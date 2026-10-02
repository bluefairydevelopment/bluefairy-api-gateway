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
	 * An upper bound on the rows in the hits table, from one indexed read:
	 * `MAX(id) - MIN(id) + 1` (0 when empty). The engine's `SHOW TABLE
	 * STATUS` estimate was read before, but MySQL 8 caches it for up to a
	 * day (`information_schema_stats_expiry`), so a table that had just
	 * filled could read as small (PN9). The bound over-counts only by rows
	 * deleted from the middle of the id range: the prune removes the OLDEST
	 * hits and ids rise with `hit_at`, so in practice it is the live count;
	 * it never under-counts, so the cap still fires. The dashboard figure
	 * is the KPI cron's exact COUNT(*).
	 */
	public function approximate_rows(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table, prefix interpolation; two index ends, read every five minutes.
		$bound = $wpdb->get_var( "SELECT COALESCE(MAX(id) - MIN(id) + 1, 0) FROM {$wpdb->prefix}bfag_rate_limit_hits" );
		return is_numeric( $bound ) ? max( 0, (int) $bound ) : 0;
	}

	/**
	 * Safety cap: when the pruner has fallen so far behind that the table
	 * holds more than $threshold rows, delete the OLDEST rows so the newest
	 * $threshold remain, rather than let the table grow without bound.
	 * Reads the id-range bound, not COUNT(*): this runs every five minutes on
	 * a table the KPI cron already counts.
	 *
	 * The delete is by primary-key range (`id < MAX(id) - $threshold + 1`)
	 * in slices of PRUNE_BATCH, at most PRUNE_MAX_ITERATIONS a run — a run
	 * that cannot catch up finishes on the next. It never TRUNCATEs (P17 /
	 * WPMAP-108): TRUNCATE takes an exclusive metadata lock that stalls every
	 * concurrent INSERT, and it forgave every subject's window at once. A
	 * range delete of the oldest ids locks rows far below the end of the
	 * index where new hits are appended, and forgives only the oldest hits.
	 * Logged with the number of rows deleted.
	 *
	 * @param int $threshold Row count above which the oldest rows are deleted.
	 * @return bool True when rows were deleted.
	 */
	public function rotate_if_huge( int $threshold = self::ROTATE_THRESHOLD ): bool {
		$rows = $this->approximate_rows();
		if ( $rows <= $threshold ) {
			return false;
		}
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table, prefix interpolation; one index end.
		$max = $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}bfag_rate_limit_hits" );
		if ( ! is_numeric( $max ) ) {
			return false;
		}
		$cutoff  = (int) $max - max( 0, $threshold ) + 1; // Ids below this go; the newest $threshold stay.
		$deleted = 0;
		for ( $i = 0; $i < self::PRUNE_MAX_ITERATIONS; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table, prefix interpolation; batched delete of the oldest ids.
			$result   = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}bfag_rate_limit_hits WHERE id < %d LIMIT %d", $cutoff, self::PRUNE_BATCH ) );
			$slice    = is_int( $result ) ? $result : 0;
			$deleted += max( 0, $slice );
			if ( $slice < self::PRUNE_BATCH ) {
				break;
			}
		}
		Logger::log(
			'rate-limit hits table rotated',
			[
				'rows'      => $rows,
				'threshold' => $threshold,
				'deleted'   => $deleted,
			]
		);
		return $deleted > 0;
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
