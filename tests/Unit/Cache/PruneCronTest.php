<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Cache;

use BlueFairy\ApiGateway\Cache\CacheStore;
use BlueFairy\ApiGateway\Cache\PruneCron;
use BlueFairy\ApiGateway\Installer;
use BlueFairy\ApiGateway\Logger;
use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Tests\TestCase;

final class PruneCronTest extends TestCase {

	public function test_register_hooks_attaches_run_to_the_five_minute_hook_installer_schedules(): void {
		$this->assertSame( 'bfag_five_minutes', Installer::CRON[ PruneCron::HOOK ] ?? null, 'scheduled every five minutes' );
		$this->assertFalse( has_action( PruneCron::HOOK ) );
		( new PruneCron( new CacheStore() ) )->register_hooks();
		$this->assertNotFalse( has_action( PruneCron::HOOK ) );
		$this->assertFalse( has_action( 'bfag_cron_prune_hits' ), 'not the rate-limit cron' );
	}

	public function test_plugin_boot_wires_the_cleanup_cron(): void {
		$this->wpdb->always( 'get_var', '0' );
		$this->assertFalse( has_action( PruneCron::HOOK ) );
		\BlueFairy\ApiGateway\Plugin::boot();
		$this->assertNotFalse( has_action( PruneCron::HOOK ), 'Plugin::boot() registers the handler' );
	}

	public function test_run_prunes_expired_transients_and_logs_the_count(): void {
		update_option( 'bfag_settings', [ 'debug_logging' => true ] );
		Logger::use_settings( new Settings() );
		ini_set( 'error_log', '/dev/null' );
		$this->wpdb->queue( 'get_col', [ '_transient_timeout_bfag_resp_a', '_transient_timeout_bfag_resp_b' ] );
		( new PruneCron( new CacheStore() ) )->register_hooks();

		do_action( PruneCron::HOOK );

		$this->assertCount( 1, $this->wpdb->queries_matching( 'DELETE FROM bfw_options' ) );
		$this->assertSame( [ '[bfag] expired response transients pruned {"deleted":2}' ], Logger::lines() );
	}
}
