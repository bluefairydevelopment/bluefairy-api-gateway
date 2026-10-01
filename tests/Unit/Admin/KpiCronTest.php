<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Admin;

use BlueFairy\ApiGateway\Admin\KpiCron;
use BlueFairy\ApiGateway\Cache\CacheStatsRepository;
use BlueFairy\ApiGateway\RateLimit\HitCounter;
use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Webhook\DeliveryRepository;
use BlueFairy\ApiGateway\Tests\TestCase;

final class KpiCronTest extends TestCase {

	private function cron(): KpiCron {
		return new KpiCron( new CacheStatsRepository(), new DeliveryRepository( new Settings() ), new HitCounter() );
	}

	/**
	 * Script the three aggregate queries compute() issues.
	 *
	 * @param array<string, int> $deliveries status => count.
	 */
	private function prime( int $hits, int $misses, array $deliveries, int $rows ): void {
		$this->wpdb->queue(
			'get_row',
			[
				'hits'   => $hits,
				'misses' => $misses,
			]
		);
		$stats = [];
		foreach ( $deliveries as $status => $n ) {
			$stats[] = [
				'status' => $status,
				'n'      => $n,
			];
		}
		$this->wpdb->queue( 'get_results', $stats );
		$this->wpdb->queue( 'get_var', $rows );
	}

	public function test_compute_has_the_contract_keys_and_computes_the_rates(): void {
		$this->prime(
			75,
			25,
			[
				'success' => 8,
				'failed'  => 2,
				'pending' => 1,
			],
			4321
		);

		$kpis = $this->cron()->compute();

		$this->assertSame(
			[ 'cache_hit_rate', 'cache_hits', 'cache_misses', 'webhook_success_rate', 'webhook_failed', 'webhook_total', 'blocked_today', 'hits_table_rows', 'computed_at' ],
			array_keys( $kpis )
		);
		$this->assertSame( 0.75, $kpis['cache_hit_rate'] );
		$this->assertSame( 75, $kpis['cache_hits'] );
		$this->assertSame( 25, $kpis['cache_misses'] );
		$this->assertSame( 0.8, $kpis['webhook_success_rate'] );
		$this->assertSame( 2, $kpis['webhook_failed'] );
		$this->assertSame( 11, $kpis['webhook_total'], 'pending counts towards the total but not the rate' );
		$this->assertSame( 0, $kpis['blocked_today'] );
		$this->assertSame( 4321, $kpis['hits_table_rows'] );
		$this->assertIsInt( $kpis['computed_at'] );
		$this->assertSame( 1, preg_match( "/bucket_start >= '([^']+)'/", $this->wpdb->queries_matching( 'bfag_cache_stats' )[0] ?? '', $m ) );
		$this->assertEqualsWithDelta( time() - DAY_IN_SECONDS, strtotime( $m[1] . ' UTC' ), 5, 'the window is the last 24 hours' );
	}

	public function test_rates_are_null_not_zero_when_there_is_nothing_to_divide(): void {
		$this->prime( 0, 0, [], 0 );
		$kpis = $this->cron()->compute();
		$this->assertNull( $kpis['cache_hit_rate'] );
		$this->assertNull( $kpis['webhook_success_rate'] );
		$this->assertSame( 0, $kpis['webhook_total'] );

		$this->prime( 0, 10, [ 'failed' => 3 ], 0 );
		$kpis = $this->cron()->compute();
		$this->assertSame( 0.0, $kpis['cache_hit_rate'], 'misses without hits is a real zero' );
		$this->assertSame( 0.0, $kpis['webhook_success_rate'] );
	}

	public function test_blocked_today_and_the_whole_array_are_filterable(): void {
		add_filter( 'bfag_kpi_blocked_today', static fn( int $n ): int => $n + 7 );
		add_filter(
			'bfag_dashboard_kpis',
			static function ( array $kpis ): array {
				$kpis['api_keys'] = 3;
				return $kpis;
			}
		);
		$this->prime( 1, 1, [], 0 );
		$kpis = $this->cron()->compute();
		$this->assertSame( 7, $kpis['blocked_today'] );
		$this->assertSame( 3, $kpis['api_keys'] );
	}

	public function test_run_stores_the_transient_for_360_seconds_and_current_reads_it(): void {
		bfag_test_set_time( 1_700_000_000 );
		$cron = $this->cron();
		$this->assertNull( $cron->current(), 'cold' );

		$this->prime( 3, 1, [ 'success' => 1 ], 9 );
		$stored = $cron->run();
		$this->assertSame( 0.75, $stored['cache_hit_rate'] );
		$this->assertSame( $stored, $cron->current() );
		$this->assertSame( 1_700_000_000 + 360, $GLOBALS['bfag_test_transients']['bfag_kpis']['expires'] );

		bfag_test_set_time( 1_700_000_000 + 361 );
		$this->assertNull( $cron->current(), 'expired' );
	}

	public function test_run_prunes_cache_stats_older_than_seven_days_but_compute_does_not(): void {
		$cron   = $this->cron();
		$needle = 'DELETE FROM ' . $this->wpdb->prefix . 'bfag_cache_stats';

		$this->prime( 1, 1, [], 0 );
		$cron->compute();
		$this->assertSame( [], $this->wpdb->queries_matching( $needle ), 'compute() is read-only' );

		$this->prime( 1, 1, [], 0 );
		$cron->run();
		$deletes = $this->wpdb->queries_matching( $needle );
		$this->assertCount( 1, $deletes, 'run() prunes once' );
		$this->assertSame( 1, preg_match( "/bucket_start < '([^']+)'/", $deletes[0], $m ) );
		$this->assertEqualsWithDelta( time() - 7 * DAY_IN_SECONDS, strtotime( $m[1] . ' UTC' ), 5, 'buckets older than seven days go' );
	}

	public function test_a_non_array_transient_reads_as_cold(): void {
		set_transient( 'bfag_kpis', 'garbage', 360 );
		$this->assertNull( $this->cron()->current() );
	}

	public function test_register_hooks_binds_the_cron_hook_to_run(): void {
		$cron = $this->cron();
		$this->assertFalse( has_action( KpiCron::HOOK ) );
		$cron->register_hooks();
		$this->assertTrue( (bool) has_action( KpiCron::HOOK ) );
		$this->prime( 1, 0, [], 0 );
		do_action( KpiCron::HOOK );
		$this->assertSame( 1.0, $cron->current()['cache_hit_rate'] ?? null );
	}
}
