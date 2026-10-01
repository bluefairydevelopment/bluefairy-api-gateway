<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit;

use BlueFairy\ApiGateway\Capabilities;
use BlueFairy\ApiGateway\Installer;
use BlueFairy\ApiGateway\Tests\TestCase;

/**
 * Runs the real uninstall.php against the stubs.
 */
final class UninstallTest extends TestCase {

	private function run_uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}
		require BFAG_DIR . 'uninstall.php';
	}

	private function seed(): void {
		foreach ( Installer::OPTIONS as $key ) {
			update_option( $key, 'x' );
		}
		set_transient( 'bfag_kpis', [ 1 ] );
		Capabilities::add_caps();
		Installer::schedule_cron();
	}

	public function test_default_keeps_tables_but_removes_options_cron_and_cap(): void {
		$this->seed();
		update_option( 'bfag_settings', [ 'delete_data_on_uninstall' => false ] );
		wp_schedule_single_event( time(), 'bfag_cron_flush_pending', [ 7 ] );
		update_user_meta( 3, 'bfag_proxy_notice_dismissed', 1 );
		update_user_meta( 4, 'bfag_proxy_notice_dismissed', 1 );
		update_user_meta( 4, 'other_plugin_meta', 'keep' );
		$this->run_uninstall();

		$this->assertSame( '', get_user_meta( 3, 'bfag_proxy_notice_dismissed', true ), 'N-08: the proxy-notice dismissal is removed from every user' );
		$this->assertSame( '', get_user_meta( 4, 'bfag_proxy_notice_dismissed', true ) );
		$this->assertSame( 'keep', get_user_meta( 4, 'other_plugin_meta', true ), 'only our key' );

		$this->assertSame( [], $this->wpdb->queries_matching( 'DROP TABLE' ), 'tables are kept unless opted in' );
		foreach ( Installer::OPTIONS as $key ) {
			$this->assertFalse( get_option( $key ), $key );
		}
		foreach ( array_keys( Installer::CRON ) as $hook ) {
			$this->assertFalse( wp_next_scheduled( $hook ), $hook );
		}
		$this->assertSame( [], $GLOBALS['bfag_test_single_events'], 'the pending-flush single event goes too, whatever its args' );
		$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );
		$this->assertCount( 1, $this->wpdb->queries_matching( 'DELETE FROM bfw_options WHERE option_name LIKE' ), 'transients are swept' );
		// D2: the options-table sweep cannot reach a transient held by an external object cache; the KPI transient is deleted by name through the API as well.
		$this->assertFalse( get_transient( 'bfag_kpis' ), 'bfag_kpis is deleted through delete_transient(), whatever backs transients' );
	}

	public function test_tag_generations_option_is_deleted_whether_or_not_data_is_deleted(): void {
		$this->seed();
		update_option( 'bfag_settings', [ 'delete_data_on_uninstall' => false ] );
		update_option( 'bfag_tag_generations', [ 'all' => 4, 'type:post' => 2 ] );
		update_option( 'bfagp_keep_me', 'pro' );
		$this->assertSame( [ 'all' => 4, 'type:post' => 2 ], get_option( 'bfag_tag_generations' ) );

		$this->run_uninstall();

		$this->assertFalse( get_option( 'bfag_tag_generations' ), 'tag generations no longer survive uninstall' );
		$this->assertSame( 'pro', get_option( 'bfagp_keep_me' ), 'only the listed options go' );
	}

	public function test_opt_in_drops_exactly_the_six_tables(): void {
		$this->seed();
		update_option( 'bfag_settings', [ 'delete_data_on_uninstall' => true ] );
		$this->run_uninstall();

		$drops = $this->wpdb->queries_matching( 'DROP TABLE IF EXISTS' );
		$this->assertCount( 6, $drops );
		foreach ( Installer::TABLES as $table ) {
			$this->assertContains( "DROP TABLE IF EXISTS `bfw_{$table}`", $drops );
		}
		foreach ( $drops as $sql ) {
			$this->assertStringNotContainsString( 'bfagp_', $sql, 'never touches the Pro add-on tables' );
		}
	}

	public function test_corrupt_settings_do_not_drop_tables(): void {
		$this->seed();
		update_option( 'bfag_settings', 'garbage' );
		$this->run_uninstall();
		$this->assertSame( [], $this->wpdb->queries_matching( 'DROP TABLE' ) );
	}

	public function test_on_multisite_every_site_is_cleaned_in_turn_and_only_an_opted_in_site_loses_its_tables(): void {
		$GLOBALS['bfag_test_multisite'] = true;
		$GLOBALS['bfag_test_sites']     = [ 1, 2, 3 ];
		foreach ( [ 1, 2, 3 ] as $id ) {
			switch_to_blog( $id );
			$this->seed();
			update_option( 'bfag_settings', [ 'delete_data_on_uninstall' => 2 === $id ] );
			restore_current_blog();
		}
		$GLOBALS['bfag_test_switched'] = [];

		$this->run_uninstall();

		$this->assertSame( [ 1, 2, 3 ], $GLOBALS['bfag_test_switched'], 'every site visited, in order' );
		$this->assertSame( 1, get_current_blog_id(), 'restored to the main site' );
		foreach ( [ 1, 2, 3 ] as $id ) {
			switch_to_blog( $id );
			foreach ( Installer::OPTIONS as $key ) {
				$this->assertFalse( get_option( $key ), "site {$id}: {$key}" );
			}
			foreach ( array_keys( Installer::CRON ) as $hook ) {
				$this->assertFalse( wp_next_scheduled( $hook ), "site {$id}: {$hook}" );
			}
			$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ), "site {$id}: capability" );
			restore_current_blog();
		}

		$drops = $this->wpdb->queries_matching( 'DROP TABLE IF EXISTS' );
		$this->assertCount( 6, $drops, 'only site 2 opted in' );
		foreach ( $drops as $sql ) {
			$this->assertStringContainsString( '`bfw_2_bfag_', $sql );
		}
		$sweeps = $this->wpdb->queries_matching( 'WHERE option_name LIKE' );
		$this->assertCount( 3, $sweeps, 'transients swept on every site' );
		foreach ( [ 'bfw_options', 'bfw_2_options', 'bfw_3_options' ] as $i => $table ) {
			$this->assertStringContainsString( "DELETE FROM {$table} ", $sweeps[ $i ] );
		}
	}
}
