<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit;

use BlueFairy\ApiGateway\Capabilities;
use BlueFairy\ApiGateway\Installer;
use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Tests\TestCase;

final class InstallerTest extends TestCase {

	public function test_schema_defines_exactly_the_six_owned_tables_as_innodb(): void {
		$schema = Installer::schema( 'bfw_', 'DEFAULT CHARSET utf8mb4' );
		$this->assertSame( Installer::TABLES, array_keys( $schema ) );
		foreach ( $schema as $table => $sql ) {
			$this->assertStringStartsWith( "CREATE TABLE bfw_{$table} (", $sql );
			$this->assertStringContainsString( 'ENGINE=InnoDB DEFAULT CHARSET utf8mb4', $sql );
			$this->assertStringContainsString( 'PRIMARY KEY  (', $sql, 'dbDelta needs two spaces after PRIMARY KEY' );
			$this->assertStringNotContainsString( 'CONSTRAINT', $sql, 'dbDelta cannot parse FK constraints' );
			$this->assertStringNotContainsString( 'GENERATED', $sql, 'dbDelta cannot manage generated columns' );
			$this->assertStringNotContainsString( ' INDEX ', $sql, 'dbDelta wants KEY, not INDEX' );
		}
		$this->assertStringContainsString( 'route_hash CHAR(16) NOT NULL', $schema['bfag_rate_limit_hits'] );
		$this->assertStringContainsString( 'payload MEDIUMTEXT NOT NULL', $schema['bfag_webhook_deliveries'] );
		$this->assertStringContainsString( 'truncated TINYINT(1)', $schema['bfag_webhook_deliveries'] );
	}

	public function test_create_tables_runs_db_delta_once_per_table(): void {
		Installer::create_tables();
		$this->assertCount( 6, $GLOBALS['bfag_test_db_delta'] );
	}

	public function test_activate_seeds_options_caps_rules_and_cron(): void {
		$this->wpdb->queue( 'get_var', '0' );
		$this->wpdb->queue( 'get_var', '0' );
		Installer::activate();

		$this->assertSame( Settings::DEFAULTS, get_option( 'bfag_settings' ) );
		$this->assertSame( 1, get_option( 'bfag_cache_generation' ) );
		$this->assertSame( BFAG_VERSION, get_option( 'bfag_version' ) );
		$this->assertSame( BFAG_DB_VERSION, get_option( 'bfag_db_version' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );

		$inserts = $this->wpdb->calls_to( 'insert' );
		$this->assertCount( 2, $inserts );
		$this->assertSame( 'bfw_bfag_cache_rules', $inserts[0]['table'] );
		$this->assertSame( '**', $inserts[0]['data']['route_glob'] );
		$this->assertSame( 'bfw_bfag_rate_limit_rules', $inserts[1]['table'] );
		$this->assertSame( 'ip', $inserts[1]['data']['subject_type'] );

		foreach ( Installer::CRON as $hook => $schedule ) {
			$this->assertNotFalse( wp_next_scheduled( $hook ), $hook );
			$this->assertSame( $schedule, $GLOBALS['bfag_test_cron'][ $hook ]['recurrence'] );
		}
	}

	public function test_activate_schedules_the_five_minute_jobs_without_plugin_boot(): void {
		// The activation hook fires after plugins_loaded: nothing has registered the schedule yet.
		$this->assertFalse( has_filter( 'cron_schedules' ) );
		$this->assertArrayNotHasKey( 'bfag_five_minutes', wp_get_schedules() );
		$this->assertFalse( wp_schedule_event( time(), 'bfag_five_minutes', 'bfag_probe' ), 'the harness refuses an unregistered recurrence, as core does' );
		$this->assertArrayNotHasKey( 'bfag_probe', $GLOBALS['bfag_test_cron'] );

		$this->wpdb->always( 'get_var', '0' );
		Installer::activate();

		$this->assertSame( [ 'bfag_cron_prune_hits', 'bfag_cron_prune_deliveries', 'bfag_cron_recompute_kpis' ], array_keys( $GLOBALS['bfag_test_cron'] ) );
		$this->assertSame( 'bfag_five_minutes', $GLOBALS['bfag_test_cron']['bfag_cron_prune_hits']['recurrence'] );
		$this->assertSame( 'daily', $GLOBALS['bfag_test_cron']['bfag_cron_prune_deliveries']['recurrence'] );
		$this->assertSame( 'bfag_five_minutes', $GLOBALS['bfag_test_cron']['bfag_cron_recompute_kpis']['recurrence'] );
		$this->assertSame( 10, has_filter( 'cron_schedules', [ Installer::class, 'cron_schedules' ] ), 'activation registered the schedule itself' );
		$this->assertSame( 300, wp_get_schedules()['bfag_five_minutes']['interval'] );
	}

	public function test_activate_does_not_reseed_or_overwrite_existing_settings(): void {
		update_option( 'bfag_settings', [ 'default_cache_ttl' => 120 ] );
		update_option( 'bfag_cache_generation', 9 );
		$this->wpdb->queue( 'get_var', '1' );
		$this->wpdb->queue( 'get_var', '3' );
		Installer::activate();
		$this->assertSame( [ 'default_cache_ttl' => 120 ], get_option( 'bfag_settings' ) );
		$this->assertSame( 9, get_option( 'bfag_cache_generation' ) );
		$this->assertCount( 0, $this->wpdb->calls_to( 'insert' ) );
	}

	public function test_deactivate_clears_cron_and_capability(): void {
		$this->wpdb->always( 'get_var', '0' );
		Installer::activate();
		Installer::deactivate();
		foreach ( array_keys( Installer::CRON ) as $hook ) {
			$this->assertFalse( wp_next_scheduled( $hook ), $hook );
		}
		$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );
		$this->assertSame( Settings::DEFAULTS, get_option( 'bfag_settings' ), 'data survives deactivation' );
	}

	public function test_maybe_upgrade_runs_only_when_db_version_is_behind(): void {
		update_option( 'bfag_db_version', BFAG_DB_VERSION );
		Installer::maybe_upgrade();
		$this->assertCount( 0, $GLOBALS['bfag_test_db_delta'] );

		update_option( 'bfag_db_version', '0.9.0' );
		Installer::maybe_upgrade();
		$this->assertCount( 6, $GLOBALS['bfag_test_db_delta'] );
		$this->assertSame( BFAG_DB_VERSION, get_option( 'bfag_db_version' ) );
	}

	public function test_cron_schedules_adds_five_minute_interval(): void {
		$schedules = Installer::cron_schedules( [] );
		$this->assertSame( 300, $schedules['bfag_five_minutes']['interval'] );
	}

	public function test_schedule_cron_is_idempotent(): void {
		Installer::schedule_cron();
		$first = $GLOBALS['bfag_test_cron'];
		Installer::schedule_cron();
		$this->assertSame( $first, $GLOBALS['bfag_test_cron'] );
	}

	public function test_table_and_option_lists_match_uninstall_php(): void {
		$source = (string) file_get_contents( BFAG_DIR . 'uninstall.php' );
		preg_match( '/\$bfag_tables = \[(.*?)\];/s', $source, $t );
		preg_match( '/\$bfag_option_keys = \[(.*?)\];/s', $source, $o );
		preg_match( '/\$bfag_cron_hooks = \[(.*?)\];/s', $source, $c );
		$extract = static fn( string $block ): array => array_map( static fn( string $s ): string => trim( $s, "'" ), preg_split( '/[\s,]+/', trim( $block ), -1, PREG_SPLIT_NO_EMPTY ) ?: [] );
		$this->assertSame( Installer::TABLES, $extract( $t[1] ?? '' ) );
		$this->assertSame( Installer::OPTIONS, $extract( $o[1] ?? '' ) );
		$this->assertSame( array_keys( Installer::CRON ), $extract( $c[1] ?? '' ) );
	}
}
