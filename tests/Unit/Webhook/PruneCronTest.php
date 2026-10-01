<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Webhook;

use BlueFairy\ApiGateway\Installer;
use BlueFairy\ApiGateway\Logger;
use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Tests\TestCase;
use BlueFairy\ApiGateway\Webhook\DeliveryRepository;
use BlueFairy\ApiGateway\Webhook\PruneCron;

final class PruneCronTest extends TestCase {

	private function cron( array $settings = [] ): PruneCron {
		update_option( 'bfag_settings', $settings );
		$s = new Settings();
		return new PruneCron( new DeliveryRepository( $s ), $s );
	}

	public function test_register_hooks_attaches_the_scheduled_cron_hook(): void {
		$this->assertArrayHasKey( PruneCron::HOOK, Installer::CRON, 'the hook Installer schedules is the one we listen on' );
		$this->assertFalse( has_action( 'bfag_cron_prune_deliveries' ) );
		$this->cron()->register_hooks();
		$this->assertNotFalse( has_action( 'bfag_cron_prune_deliveries' ) );
		$this->assertFalse( has_action( 'bfag_cron_prune_hits' ), 'not the rate-limit cron' );
	}

	public function test_run_prunes_with_the_retention_days_setting(): void {
		update_option( 'bfag_settings', [ 'retention_days' => 7, 'debug_logging' => true ] );
		$cron = $this->cron( [ 'retention_days' => 7, 'debug_logging' => true ] );
		Logger::use_settings( new Settings() );
		ini_set( 'error_log', '/dev/null' );
		$this->wpdb->queue( 'query', 40 );
		$this->wpdb->queue( 'query', 0 );

		$before = time();
		do_action( 'bfag_cron_prune_deliveries' );
		$this->assertCount( 0, $this->wpdb->queries_matching( 'DELETE' ), 'not registered yet: the hook does nothing' );

		$cron->register_hooks();
		do_action( 'bfag_cron_prune_deliveries' );
		$deletes = $this->wpdb->queries_matching( 'DELETE FROM bfw_bfag_webhook_deliveries' );
		$this->assertCount( 2, $deletes );
		$this->assertStringContainsString( gmdate( 'Y-m-d H:i', $before - 7 * DAY_IN_SECONDS ), $deletes[0] );
		$this->assertStringNotContainsString( gmdate( 'Y-m-d H:i', $before - 30 * DAY_IN_SECONDS ), $deletes[0] );
		$this->assertNotEmpty( array_filter( Logger::lines(), static fn( string $l ): bool => str_contains( $l, '"retention_days":7,"removed":40' ) ) );
	}

	public function test_run_defaults_to_thirty_days(): void {
		$this->assertSame( 30, Settings::DEFAULTS['retention_days'] );
		$this->cron()->run();
		$this->assertStringContainsString( gmdate( 'Y-m-d H:i', time() - 30 * DAY_IN_SECONDS ), $this->wpdb->queries_matching( 'DELETE' )[0] );
	}
}
