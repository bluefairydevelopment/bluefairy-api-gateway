<?php
/**
 * The rolling hit log behind every rate-limit decision.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\RateLimit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BlueFairy\ApiGateway\Logger;

/**
 * Sliding-window counting over `bfag_rate_limit_hits`.
 *
 * The consume() method INSERTs the hit first and COUNTs second (review
 * finding SEC6). Read-then-decide lets a hundred concurrent requests all read "59", all
 * pass, and all insert. Insert-then-count means every request's own row is
 * in the count it reads, so at most a handful of requests that were already
 * in flight when the limit was crossed get through — the acceptable
 * overshoot of a sliding window, not an unbounded one.
 *
 * `route_hash` is the first 16 hex characters of SHA-256(route), computed
 * here rather than as a generated column so dbDelta can manage the table
 * (review finding DB2). Timestamps are written and compared in GMT from
 * PHP's clock, never MySQL's, so a server whose session time zone is not
 * UTC still counts the right window.
 */
final class HitCounter {

	public const PRUNE_BATCH          = 10000;
	public const PRUNE_MAX_ITERATIONS = 50;
	public const ROTATE_THRESHOLD     = 10000000;

	/**
	 * Record a hit and report whether the subject is now over the limit.
	 *
	 * @param string $subject Who is being counted (an IP in the free plugin).
	 * @param string $route   REST route.
	 * @param int    $window  Window length in seconds.
	 * @param int    $max     Hits allowed within the window.
	 * @return bool True when the subject has exceeded $max in the window.
	 */
	public function consume( string $subject, string $route, int $window, int $max ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table; every hit must be recorded.
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'bfag_rate_limit_hits',
			[
				'subject'    => substr( $subject, 0, 200 ),
				'route'      => substr( $route, 0, 500 ),
				'route_hash' => self::route_hash( $route ),
				'hit_at'     => gmdate( 'Y-m-d H:i:s', time() ),
			],
			[ '%s', '%s', '%s', '%s' ]
		);
		if ( false === $inserted ) {
			// Fail open: a database fault must not turn into a site-wide 429.
			Logger::log( 'rate-limit hit insert failed', [ 'error' => $wpdb->last_error ] );
			return false;
		}
		return $this->count( $subject, $route, $window ) > $max;
	}

	/**
	 * Hits by $subject on $route within the last $window seconds.
	 *
	 * @param string $subject Who is being counted.
	 * @param string $route   REST route.
	 * @param int    $window  Window length in seconds.
	 */
	public function count( string $subject, string $route, int $window ): int {
		global $wpdb;

		$since = gmdate( 'Y-m-d H:i:s', time() - $window );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table, prefix interpolation; a count that must be fresh.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bfag_rate_limit_hits WHERE subject = %s AND route_hash = %s AND hit_at > %s", substr( $subject, 0, 200 ), self::route_hash( $route ), $since ) );
	}

	/**
	 * Delete hits older than $max_window seconds in batches of 10,000 until
	 * a batch deletes nothing, or 50 batches have run (review finding DB1).
	 *
	 * @param int $max_window Seconds of history to keep.
	 * @return int Rows deleted.
	 */
	public function prune( int $max_window ): int {
		global $wpdb;

		$before  = gmdate( 'Y-m-d H:i:s', time() - max( 0, $max_window ) );
		$deleted = 0;
		for ( $i = 0; $i < self::PRUNE_MAX_ITERATIONS; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table, prefix interpolation; batched delete.
			$result = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}bfag_rate_limit_hits WHERE hit_at < %s LIMIT %d", $before, self::PRUNE_BATCH ) );
			$rows   = is_int( $result ) ? $result : 0;
			if ( $rows <= 0 ) {
				break;
			}
			$deleted += $rows;
		}
		return $deleted;
	}

	/**
	 * Rows in the hits table.
	 */
	public function total_rows(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table, prefix interpolation, no values to prepare.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bfag_rate_limit_hits" );
	}

	/**
	 * Safety cap: when the pruner has fallen so far behind that the table
	 * holds more than $threshold rows, empty it rather than let every
	 * request's COUNT degrade further. Logged, because it forgives every
	 * subject's current window.
	 *
	 * @param int $threshold Row count above which the table is truncated.
	 * @return bool True when the table was truncated.
	 */
	public function rotate_if_huge( int $threshold = self::ROTATE_THRESHOLD ): bool {
		$rows = $this->total_rows();
		if ( $rows <= $threshold ) {
			return false;
		}
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- plugin table, prefix interpolation; the DB1 hard cap.
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}bfag_rate_limit_hits" );
		Logger::log(
			'rate-limit hits table rotated',
			[
				'rows'      => $rows,
				'threshold' => $threshold,
			]
		);
		return true;
	}

	/**
	 * The indexed form of a route: first 16 hex characters of its SHA-256.
	 *
	 * @param string $route REST route.
	 */
	public static function route_hash( string $route ): string {
		return substr( hash( 'sha256', $route ), 0, 16 );
	}
}
