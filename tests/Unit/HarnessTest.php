<?php
/**
 * The stubs the product tests lean on, pinned where they model core
 * behaviour a product test would otherwise be unable to distinguish from a
 * stub that cannot fail: string options, cache TTLs, cron events keyed by
 * their arguments, the URL safety rules and per-site state on multisite.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit;

use BlueFairy\ApiGateway\Tests\TestCase;

final class HarnessTest extends TestCase {

	public function test_get_option_returns_scalars_as_strings_and_arrays_as_arrays(): void {
		update_option( 'bfag_h_int', 4 );
		update_option( 'bfag_h_bool', true );
		update_option( 'bfag_h_false', false );
		update_option( 'bfag_h_arr', [ 'a' => 1 ] );
		$this->assertSame( '4', get_option( 'bfag_h_int' ) );
		$this->assertSame( '1', get_option( 'bfag_h_bool' ) );
		$this->assertSame( '', get_option( 'bfag_h_false' ) );
		$this->assertSame( [ 'a' => 1 ], get_option( 'bfag_h_arr' ) );
		$this->assertSame( 7, get_option( 'bfag_h_missing', 7 ), 'a default is returned untouched' );
	}

	public function test_wp_cache_set_honours_expire(): void {
		bfag_test_set_time( 1_700_000_000 );
		wp_cache_set( 'k', 'v', 'g', 10 );
		wp_cache_set( 'forever', 'v', 'g' );
		$this->assertSame( 'v', wp_cache_get( 'k', 'g' ) );

		bfag_test_set_time( 1_700_000_010 );
		$this->assertSame( 'v', wp_cache_get( 'k', 'g' ), 'alive at the boundary, as a transient is' );

		bfag_test_set_time( 1_700_000_011 );
		$this->assertFalse( wp_cache_get( 'k', 'g', false, $found ) );
		$this->assertFalse( $found );
		$this->assertSame( 'v', wp_cache_get( 'forever', 'g' ), 'no expiry: lives on' );
		$this->assertTrue( wp_cache_add( 'k', 'again', 'g' ), 'an expired key can be added again' );
		$this->assertFalse( wp_cache_add( 'forever', 'again', 'g' ), 'a live key cannot' );
	}

	public function test_wp_clear_scheduled_hook_removes_only_events_with_the_same_args(): void {
		wp_schedule_single_event( 100, 'bfag_h', [ 7 ] );
		wp_schedule_single_event( 100, 'bfag_h', [] );
		wp_schedule_event( 100, 'daily', 'bfag_h_rec', [ 1 ] );

		$this->assertSame( 1, wp_clear_scheduled_hook( 'bfag_h' ), 'no args: only the no-arg event goes' );
		$this->assertNotFalse( wp_next_scheduled( 'bfag_h', [ 7 ] ), 'the event scheduled with [7] stays' );
		$this->assertFalse( wp_next_scheduled( 'bfag_h', [] ) );
		$this->assertSame( 0, wp_clear_scheduled_hook( 'bfag_h_rec' ), 'the recurring event carries args' );
		$this->assertNotFalse( wp_next_scheduled( 'bfag_h_rec' ) );
		$this->assertSame( 1, wp_clear_scheduled_hook( 'bfag_h', [ 7 ] ), 'the same args clear it' );

		wp_schedule_single_event( 100, 'bfag_h', [ 8 ] );
		wp_schedule_single_event( 100, 'bfag_h', [ 9 ] );
		$this->assertSame( 2, wp_unschedule_hook( 'bfag_h' ), 'wp_unschedule_hook() removes every event for the hook' );
		$this->assertSame( 1, wp_unschedule_hook( 'bfag_h_rec' ) );
		$this->assertSame( [], $GLOBALS['bfag_test_single_events'] );
		$this->assertSame( [], $GLOBALS['bfag_test_cron'] );
	}

	public function test_wp_http_validate_url_refuses_userinfo_and_unlisted_ports(): void {
		$this->assertSame( 'https://h.example.com/x', wp_http_validate_url( 'https://h.example.com/x' ) );
		$this->assertSame( 'https://h.example.com:8080/x', wp_http_validate_url( 'https://h.example.com:8080/x' ) );
		$this->assertSame( 'http://h.example.com:80/x', wp_http_validate_url( 'http://h.example.com:80/x' ) );
		$this->assertSame( 'https://h.example.com:443/x', wp_http_validate_url( 'https://h.example.com:443/x' ) );
		$this->assertFalse( wp_http_validate_url( 'https://h.example.com:8443/x' ) );
		$this->assertFalse( wp_http_validate_url( 'https://u:p@h.example.com/x' ) );
		$this->assertFalse( wp_http_validate_url( 'https://u@h.example.com/x' ) );
		$this->assertFalse( wp_http_validate_url( 'https://localhost/x' ), 'the earlier rules still hold' );
		$this->assertFalse( wp_http_validate_url( 'https://10.0.0.1/x' ) );
	}

	public function test_switch_to_blog_isolates_options_cron_roles_and_the_table_prefix(): void {
		update_option( 'bfag_h', 'one' );

		switch_to_blog( 2 );
		$this->assertSame( 2, get_current_blog_id() );
		$this->assertFalse( get_option( 'bfag_h' ) );
		$this->assertSame( 'bfw_2_', $GLOBALS['wpdb']->prefix );
		$this->assertSame( 'bfw_2_options', $GLOBALS['wpdb']->options );
		update_option( 'bfag_h', 'two' );
		wp_schedule_event( 1, 'daily', 'bfag_h_cron' );
		get_role( 'administrator' )->add_cap( 'bfag_h_cap' );

		$this->assertTrue( restore_current_blog() );
		$this->assertSame( 1, get_current_blog_id() );
		$this->assertSame( 'one', get_option( 'bfag_h' ) );
		$this->assertSame( 'bfw_', $GLOBALS['wpdb']->prefix );
		$this->assertSame( 'bfw_options', $GLOBALS['wpdb']->options );
		$this->assertFalse( wp_next_scheduled( 'bfag_h_cron' ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( 'bfag_h_cap' ) );

		switch_to_blog( 2 );
		$this->assertSame( 'two', get_option( 'bfag_h' ), 'site 2 kept its own' );
		$this->assertNotFalse( wp_next_scheduled( 'bfag_h_cron' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'bfag_h_cap' ) );
		restore_current_blog();

		$this->assertSame( [ 2, 2 ], $GLOBALS['bfag_test_switched'] );
		$this->assertFalse( restore_current_blog(), 'nothing left to restore' );
	}

	public function test_get_sites_pages_like_core(): void {
		$GLOBALS['bfag_test_sites'] = range( 1, 5 );
		$this->assertSame( [ 1, 2 ], get_sites( [ 'fields' => 'ids', 'number' => 2 ] ) );
		$this->assertSame( [ 3, 4 ], get_sites( [ 'fields' => 'ids', 'number' => 2, 'offset' => 2 ] ) );
		$this->assertSame( [ 5 ], get_sites( [ 'fields' => 'ids', 'number' => 2, 'offset' => 4 ] ) );
		$this->assertSame( [], get_sites( [ 'fields' => 'ids', 'number' => 2, 'offset' => 6 ] ) );
		$this->assertSame( [ 1, 2, 3, 4, 5 ], get_sites( [ 'fields' => 'ids', 'number' => 0 ] ), 'number 0 is no limit' );
		$this->assertSame( '3', get_sites( [ 'number' => 1, 'offset' => 2 ] )[0]->blog_id, 'objects unless fields=ids' );
	}
}
