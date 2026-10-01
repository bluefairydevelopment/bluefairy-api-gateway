<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Admin;

use BlueFairy\ApiGateway\Admin\SettingsPage;
use BlueFairy\ApiGateway\Capabilities;
use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class SettingsPageTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		bfag_test_grant( Capabilities::MANAGE );
	}

	private function page( ?Settings $settings = null ): SettingsPage {
		return new SettingsPage( $settings ?? new Settings() );
	}

	public function test_tab_and_actions(): void {
		$this->assertSame( 'settings', $this->page()->tab() );
		$this->assertSame( [ 'save_settings' ], $this->page()->actions() );
	}

	public function test_every_default_appears_in_exactly_one_group(): void {
		$groups = $this->page()->groups();
		$this->assertSame( [ 'general', 'cache', 'ratelimit', 'webhooks', 'advanced' ], array_keys( $groups ) );
		$keys = [];
		foreach ( $groups as $group ) {
			foreach ( array_keys( $group['fields'] ) as $key ) {
				$keys[] = $key;
			}
		}
		sort( $keys );
		$expected = array_keys( Settings::DEFAULTS );
		sort( $expected );
		$this->assertSame( $expected, $keys );
		$this->assertSame( 'textarea', $groups['ratelimit']['fields']['trusted_proxies']['type'] );
	}

	/**
	 * Nothing in the free plugin sends an alert; the two alert fields must
	 * say the Pro add-on is what reads them, and must not promise that a
	 * blank email makes the free plugin mail the admin.
	 */
	public function test_the_alert_fields_say_the_pro_add_on_is_what_reads_them(): void {
		$fields = $this->page()->groups()['general']['fields'];
		$this->assertSame( 'Where the Pro add-on sends failure alerts. Leave blank to use the site admin email.', $fields['notification_email']['description'] );
		$this->assertSame( 'Consecutive webhook failures before the Pro add-on raises an alert. The free plugin stores this but sends nothing.', $fields['failure_threshold']['description'] );

		$html = bfag_test_render( [ $this->page(), 'render' ] );
		$this->assertStringContainsString( 'Where the Pro add-on sends failure alerts. Leave blank to use the site admin email.', $html );
		$this->assertStringContainsString( 'before the Pro add-on raises an alert', $html );
		$this->assertStringNotContainsString( 'Where alerts are sent.', $html, 'the free plugin sends no alerts' );
	}

	public function test_render_shows_every_field_with_its_value_and_fires_the_fields_hook_inside_the_table(): void {
		update_option(
			'bfag_settings',
			[
				'cache_enabled'      => false,
				'default_cache_ttl'  => 120,
				'trusted_proxies'    => [ '10.0.0.0/8', '192.0.2.1' ],
				'notification_email' => 'ops@example.com',
			]
		);
		$seen = null;
		add_action(
			'bfag_settings_fields',
			static function ( Settings $settings ) use ( &$seen ): void {
				$seen = $settings;
				echo '<tr><td>PRO-ROW</td></tr>';
			}
		);

		$html = bfag_test_render( [ $this->page(), 'render' ] );

		foreach ( array_keys( Settings::DEFAULTS ) as $key ) {
			$this->assertStringContainsString( 'name="bfag[' . $key . ']"', $html, $key );
		}
		$this->assertStringContainsString( 'name="bfag_action" value="save_settings"', $html );
		$this->assertStringContainsString( 'name="_wpnonce" value="nonce-bfag_save_settings"', $html );
		$this->assertStringContainsString( "<textarea id=\"bfag-setting-trusted-proxies\" name=\"bfag[trusted_proxies]\" class=\"large-text code\" rows=\"4\">10.0.0.0/8\n192.0.2.1</textarea>", $html );
		$this->assertStringContainsString( 'name="bfag[default_cache_ttl]" class="regular-text" value="120"', $html );
		$this->assertStringContainsString( 'value="ops@example.com"', $html );
		$this->assertMatchesRegularExpression( '/name="bfag\[cache_enabled\]" value="1"\s*\/>/', $html, 'unchecked' );
		$this->assertMatchesRegularExpression( '/name="bfag\[rate_limit_enabled\]" value="1"\s+checked="checked"/', $html, 'default on' );
		$this->assertStringContainsString( 'PRO-ROW', $html );
		$this->assertTrue( strpos( $html, 'PRO-ROW' ) < strpos( $html, '</table>' ) );
		$this->assertInstanceOf( Settings::class, $seen );
		foreach ( [ 'General', 'Cache', 'Rate limit', 'Webhooks', 'Advanced' ] as $heading ) {
			$this->assertStringContainsString( '<h2 id="bfag-group-', $html );
			$this->assertStringContainsString( '>' . $heading . '</h2>', $html );
		}
	}

	public function test_save_stores_sanitised_values_fires_settings_saved_with_the_raw_post_and_redirects(): void {
		$seen = null;
		add_action(
			'bfag_settings_saved',
			static function ( array $clean, array $raw ) use ( &$seen ): void {
				$seen = [ $clean, $raw ];
			},
			10,
			2
		);
		bfag_test_admin_request(
			'save_settings',
			[
				'cache_enabled'      => '1',
				'default_cache_ttl'  => '999999',
				'trusted_proxies'    => "10.0.0.0/8\nnot-an-ip\n203.0.113.9",
				'notification_email' => 'bad email',
				'retention_days'     => '7',
				'unknown_key'        => 'dropped',
			]
		);
		$_POST['bfagp_api_keys'] = 'on';
		$_REQUEST                = array_merge( $_REQUEST, $_POST );

		$settings = new Settings();
		$exit     = bfag_test_catch_exit( [ $this->page( $settings ), 'handle_actions' ] );

		$this->assertSame( 'redirect', $exit->kind );
		$this->assertSame( 'https://example.com/wp-admin/options-general.php?page=bfag-gateway&tab=settings&bfag_notice=settings_saved', $exit->payload );
		$stored = get_option( 'bfag_settings' );
		$this->assertTrue( $stored['cache_enabled'] );
		$this->assertFalse( $stored['rate_limit_enabled'], 'missing checkbox is off' );
		$this->assertSame( 86400, $stored['default_cache_ttl'], 'clamped' );
		$this->assertSame( [ '10.0.0.0/8', '203.0.113.9' ], $stored['trusted_proxies'] );
		$this->assertSame( '', $stored['notification_email'] );
		$this->assertSame( 7, $stored['retention_days'] );
		$this->assertArrayNotHasKey( 'unknown_key', $stored );
		$this->assertSame( 86400, $settings->get( 'default_cache_ttl' ), 'the instance sees the new value' );
		$this->assertIsArray( $seen );
		$this->assertSame( $stored, $seen[0] );
		$this->assertSame( 'on', $seen[1]['bfagp_api_keys'], 'the whole POST reaches the add-on' );
		$this->assertSame( "10.0.0.0/8\nnot-an-ip\n203.0.113.9", $seen[1]['bfag']['trusted_proxies'], 'raw means raw' );
	}

	public function test_a_bad_nonce_or_missing_capability_dies_and_stores_nothing(): void {
		bfag_test_admin_request( 'save_settings', [ 'cache_enabled' => '0' ], [], false );
		$exit = bfag_test_catch_exit( [ $this->page(), 'handle_actions' ] );
		$this->assertSame( [ 'die', 403 ], [ $exit->kind, $exit->status ] );

		$GLOBALS['bfag_test_caps'] = [];
		bfag_test_admin_request( 'save_settings', [ 'cache_enabled' => '0' ] );
		$exit = bfag_test_catch_exit( [ $this->page(), 'handle_actions' ] );
		$this->assertSame( [ 'die', 403 ], [ $exit->kind, $exit->status ] );
		$this->assertFalse( get_option( 'bfag_settings' ) );
		$this->assertSame( 0, did_action( 'bfag_settings_saved' ) );
	}

	public function test_no_cron_notice_when_the_constant_is_not_defined(): void {
		$this->assertFalse( defined( 'DISABLE_WP_CRON' ), 'precondition: this process has no DISABLE_WP_CRON' );
		bfag_test_set_screen( 'settings_page_bfag-gateway' );
		$this->assertFalse( $this->page()->cron_notice_due() );
		$this->assertSame( '', bfag_test_render( [ $this->page(), 'render_cron_notice' ] ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_cron_notice_shows_on_the_plugin_screen_when_wp_cron_is_disabled_and_not_acknowledged(): void {
		define( 'DISABLE_WP_CRON', true );
		bfag_test_grant( Capabilities::MANAGE );
		$page = $this->page();
		$this->assertTrue( $page->cron_notice_due() );

		bfag_test_set_screen( 'edit-post' );
		$this->assertSame( '', bfag_test_render( [ $page, 'render_cron_notice' ] ), 'not on other screens' );

		bfag_test_set_screen( 'settings_page_bfag-gateway' );
		$html = bfag_test_render( [ $page, 'render_cron_notice' ] );
		$this->assertStringContainsString( 'notice notice-warning', $html );
		$this->assertStringContainsString( 'WP-Cron is disabled', $html );

		update_option( 'bfag_settings', [ 'disable_cron_notice' => true ] );
		$acknowledged = $this->page();
		$this->assertFalse( $acknowledged->cron_notice_due() );
		$this->assertSame( '', bfag_test_render( [ $acknowledged, 'render_cron_notice' ] ), 'acknowledged: silent' );
	}
}
