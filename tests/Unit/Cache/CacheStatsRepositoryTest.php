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

	public function test_hit_rate_is_hits_over_total_or_null_when_empty(): void {
		$this->wpdb->queue(
			'get_row',
			[
				'hits'   => '5',
				'misses' => '3',
			]
		);
		$this->assertSame( 0.625, $this->stats->hit_rate() );

		$this->wpdb->queue(
			'get_row',
			[
				'hits'   => '0',
				'misses' => '0',
			]
		);
		$this->assertNull( $this->stats->hit_rate() );

		$this->wpdb->queue(
			'get_row',
			[
				'hits'   => '0',
				'misses' => '4',
			]
		);
		$this->assertSame( 0.0, $this->stats->hit_rate(), 'all misses is a rate of zero, not null' );
	}

	public function test_hit_rate_window_covers_the_requested_buckets_including_the_current_one(): void {
		$before = time();
		$this->stats->hit_rate( 12 );
		$this->stats->hit_rate( 1 );
		$after = time();

		$rows = $this->wpdb->calls_to( 'get_row' );
		preg_match( "/>= '([^']+)'/", $rows[0]['sql'], $twelve );
		preg_match( "/>= '([^']+)'/", $rows[1]['sql'], $one );
		$this->assertContains( $twelve[1], $this->buckets_between( $before, $after, 11 * 300 ), '12 buckets = current + 11 earlier' );
		$this->assertContains( $one[1], $this->buckets_between( $before, $after ), '1 bucket = the current one' );
		$this->assertNotSame( $twelve[1], $one[1] );
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
