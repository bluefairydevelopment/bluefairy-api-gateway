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

	/**
	 * P1: the Cache group tells the admin what happens without Redis/Memcached.
	 */
	public function test_the_cache_group_carries_the_transient_note_and_renders_it(): void {
		$groups = $this->page()->groups();
		$note   = 'Without a persistent object cache (Redis, Memcached) responses are stored as transients in the options table and capped at 64 KB and one hour.';
		$this->assertSame( $note, $groups['cache']['note'] );
		$this->assertArrayNotHasKey( 'note', $groups['general'], 'only the Cache group has one' );

		$html = bfag_test_render( [ $this->page(), 'render' ] );
		$this->assertSame( 1, substr_count( $html, 'bfag-group-note' ), 'rendered exactly once' );
		$this->assertStringContainsString( '<h2 id="bfag-group-cache">Cache</h2>', $html );
		$this->assertStringContainsString( '<p class="description bfag-group-note">' . esc_html( $note ) . '</p>', $html );
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
		$this->assertSame( 'Consecutive webhook failures before an alert is sent.', $fields['failure_threshold']['description'], 'NF-29: shown only with Pro, so it does not talk about the free plugin' );

		// U-23: without Pro the two fields are hidden inputs (values kept, nothing promised) and the General heading goes with them.
		update_option( 'bfag_settings', [ 'notification_email' => 'ops@example.com' ] );
		$html = bfag_test_render( [ $this->page(), 'render' ] );
		$this->assertStringContainsString( '<input type="hidden" name="bfag[notification_email]" value="ops@example.com" />', $html );
		$this->assertStringContainsString( '<input type="hidden" name="bfag[failure_threshold]" value="5" />', $html );
		$this->assertStringNotContainsString( 'Where the Pro add-on sends failure alerts', $html );
		$this->assertStringNotContainsString( 'before an alert is sent', $html );
		$this->assertStringNotContainsString( '>General</h2>', $html );
		$this->assertStringContainsString( '<h2 id="bfag-group-ratelimit">', $html, 'NF-30: the proxy notice links to this anchor' );
		$this->assertStringContainsString( 'name="bfag[trusted_proxies]"', $html );
		$this->assertGreaterThan( strpos( $html, 'id="bfag-group-ratelimit"' ), strpos( $html, 'name="bfag[trusted_proxies]"' ), 'Trusted proxies is in the rate-limit group' );
		$this->assertStringContainsString( '>Cache</h2>', $html );

		add_filter( 'bfag_is_pro', '__return_true' );
		$html = bfag_test_render( [ $this->page(), 'render' ] );
		$this->assertStringContainsString( 'Where the Pro add-on sends failure alerts. Leave blank to use the site admin email.', $html );
		$this->assertStringContainsString( 'Consecutive webhook failures before an alert is sent.', $html );
		$this->assertStringContainsString( '>General</h2>', $html );
		$this->assertStringContainsString( 'type="email" id="bfag-setting-notification-email"', $html );
		$this->assertStringNotContainsString( 'Where alerts are sent.', $html, 'the free plugin sends no alerts' );
	}

	/**
	 * U-09: the setting pre-fills a new rule's TTL; it is no longer a fallback.
	 */
	public function test_the_ttl_setting_is_labelled_as_the_new_rule_ttl(): void {
		$field = $this->page()->groups()['cache']['fields']['default_cache_ttl'];
		$this->assertSame( 'New-rule TTL (seconds)', $field['label'] );
		$this->assertSame( 'New-rule TTL — pre-fills the TTL of a new cache rule. Every rule carries its own TTL once saved.', $field['description'] );
		$html = bfag_test_render( [ $this->page(), 'render' ] );
		$this->assertStringContainsString( 'New-rule TTL — pre-fills the TTL of a new cache rule.', $html );
		$this->assertStringNotContainsString( 'Default TTL', $html );
		$this->assertStringNotContainsString( 'Used when a rule has no TTL of its own.', $html );
	}

	public function test_render_shows_every_field_with_its_value_and_fires_the_fields_hook_inside_the_table(): void {
		add_filter( 'bfag_is_pro', '__return_true' );
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
		$this->assertStringContainsString( 'then tick &#039;Hide the WP-Cron notice&#039; on this page&#039;s Settings tab (Advanced).', $html, 'U-23: says where the box is' );
		$this->assertStringNotContainsString( 'under Settings › Advanced', $html );

		update_option( 'bfag_settings', [ 'disable_cron_notice' => true ] );
		$acknowledged = $this->page();
		$this->assertFalse( $acknowledged->cron_notice_due() );
		$this->assertSame( '', bfag_test_render( [ $acknowledged, 'render_cron_notice' ] ), 'acknowledged: silent' );
	}

	public function test_the_editor_exemption_checkbox_and_the_trusted_proxies_help_text(): void {
		$fields = $this->page()->groups()['ratelimit']['fields'];
		$this->assertSame( 'checkbox', $fields['rate_limit_exempt_editors']['type'] );
		$this->assertSame( 'Do not rate-limit logged-in users who can edit posts in their own browser session (the block editor\'s own REST calls). Requests made with an API key or an application password are always limited.', $fields['rate_limit_exempt_editors']['description'] );
		$this->assertSame( 'One IP or CIDR per line. Needed behind Cloudflare, a load balancer or a CDN. Without it the proxy\'s address is the client IP and all visitors share one bucket; with it, the chosen header is read only from these addresses so visitors cannot forge their IP.', $fields['trusted_proxies']['description'] );

		$html = bfag_test_render( [ $this->page(), 'render' ] );
		$this->assertMatchesRegularExpression( '/name="bfag\[rate_limit_exempt_editors\]" value="1"\s+checked="checked"/', $html, 'default on' );
		$this->assertStringContainsString( 'Needed behind Cloudflare, a load balancer or a CDN.', $html );
		$this->assertStringContainsString( 'the block editor&#039;s own REST calls', $html );

		update_option( 'bfag_settings', [ 'rate_limit_exempt_editors' => false ] );
		$html = bfag_test_render( [ $this->page(), 'render' ] );
		$this->assertMatchesRegularExpression( '/name="bfag\[rate_limit_exempt_editors\]" value="1"\s*\/>/', $html, 'unchecked when off' );
	}
}
