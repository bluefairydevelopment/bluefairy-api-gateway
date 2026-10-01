<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Cache;

use BlueFairy\ApiGateway\Cache\CacheStatsRepository;
use BlueFairy\ApiGateway\Tests\TestCase;

final class CacheStatsRepositoryTest extends TestCase {

	private CacheStatsRepository $stats;

	protected function setUp(): void {
		parent::setUp();
		$this->stats = new CacheStatsRepository();
	}

	/**
	 * Every five-minute bucket start that `time()` could have fallen into
	 * between two instants, as GMT datetimes. The clock is real (WPCS forbids
	 * current_time() for a UTC timestamp), so a test that straddles a bucket
	 * boundary accepts either side.
	 *
	 * @return list<string>
	 */
	private function buckets_between( int $before, int $after, int $shift = 0 ): array {
		$out = [];
		for ( $t = $before - ( $before % 300 ); $t <= $after; $t += 300 ) {
			$out[] = gmdate( 'Y-m-d H:i:s', $t - $shift );
		}
		return $out;
	}

	public function test_record_hit_upserts_the_current_bucket(): void {
		$before = time();
		$this->stats->record_hit();
		$after = time();

		$queries = $this->wpdb->calls_to( 'query' );
		$this->assertCount( 1, $queries );
		$expected = array_map(
			static fn( string $bucket ): string => "INSERT INTO bfw_bfag_cache_stats (bucket_start, hits, misses) VALUES ('{$bucket}', 1, 0) ON DUPLICATE KEY UPDATE hits = hits + 1",
			$this->buckets_between( $before, $after )
		);
		$this->assertContains( $queries[0]['sql'], $expected );
		$this->assertStringEndsWith( ':00', substr( $queries[0]['sql'], 0, strpos( $queries[0]['sql'], "', 1, 0" ) ?: 0 ), 'bucket is floored to a whole five minutes' );
	}

	public function test_record_miss_upserts_the_misses_column(): void {
		$before = time();
		$this->stats->record_miss();
		$after = time();

		$queries = $this->wpdb->calls_to( 'query' );
		$this->assertCount( 1, $queries );
		$expected = array_map(
			static fn( string $bucket ): string => "INSERT INTO bfw_bfag_cache_stats (bucket_start, hits, misses) VALUES ('{$bucket}', 0, 1) ON DUPLICATE KEY UPDATE misses = misses + 1",
			$this->buckets_between( $before, $after )
		);
		$this->assertContains( $queries[0]['sql'], $expected );
		$this->assertStringNotContainsString( 'hits = hits + 1', $queries[0]['sql'] );
	}

	public function test_bucket_is_a_multiple_of_300_seconds(): void {
		$this->stats->record_hit();
		preg_match( "/VALUES \('([^']+)'/", $this->wpdb->calls_to( 'query' )[0]['sql'], $m );
		$this->assertSame( 0, ( (int) strtotime( $m[1] . ' UTC' ) ) % 300 );
	}

	// ------------------------------------------------------------------
	// P3: counters in a persistent object cache, flushed by the KPI cron
	// ------------------------------------------------------------------

	public function test_with_a_persistent_object_cache_hits_and_misses_are_counted_in_the_cache_not_the_table(): void {
		wp_using_ext_object_cache( true );
		$this->stats->record_hit();
		$this->stats->record_hit();
		$this->stats->record_miss();

		$this->assertSame( [], $this->wpdb->calls, 'no query' );
		$bucket = time() - ( time() % 300 );
		$this->assertSame( 2, wp_cache_get( CacheStatsRepository::counter_key( $bucket, 'hits' ), 'bfag' ) );
		$this->assertSame( 1, wp_cache_get( CacheStatsRepository::counter_key( $bucket, 'misses' ), 'bfag' ) );
		$this->assertSame( 'stats:' . $bucket . ':hits', CacheStatsRepository::counter_key( $bucket, 'hits' ) );
		$this->assertSame( bfag_test_now() + 900, $GLOBALS['bfag_test_cache_expires']['bfag'][ 'stats:' . $bucket . ':hits' ], 'a counter waits three buckets for the cron' );
	}

	public function test_flush_counters_upserts_the_current_and_previous_bucket_and_decrements_what_it_wrote(): void {
		wp_using_ext_object_cache( true );
		$bucket   = time() - ( time() % 300 );
		$previous = $bucket - 300;
		$older    = $bucket - 600;
		wp_cache_set( CacheStatsRepository::counter_key( $previous, 'hits' ), 7, 'bfag' );
		wp_cache_set( CacheStatsRepository::counter_key( $previous, 'misses' ), 2, 'bfag' );
		wp_cache_set( CacheStatsRepository::counter_key( $bucket, 'hits' ), 3, 'bfag' );
		wp_cache_set( CacheStatsRepository::counter_key( $older, 'hits' ), 50, 'bfag' );
		$this->wpdb->always( 'query', 1 );

		$this->assertSame( 2, $this->stats->flush_counters() );

		$queries = array_column( $this->wpdb->calls_to( 'query' ), 'sql' );
		$this->assertSame(
			[
				"INSERT INTO bfw_bfag_cache_stats (bucket_start, hits, misses) VALUES ('" . gmdate( 'Y-m-d H:i:s', $previous ) . "', 7, 2) ON DUPLICATE KEY UPDATE hits = hits + 7, misses = misses + 2",
				"INSERT INTO bfw_bfag_cache_stats (bucket_start, hits, misses) VALUES ('" . gmdate( 'Y-m-d H:i:s', $bucket ) . "', 3, 0) ON DUPLICATE KEY UPDATE hits = hits + 3, misses = misses + 0",
			],
			$queries
		);
		$this->assertSame( 0, wp_cache_get( CacheStatsRepository::counter_key( $previous, 'hits' ), 'bfag' ), 'decremented by what was written' );
		$this->assertSame( 0, wp_cache_get( CacheStatsRepository::counter_key( $bucket, 'hits' ), 'bfag' ) );
		$this->assertSame( 50, wp_cache_get( CacheStatsRepository::counter_key( $older, 'hits' ), 'bfag' ), 'only the current and previous bucket are flushed' );

		$this->wpdb->calls = [];
		$this->assertSame( 0, $this->stats->flush_counters(), 'nothing left to write' );
		$this->assertSame( [], $this->wpdb->calls );
	}

	public function test_flush_counters_keeps_the_counter_when_the_upsert_fails_and_is_a_no_op_without_a_persistent_cache(): void {
		wp_using_ext_object_cache( true );
		$bucket = time() - ( time() % 300 );
		wp_cache_set( CacheStatsRepository::counter_key( $bucket, 'misses' ), 4, 'bfag' );
		$this->wpdb->queue( 'query', false );
		$this->assertSame( 0, $this->stats->flush_counters() );
		$this->assertSame( 4, wp_cache_get( CacheStatsRepository::counter_key( $bucket, 'misses' ), 'bfag' ), 'kept for the next run' );

		$GLOBALS['bfag_test_ext_cache'] = null;
		$this->wpdb->calls              = [];
		$this->assertSame( 0, $this->stats->flush_counters() );
		$this->assertSame( [], $this->wpdb->calls, 'without a persistent cache there are no counters to flush' );
	}

	public function test_totals_since_sums_and_casts(): void {
		$this->wpdb->queue(
			'get_row',
			[
				'hits'   => '5',
				'misses' => '3',
			]
		);
		$this->assertSame(
			[
				'hits'   => 5,
				'misses' => 3,
			],
			$this->stats->totals_since( '2026-09-30 10:00:00' )
		);
		$this->assertSame(
			"SELECT COALESCE(SUM(hits), 0) AS hits, COALESCE(SUM(misses), 0) AS misses FROM bfw_bfag_cache_stats WHERE bucket_start >= '2026-09-30 10:00:00'",
			$this->wpdb->calls_to( 'get_row' )[0]['sql']
		);

		$this->assertSame(
			[
				'hits'   => 0,
				'misses' => 0,
			],
			$this->stats->totals_since( '2026-09-30 10:00:00' ),
			'no row means zero'
		);
	}

	/**
	 * F16: hit_rate() was dead (KpiCron computes its own rate) and is gone.
	 */
	public function test_hit_rate_is_no_longer_part_of_the_api(): void {
		$this->assertFalse( method_exists( $this->stats, 'hit_rate' ) );
		$this->assertTrue( method_exists( $this->stats, 'totals_since' ), 'control: the method KpiCron uses is still there' );
	}

	public function test_prune_deletes_buckets_older_than_the_retention_and_returns_the_count(): void {
		$this->wpdb->queue( 'query', 4 );
		$before = time();
		$this->assertSame( 4, $this->stats->prune( 7 ) );
		$after = time();

		$sql = $this->wpdb->calls_to( 'query' )[0]['sql'];
		$this->assertStringStartsWith( "DELETE FROM bfw_bfag_cache_stats WHERE bucket_start < '", $sql );
		preg_match( "/< '([^']+)'/", $sql, $m );
		$cutoff = (int) strtotime( $m[1] . ' UTC' );
		$this->assertGreaterThanOrEqual( $before - 7 * DAY_IN_SECONDS, $cutoff );
		$this->assertLessThanOrEqual( $after - 7 * DAY_IN_SECONDS, $cutoff );

		$this->wpdb->queue( 'query', false );
		$this->assertSame( 0, $this->stats->prune( 7 ), 'a failed delete reports zero' );
	}

	public function test_prune_keep_days_moves_the_cutoff(): void {
		$this->stats->prune( 1 );
		$this->stats->prune( 30 );
		$queries = $this->wpdb->calls_to( 'query' );
		preg_match( "/< '([^']+)'/", $queries[0]['sql'], $one );
		preg_match( "/< '([^']+)'/", $queries[1]['sql'], $thirty );
		$this->assertEqualsWithDelta( 29 * DAY_IN_SECONDS, strtotime( $one[1] . ' UTC' ) - strtotime( $thirty[1] . ' UTC' ), 2 );
	}
}
