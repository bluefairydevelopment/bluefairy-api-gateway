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
		$this->assertNotFalse( wp_next_scheduled( 'bfag_h_rec', [ 1 ] ) );
		$this->assertFalse( wp_next_scheduled( 'bfag_h_rec' ), 'TF5: as core, a recurring event is keyed by hook AND args' );
		wp_schedule_event( 200, 'daily', 'bfag_h_rec', [ 2 ] );
		$this->assertCount( 2, array_filter( array_keys( $GLOBALS['bfag_test_cron'] ), static fn( string $k ): bool => str_starts_with( $k, 'bfag_h_rec' ) ), 'two recurring events of one hook with different args coexist' );
		$this->assertSame( 1, wp_clear_scheduled_hook( 'bfag_h_rec', [ 2 ] ), 'and are cleared by their own args' );
		$this->assertNotFalse( wp_next_scheduled( 'bfag_h_rec', [ 1 ] ) );
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

	/**
	 * TF1: the date stubs render in the site timezone, as core does.
	 */
	public function test_date_stubs_honour_the_site_timezone(): void {
		$noon = gmmktime( 12, 0, 0, 10, 1, 2026 );
		$this->assertSame( '2026-10-01 12:00:00', get_date_from_gmt( '2026-10-01 12:00:00' ), 'no timezone_string: UTC' );
		update_option( 'timezone_string', 'America/Chicago' );
		$this->assertSame( '2026-10-01 07:00:00', get_date_from_gmt( '2026-10-01 12:00:00' ), 'CDT is UTC−5' );
		$this->assertSame( '07:00', get_date_from_gmt( '2026-10-01 12:00:00', 'H:i' ) );
		bfag_test_set_time( $noon );
		$this->assertSame( '07:00', date_i18n( 'H:i' ), 'no timestamp: now, in the site timezone' );
		$this->assertSame( '12:00', date_i18n( 'H:i', false, true ), '$gmt: now, in UTC' );
		$this->assertSame( '12:00', date_i18n( 'H:i', $noon ), 'a given timestamp is a WP timestamp (already shifted) and is formatted as-is, as core does' );
		update_option( 'timezone_string', 'Not/AZone' );
		$this->assertSame( '2026-10-01 12:00:00', get_date_from_gmt( '2026-10-01 12:00:00' ), 'an invalid zone falls back to UTC' );
	}

	/**
	 * TF4 / TF6: the REST-serving flag defaults false (as core, outside a
	 * REST request) and wp_cache_supports() is switchable.
	 */
	public function test_rest_serving_defaults_false_and_cache_supports_is_switchable(): void {
		$this->assertFalse( wp_is_serving_rest_request(), 'a test that needs the top-level REST request sets bfag_test_serving_rest itself' );
		$this->assertTrue( wp_cache_supports( 'flush_group' ) );
		$this->assertFalse( wp_cache_supports( 'flush_runtime' ) );
		$GLOBALS['bfag_test_cache_supports'] = [];
		$this->assertFalse( wp_cache_supports( 'flush_group' ) );
	}

	/**
	 * TF7: `__()` runs the `gettext` filter as core's translate() does, so a
	 * test can plant a translation and tell "translated" from "the literal"
	 * — the only way to assert that a string was NOT translated (D6).
	 */
	public function test_gettext_stubs_honour_the_gettext_filter_and_did_action_counts(): void {
		$this->assertSame( 'Plain', __( 'Plain', 'bluefairy-api-gateway' ), 'control: no filter, the literal' );
		add_filter(
			'gettext',
			static function ( string $translation, string $text, string $domain ): string {
				return 'bluefairy-api-gateway' === $domain ? '[xx] ' . $text : $translation;
			},
			10,
			3
		);
		$this->assertSame( '[xx] Plain', __( 'Plain', 'bluefairy-api-gateway' ) );
		$this->assertSame( '[xx] &lt;b&gt;', esc_html__( '<b>', 'bluefairy-api-gateway' ), 'esc_html__() translates, then escapes' );
		$this->assertSame( '[xx] a&quot;', esc_attr__( 'a"', 'bluefairy-api-gateway' ) );
		$this->assertSame( 'Other', __( 'Other', 'other-domain' ), 'the filter sees the domain' );

		$this->assertSame( 0, did_action( 'init' ), 'the harness starts before init, as plugins_loaded is' );
		do_action( 'init' );
		$this->assertSame( 1, did_action( 'init' ) );
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

	/**
	 * P7 / F21: update_option() as core — unchanged values are not written
	 * and return false (a `2` over a stored `'2'` included), the autoload
	 * flag of an existing option moves only with its value, and a write the
	 * database refuses returns false and stores nothing.
	 */
	public function test_update_option_models_core_unchanged_autoload_and_failure(): void {
		$this->assertTrue( update_option( 'bfag_h', 2, false ), 'a missing option is added' );
		$this->assertSame( 'off', $GLOBALS['bfag_test_option_autoload']['bfag_h'] );
		$this->assertFalse( update_option( 'bfag_h', 2, true ), 'unchanged: not written' );
		$this->assertFalse( update_option( 'bfag_h', '2', true ), 'the stored form is a string: still unchanged' );
		$this->assertSame( 'off', $GLOBALS['bfag_test_option_autoload']['bfag_h'], 'the flag did not move without the value' );
		$this->assertTrue( update_option( 'bfag_h', 3, true ) );
		$this->assertSame( 'on', $GLOBALS['bfag_test_option_autoload']['bfag_h'], 'it moves with a changed value' );
		$this->assertTrue( update_option( 'bfag_h', 4 ), 'null autoload keeps the flag' );
		$this->assertSame( 'on', $GLOBALS['bfag_test_option_autoload']['bfag_h'] );

		$this->assertSame( [ 'bfag_h' => true, 'bfag_nope' => false ], wp_set_option_autoload_values( [ 'bfag_h' => false, 'bfag_nope' => true ] ) );
		$this->assertSame( 'off', $GLOBALS['bfag_test_option_autoload']['bfag_h'] );
		$this->assertArrayNotHasKey( 'bfag_nope', $GLOBALS['bfag_test_options'], 'a missing option is not created' );

		$GLOBALS['bfag_test_option_write_fails'] = [ 'bfag_h', 'bfag_new' ];
		$this->assertFalse( update_option( 'bfag_h', 5 ) );
		$this->assertSame( '4', get_option( 'bfag_h' ), 'a refused write stores nothing' );
		$this->assertFalse( update_option( 'bfag_new', 1 ) );
		$this->assertFalse( add_option( 'bfag_new', 1 ) );
		$this->assertFalse( get_option( 'bfag_new' ) );
	}

	/**
	 * T5 / WPMAP-114: get_userdata() answers from the registry. The base
	 * suite runs with it on; null is the unscripted mode a suite from
	 * another namespace keeps.
	 */
	public function test_users_come_from_the_registry_and_an_unknown_id_is_no_user(): void {
		$this->assertSame( [], $GLOBALS['bfag_test_users'], 'the base TestCase turns the registry on' );
		$this->assertFalse( get_userdata( 7 ), 'an unregistered positive id is no user, as in core' );
		$this->assertFalse( get_user_by( 'id', 7 ) );

		$user = bfag_test_add_user( 7, [ 'user_login' => 'ann' ] );
		$this->assertSame( $user, get_userdata( 7 ) );
		$this->assertSame( 'ann', get_user_by( 'id', '7' )->user_login );
		$this->assertFalse( get_userdata( 8 ) );
		$this->assertFalse( get_userdata( 0 ) );

		$GLOBALS['bfag_test_users'] = null;
		$this->assertInstanceOf( \WP_User::class, get_userdata( 8 ), 'unscripted: any positive id' );
		$this->assertFalse( get_userdata( 0 ) );
		bfag_test_reset();
		$this->assertNull( $GLOBALS['bfag_test_users'], 'the reset default is unscripted (the Pro suite relies on it)' );
	}

	/**
	 * T7 / WPMAP-114: wp_schedule_single_event() refuses a duplicate — same
	 * hook and args — within ten minutes, as core does.
	 */
	public function test_single_events_dedupe_within_ten_minutes_as_core(): void {
		bfag_test_set_time( 1_700_000_000 );
		$now = bfag_test_now(); // The harness clock (time() is PHP's own).
		$this->assertTrue( wp_schedule_single_event( $now + 3600, 'bfag_h', [ 1 ] ) );
		$this->assertFalse( wp_schedule_single_event( $now + 3600 + 600, 'bfag_h', [ 1 ] ), 'ten minutes later: still a duplicate' );
		$this->assertFalse( wp_schedule_single_event( $now + 3600 - 599, 'bfag_h', [ 1 ] ) );
		$this->assertTrue( wp_schedule_single_event( $now + 3600 + 601, 'bfag_h', [ 1 ] ), 'just outside the window' );
		$this->assertTrue( wp_schedule_single_event( $now + 3600, 'bfag_h', [ 2 ] ), 'other args are another event' );
		$this->assertTrue( wp_schedule_single_event( $now + 3600, 'bfag_other', [ 1 ] ), 'another hook' );
		$this->assertCount( 4, $GLOBALS['bfag_test_single_events'] );

		// Due within ten minutes: any earlier event with the key collides.
		$this->assertTrue( wp_schedule_single_event( $now - 5000, 'bfag_soon' ) );
		$this->assertFalse( wp_schedule_single_event( $now + 60, 'bfag_soon' ) );
		// A recurring event counts.
		wp_schedule_event( $now + 100, 'daily', 'bfag_recurring' );
		$this->assertFalse( wp_schedule_single_event( $now, 'bfag_recurring' ) );
		$this->assertTrue( wp_schedule_single_event( $now, 'bfag_recurring', [ 'x' ] ) );
	}

	/**
	 * T7 / WPMAP-114: size_format() and human_time_diff() phrase as core
	 * does, so a view test cannot pass only on stub text.
	 */
	public function test_size_format_and_human_time_diff_phrase_as_core(): void {
		$this->assertSame( '512 B', size_format( 512 ) );
		$this->assertSame( '1 KB', size_format( 1024 ) );
		$this->assertSame( '1.5 MB', size_format( 1572864, 1 ) );
		$this->assertFalse( size_format( 'lots' ) );

		bfag_test_set_time( 1_700_000_000 );
		$this->assertSame( '1 second', human_time_diff( bfag_test_now() - 1 ) );
		$this->assertSame( '45 seconds', human_time_diff( bfag_test_now() - 45 ) );
		$this->assertSame( '5 mins', human_time_diff( bfag_test_now() - 300 ) );
		$this->assertSame( '1 hour', human_time_diff( bfag_test_now() - 3600 ) );
		$this->assertSame( '2 days', human_time_diff( 1_700_000_000, 1_700_172_800 ) );
	}
}
