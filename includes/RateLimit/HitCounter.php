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
	 * Object-cache group of the per-window counters used with a persistent
	 * object cache.
	 */
	public const COUNTER_GROUP = 'bfag';

	/**
	 * Characters of the real route kept in the `route` column (the admin
	 * reads it; the index is on `route_hash`). 191 is the longest a utf8mb4
	 * column can be indexed in full, should an index ever be wanted.
	 */
	public const ROUTE_COLUMN_LENGTH = 191;

	/**
	 * Record a hit and report whether the subject is now over the limit.
	 *
	 * `$route` is the bucket key: whatever is counted together. The
	 * middleware passes the matched rule's key (`rule:<id>`, see
	 * RateLimitMiddleware::bucket()) so one rule is one budget per subject
	 * however the path is spelled; `route_hash` is derived from it. The
	 * `route` column shows `$display_route` when given — the real route, for
	 * the admin reading the log — and the bucket key otherwise.
	 *
	 * @param string      $subject       Who is being counted (an IP in the free plugin).
	 * @param string      $route         Bucket key (hashed into route_hash).
	 * @param int         $window        Window length in seconds.
	 * @param int         $max           Hits allowed within the window.
	 * @param string|null $display_route What to write in the `route` column; null writes the bucket key.
	 * @return bool True when the subject has exceeded $max in the window.
	 */
	public function consume( string $subject, string $route, int $window, int $max, ?string $display_route = null ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table; every hit must be recorded.
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'bfag_rate_limit_hits',
			[
				'subject'    => substr( $subject, 0, 200 ),
				'route'      => substr( $display_route ?? $route, 0, self::ROUTE_COLUMN_LENGTH ),
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
		if ( true === wp_using_ext_object_cache() ) {
			// The row is still written — the admin's hit log and the Pro
			// add-on's cross-route DDoS count read the table — but the count
			// is an atomic increment in the object cache, not a COUNT(*).
			return $this->count_in_window( $subject, $route, $window ) > $max;
		}
		return $this->count( $subject, $route, $window ) > $max;
	}

	/**
	 * Count this hit in the object cache and return the total so far in the
	 * current fixed window (`floor( time / window )`). Fixed windows, not
	 * sliding: a subject may get up to 2 × max across one boundary, which
	 * is the usual trade for a counter that costs no query. The counter
	 * lives for two windows so the previous one can still be read. A failed
	 * increment (a key evicted between add and incr) counts as zero: fail
	 * open, as a failed INSERT does.
	 *
	 * @param string $subject Who is being counted.
	 * @param string $route   Bucket key, as passed to consume().
	 * @param int    $window  Window length in seconds.
	 */
	public function count_in_window( string $subject, string $route, int $window ): int {
		$key = self::window_key( $subject, $route, $window );
		wp_cache_add( $key, 0, self::COUNTER_GROUP, max( 1, $window ) * 2 );
		$count = wp_cache_incr( $key, 1, self::COUNTER_GROUP );
		return false === $count ? 0 : (int) $count;
	}

	/**
	 * The object-cache key of a subject's counter in the current window:
	 * `bfag:rl:<md5(subject|route)>:<floor(time/window)>`.
	 *
	 * @param string $subject Who is being counted.
	 * @param string $route   Bucket key.
	 * @param int    $window  Window length in seconds.
	 */
	public static function window_key( string $subject, string $route, int $window ): string {
		return 'bfag:rl:' . md5( $subject . '|' . $route ) . ':' . intdiv( time(), max( 1, $window ) );
	}

	/**
	 * Hits by $subject in the $route bucket within the last $window seconds.
	 *
	 * @param string $subject Who is being counted.
	 * @param string $route   Bucket key, as passed to consume().
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
	 * The storage engine's row estimate (`SHOW TABLE STATUS`), free where
	 * COUNT(*) walks the table. Off by a margin on InnoDB, which is fine for
	 * a ten-million-row cap; the dashboard figure is the exact count.
	 */
	public function approximate_rows(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table; the engine's estimate, read every five minutes.
		$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'bfag_rate_limit_hits' ) ), ARRAY_A );
		return is_array( $status ) && isset( $status['Rows'] ) && is_numeric( $status['Rows'] ) ? (int) $status['Rows'] : 0;
	}

	/**
	 * Safety cap: when the pruner has fallen so far behind that the table
	 * holds more than $threshold rows, empty it rather than let every
	 * request's COUNT degrade further. Logged, because it forgives every
	 * subject's current window. Reads the engine's estimate, not COUNT(*):
	 * this runs every five minutes on a table the KPI cron already counts.
	 *
	 * @param int $threshold Row count above which the table is truncated.
	 * @return bool True when the table was truncated.
	 */
	public function rotate_if_huge( int $threshold = self::ROTATE_THRESHOLD ): bool {
		$rows = $this->approximate_rows();
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
	 * The indexed form of a bucket key: first 16 hex characters of its SHA-256.
	 *
	 * @param string $route Bucket key (`rule:<id>` from the middleware).
	 */
	public static function route_hash( string $route ): string {
		return substr( hash( 'sha256', $route ), 0, 16 );
	}
}
