<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\RateLimit;

use BlueFairy\ApiGateway\Installer;
use BlueFairy\ApiGateway\Logger;
use BlueFairy\ApiGateway\RateLimit\HitCounter;
use BlueFairy\ApiGateway\RateLimit\PruneCron;
use BlueFairy\ApiGateway\RateLimit\RateLimitRuleRepository;
use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Tests\TestCase;

final class PruneCronTest extends TestCase {

	private function cron(): PruneCron {
		return new PruneCron( new HitCounter(), new RateLimitRuleRepository() );
	}

	/**
	 * Seed enabled rules with the given windows through the object cache.
	 *
	 * @param list<int> $windows window_seconds per rule.
	 */
	private function rules_with_windows( array $windows ): void {
		$rules = [];
		foreach ( $windows as $i => $window ) {
			$rules[] = [
				'id'              => $i + 1,
				'label'           => '',
				'subject_type'    => 'ip',
				'route_glob'      => '**',
				'max_requests'    => 60,
				'window_seconds'  => $window,
				'penalty_seconds' => 0,
				'enabled'         => 1,
				'created_at'      => '2026-09-30 00:00:00',
			];
		}
		wp_cache_set( 'rl_rules_enabled', $rules, 'bfag' );
	}

	public function test_register_hooks_binds_run_to_the_cron_hook_installer_schedules(): void {
		$cron = $this->cron();
		$this->assertArrayHasKey( 'bfag_cron_prune_hits', Installer::CRON );
		$this->assertFalse( has_action( 'bfag_cron_prune_hits' ) );
		$cron->register_hooks();
		$this->assertSame( 10, has_action( 'bfag_cron_prune_hits', [ $cron, 'run' ] ) );

		$this->wpdb->queue( 'query', 3 );
		$this->wpdb->queue( 'query', 0 );
		$this->wpdb->queue( 'get_var', '0' );
		$this->fire( 'bfag_cron_prune_hits' );
		$this->assertCount( 2, $this->wpdb->queries_matching( 'DELETE FROM bfw_bfag_rate_limit_hits' ), 'firing the hook runs the prune' );
	}

	public function test_run_prunes_with_the_largest_enabled_window_then_checks_the_cap(): void {
		$this->rules_with_windows( [ 60, 3600, 120 ] );
		$this->wpdb->queue( 'query', 10 );
		$this->wpdb->queue( 'query', 0 );
		$this->wpdb->queue( 'get_var', '42' );
		$t0 = time();
		$this->cron()->run();

		$deletes = $this->wpdb->queries_matching( 'DELETE FROM bfw_bfag_rate_limit_hits' );
		$this->assertCount( 2, $deletes );
		$this->assertSame( 1, preg_match( "/hit_at < '([^']+)' LIMIT 10000$/", $deletes[0], $m ) );
		$before = (int) strtotime( $m[1] . ' UTC' );
		$this->assertGreaterThanOrEqual( $t0 - 3600, $before, 'rows inside the longest window must survive' );
		$this->assertLessThanOrEqual( time() - 3600, $before );

		$this->assertSame( [], $this->wpdb->queries_matching( 'COUNT(*)' ), 'P10: the cap check never COUNT(*)s — the KPI cron is the one exact count' );
		$bound_calls = $this->wpdb->calls_to( 'get_var' );
		$this->assertCount( 1, $bound_calls );
		$this->assertSame( 'SELECT COALESCE(MAX(id) - MIN(id) + 1, 0) FROM bfw_bfag_rate_limit_hits', $bound_calls[0]['sql'], 'PN9: the id-range bound, not the engine estimate' );
		$this->assertCount( 0, $this->wpdb->queries_matching( 'TRUNCATE' ), '42 rows is not huge' );

		$order = array_column( $this->wpdb->calls, 'method' );
		$this->assertSame( [ 'query', 'query', 'get_var' ], $order, 'prune first, then the cap check' );
	}

	public function test_run_uses_the_default_window_without_rules(): void {
		wp_cache_set( 'rl_rules_enabled', [], 'bfag' );
		$this->wpdb->queue( 'query', 0 );
		$t0 = time();
		$this->cron()->run();
		$sql = $this->wpdb->queries_matching( 'DELETE FROM' )[0];
		$this->assertSame( 1, preg_match( "/hit_at < '([^']+)'/", $sql, $m ) );
		$before = (int) strtotime( $m[1] . ' UTC' );
		$this->assertGreaterThanOrEqual( $t0 - 60, $before );
		$this->assertLessThanOrEqual( time() - 60, $before );
	}

	public function test_run_rotates_the_table_when_it_is_over_the_cap(): void {
		$this->rules_with_windows( [ 60 ] );
		$this->wpdb->queue( 'query', 0 );
		$this->wpdb->queue( 'get_var', (string) ( HitCounter::ROTATE_THRESHOLD + 1 ) );
		$this->cron()->run();
		$this->assertSame( [ 'TRUNCATE TABLE bfw_bfag_rate_limit_hits' ], $this->wpdb->queries_matching( 'TRUNCATE' ) );
	}

	public function test_run_logs_the_outcome_when_debug_logging_is_on(): void {
		$this->rules_with_windows( [ 300 ] );
		update_option( 'bfag_settings', [ 'debug_logging' => true ] );
		Logger::use_settings( new Settings() );
		ini_set( 'error_log', '/dev/null' );
		$this->wpdb->queue( 'query', 7 );
		$this->wpdb->queue( 'query', 0 );
		$this->wpdb->queue( 'get_var', '7' );
		$this->cron()->run();

		$lines = Logger::lines();
		$this->assertCount( 1, $lines );
		$this->assertStringContainsString( 'rate-limit hits pruned {"window":300,"deleted":7,"rotated":false}', $lines[0] );

		Logger::use_settings( null );
		Logger::clear();
		$this->wpdb->queue( 'query', 0 );
		$this->cron()->run();
		$this->assertSame( [], Logger::lines(), 'silent without debug logging' );
	}
}
