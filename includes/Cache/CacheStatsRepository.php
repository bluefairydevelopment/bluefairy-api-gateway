<?php
/**
 * Hit/miss counters in five-minute buckets.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes to `bfag_cache_stats`, one row per five-minute bucket, with
 * `INSERT … ON DUPLICATE KEY UPDATE` so a counter is a single statement and
 * never a read-modify-write race. Buckets are GMT, floored to 300 s.
 */
final class CacheStatsRepository {

	/**
	 * Bucket width in seconds.
	 */
	public const BUCKET_SECONDS = 300;

	/**
	 * Object-cache group of the per-bucket counters used with a persistent
	 * object cache, and how long a counter waits for the KPI cron to flush
	 * it (three buckets: the cron runs every one).
	 */
	public const COUNTER_GROUP = 'bfag';
	public const COUNTER_TTL   = 3 * self::BUCKET_SECONDS;

	/**
	 * Object-cache key held while flush_counters() runs, and how long a
	 * crashed run may hold it. The dashboard Refresh and the KPI cron can
	 * flush at the same moment; two flushes reading the same counter would
	 * write it twice and decrement it below zero (PN10).
	 */
	public const FLUSH_LOCK_KEY = 'stats:flush_lock';
	public const FLUSH_LOCK_TTL = 30;

	/**
	 * Count a cache hit in the current bucket.
	 */
	public function record_hit(): void {
		if ( true === wp_using_ext_object_cache() ) {
			$this->increment( 'hits' );
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- counter upsert on a plugin table.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}bfag_cache_stats (bucket_start, hits, misses) VALUES (%s, 1, 0) ON DUPLICATE KEY UPDATE hits = hits + 1", $this->current_bucket() ) );
	}

	/**
	 * Count a cache miss in the current bucket.
	 */
	public function record_miss(): void {
		if ( true === wp_using_ext_object_cache() ) {
			$this->increment( 'misses' );
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- counter upsert on a plugin table.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}bfag_cache_stats (bucket_start, hits, misses) VALUES (%s, 0, 1) ON DUPLICATE KEY UPDATE misses = misses + 1", $this->current_bucket() ) );
	}

	/**
	 * Move the object-cache counters of every bucket a counter can still be
	 * alive in — from `now − COUNTER_TTL` to the current bucket — into the
	 * table (one upsert per bucket that has anything) and decrement each
	 * counter by what was flushed, so hits counted while the flush ran are
	 * not lost. Reading only the current and previous bucket lost a bucket
	 * whenever the cron ran late (PN10). One flush runs at a time: a second
	 * caller finds FLUSH_LOCK_KEY held and returns 0, leaving the counters
	 * for the holder. The KPI cron calls this before it aggregates.
	 * Returns the buckets written.
	 */
	public function flush_counters(): int {
		if ( true !== wp_using_ext_object_cache() ) {
			return 0;
		}
		if ( ! wp_cache_add( self::FLUSH_LOCK_KEY, 1, self::COUNTER_GROUP, self::FLUSH_LOCK_TTL ) ) {
			return 0;
		}
		try {
			return $this->flush_buckets();
		} finally {
			wp_cache_delete( self::FLUSH_LOCK_KEY, self::COUNTER_GROUP );
		}
	}

	/**
	 * The flush itself, under the lock.
	 */
	private function flush_buckets(): int {
		global $wpdb;

		$written = 0;
		$now     = $this->now();
		$buckets = [];
		for ( $bucket = $this->bucket_start( $now - self::COUNTER_TTL ); $bucket <= $now; $bucket += self::BUCKET_SECONDS ) {
			$buckets[] = $bucket;
		}
		foreach ( $buckets as $bucket ) {
			$counts = [];
			foreach ( [ 'hits', 'misses' ] as $kind ) {
				$value           = wp_cache_get( self::counter_key( $bucket, $kind ), self::COUNTER_GROUP );
				$counts[ $kind ] = is_numeric( $value ) ? max( 0, (int) $value ) : 0;
			}
			if ( 0 === $counts['hits'] && 0 === $counts['misses'] ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- batched counter upsert on a plugin table.
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->prefix}bfag_cache_stats (bucket_start, hits, misses) VALUES (%s, %d, %d) ON DUPLICATE KEY UPDATE hits = hits + %d, misses = misses + %d",
					gmdate( 'Y-m-d H:i:s', $bucket ),
					$counts['hits'],
					$counts['misses'],
					$counts['hits'],
					$counts['misses']
				)
			);
			if ( false === $result ) {
				continue; // Leave the counters for the next run.
			}
			++$written;
			foreach ( $counts as $kind => $n ) {
				if ( $n > 0 ) {
					wp_cache_decr( self::counter_key( $bucket, $kind ), $n, self::COUNTER_GROUP );
				}
			}
		}
		return $written;
	}

	/**
	 * The object-cache key of one bucket's counter: `stats:<bucket>:<kind>`.
	 *
	 * @param int    $bucket_start Bucket start (Unix timestamp).
	 * @param string $kind         'hits' or 'misses'.
	 */
	public static function counter_key( int $bucket_start, string $kind ): string {
		return 'stats:' . $bucket_start . ':' . $kind;
	}

	/**
	 * Add one to the current bucket's counter in the object cache.
	 *
	 * @param string $kind 'hits' or 'misses'.
	 */
	private function increment( string $kind ): void {
		$key = self::counter_key( $this->bucket_start( $this->now() ), $kind );
		wp_cache_add( $key, 0, self::COUNTER_GROUP, self::COUNTER_TTL );
		wp_cache_incr( $key, 1, self::COUNTER_GROUP );
	}

	/**
	 * Summed hits and misses for buckets starting at or after a GMT datetime.
	 *
	 * @param string $mysql_gmt_datetime `Y-m-d H:i:s` in GMT.
	 * @return array{hits:int, misses:int}
	 */
	public function totals_since( string $mysql_gmt_datetime ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregate read of a plugin table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE(SUM(hits), 0) AS hits, COALESCE(SUM(misses), 0) AS misses FROM {$wpdb->prefix}bfag_cache_stats WHERE bucket_start >= %s", $mysql_gmt_datetime ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return [
				'hits'   => 0,
				'misses' => 0,
			];
		}
		return [
			'hits'   => (int) ( $row['hits'] ?? 0 ),
			'misses' => (int) ( $row['misses'] ?? 0 ),
		];
	}

	/**
	 * Delete buckets older than the retention window. Returns rows deleted.
	 *
	 * @param int $keep_days Days of buckets to keep.
	 */
	public function prune( int $keep_days = 7 ): int {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', $this->now() - ( max( 0, $keep_days ) * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- retention delete on a plugin table (at most 288 rows a day).
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}bfag_cache_stats WHERE bucket_start < %s", $cutoff ) );
		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * GMT datetime of the bucket containing now.
	 */
	private function current_bucket(): string {
		return gmdate( 'Y-m-d H:i:s', $this->bucket_start( $this->now() ) );
	}

	/**
	 * Floor a timestamp to its bucket.
	 *
	 * @param int $timestamp Unix timestamp.
	 */
	private function bucket_start( int $timestamp ): int {
		return $timestamp - ( $timestamp % self::BUCKET_SECONDS );
	}

	/**
	 * Now, as a Unix (UTC) timestamp.
	 */
	private function now(): int {
		return time();
	}
}
