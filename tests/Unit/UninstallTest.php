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
		$this->run_uninstall();

		$this->assertSame( [], $this->wpdb->queries_matching( 'DROP TABLE' ), 'tables are kept unless opted in' );
		foreach ( Installer::OPTIONS as $key ) {
			$this->assertFalse( get_option( $key ), $key );
		}
		foreach ( array_keys( Installer::CRON ) as $hook ) {
			$this->assertFalse( wp_next_scheduled( $hook ), $hook );
		}
		$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );
		$this->assertCount( 1, $this->wpdb->queries_matching( 'DELETE FROM bfw_options WHERE option_name LIKE' ), 'transients are swept' );
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
}
