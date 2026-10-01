<?php
/**
 * Hit/miss counters in five-minute buckets.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Cache;

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
	 * Count a cache hit in the current bucket.
	 */
	public function record_hit(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- counter upsert on a plugin table.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}bfag_cache_stats (bucket_start, hits, misses) VALUES (%s, 1, 0) ON DUPLICATE KEY UPDATE hits = hits + 1", $this->current_bucket() ) );
	}

	/**
	 * Count a cache miss in the current bucket.
	 */
	public function record_miss(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- counter upsert on a plugin table.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}bfag_cache_stats (bucket_start, hits, misses) VALUES (%s, 0, 1) ON DUPLICATE KEY UPDATE misses = misses + 1", $this->current_bucket() ) );
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
	 * Hits as a fraction (0.0–1.0) of all requests over the most recent
	 * buckets, including the current one; null when there were none.
	 *
	 * @param int $buckets How many five-minute buckets to include.
	 */
	public function hit_rate( int $buckets = 12 ): ?float {
		$buckets = max( 1, $buckets );
		$since   = $this->bucket_start( $this->now() ) - ( ( $buckets - 1 ) * self::BUCKET_SECONDS );
		$totals  = $this->totals_since( gmdate( 'Y-m-d H:i:s', $since ) );
		$total   = $totals['hits'] + $totals['misses'];
		if ( 0 === $total ) {
			return null;
		}
		return $totals['hits'] / $total;
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
