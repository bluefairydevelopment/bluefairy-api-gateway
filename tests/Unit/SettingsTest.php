<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit;

use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Tests\TestCase;

final class SettingsTest extends TestCase {

	public function test_defaults_are_returned_when_option_is_absent(): void {
		$settings = new Settings();
		$this->assertSame( Settings::DEFAULTS, $settings->all() );
		$this->assertSame( 60, $settings->get( 'default_cache_ttl' ) );
	}

	public function test_stored_values_are_merged_over_defaults(): void {
		update_option( 'bfag_settings', [ 'default_cache_ttl' => 300 ] );
		$settings = new Settings();
		$this->assertSame( 300, $settings->get( 'default_cache_ttl' ) );
		$this->assertSame( 30, $settings->get( 'retention_days' ) );
	}

	public function test_corrupt_option_falls_back_to_defaults(): void {
		update_option( 'bfag_settings', 'not an array' );
		$this->assertSame( Settings::DEFAULTS, ( new Settings() )->all() );
	}

	public function test_get_unknown_key_returns_null(): void {
		$this->assertNull( ( new Settings() )->get( 'nope' ) );
	}

	public function test_update_sanitises_and_stores(): void {
		$settings = new Settings();
		$stored   = $settings->update( [ 'default_cache_ttl' => '999999', 'debug_logging' => 'on', 'bogus' => 1 ] );
		$this->assertSame( 86400, $stored['default_cache_ttl'] );
		$this->assertTrue( $stored['debug_logging'] );
		$this->assertArrayNotHasKey( 'bogus', $stored );
		$this->assertSame( $stored, get_option( 'bfag_settings' ) );
		$this->assertSame( 86400, $settings->get( 'default_cache_ttl' ), 'per-request cache refreshed after update' );
	}

	public function test_sanitize_clamps_every_bounded_integer(): void {
		$clean = ( new Settings() )->sanitize(
			[
				'default_cache_ttl' => 0,
				'max_payload_bytes' => 10,
				'retention_days'    => 1000,
				'failure_threshold' => -5,
			]
		);
		$this->assertSame( 1, $clean['default_cache_ttl'] );
		$this->assertSame( 1024, $clean['max_payload_bytes'] );
		$this->assertSame( 365, $clean['retention_days'] );
		$this->assertSame( 1, $clean['failure_threshold'] );
	}

	public function test_missing_checkbox_means_false_but_missing_field_keeps_default(): void {
		$clean = ( new Settings() )->sanitize( [] );
		$this->assertFalse( $clean['cache_enabled'], 'an absent checkbox is unticked' );
		$this->assertSame( 60, $clean['default_cache_ttl'] );
	}

	public function test_notification_email_must_be_valid(): void {
		$s = new Settings();
		$this->assertSame( '', $s->sanitize( [ 'notification_email' => 'nope' ] )['notification_email'] );
		$this->assertSame( 'ops@example.com', $s->sanitize( [ 'notification_email' => 'ops@example.com' ] )['notification_email'] );
	}

	public function test_trusted_proxies_accepts_string_or_array_and_drops_garbage(): void {
		$this->assertSame(
			[ '10.0.0.0/8', '2001:db8::1' ],
			Settings::sanitize_cidr_list( "10.0.0.0/8\nnot-an-ip, 2001:db8::1, 10.0.0.0/8, 300.1.1.1" )
		);
		$this->assertSame( [ '192.0.2.1' ], Settings::sanitize_cidr_list( [ '192.0.2.1', 42, '' ] ) );
		$this->assertSame( [], Settings::sanitize_cidr_list( 7 ) );
	}

	/**
	 * @dataProvider bool_inputs
	 */
	public function test_to_bool( mixed $input, bool $expected ): void {
		$this->assertSame( $expected, Settings::to_bool( $input ) );
	}

	/** @return array<string, array{mixed, bool}> */
	public static function bool_inputs(): array {
		return [
			'true'   => [ true, true ],
			'false'  => [ false, false ],
			'1'      => [ '1', true ],
			'on'     => [ 'on', true ],
			'yes'    => [ 'YES', true ],
			'0'      => [ '0', false ],
			'off'    => [ 'off', false ],
			'int1'   => [ 1, true ],
			'int0'   => [ 0, false ],
			'array'  => [ [ 1 ], false ],
			'null'   => [ null, false ],
		];
	}
}
