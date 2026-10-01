<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Admin;

use BlueFairy\ApiGateway\Admin\ProUpsell;
use BlueFairy\ApiGateway\Admin\WebhookPage;
use BlueFairy\ApiGateway\Capabilities;
use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Tests\TestCase;
use BlueFairy\ApiGateway\Webhook\DeliveryRepository;
use BlueFairy\ApiGateway\Webhook\EventRegistry;
use BlueFairy\ApiGateway\Webhook\WebhookDispatcher;
use BlueFairy\ApiGateway\Webhook\WebhookRepository;

final class WebhookPageTest extends TestCase {

	private const SECRET = 'deadbeefcafe0123456789abcdef0123456789abcdef0123456789abcdef0123';

	protected function setUp(): void {
		parent::setUp();
		bfag_test_grant( Capabilities::MANAGE );
	}

	private function page(): WebhookPage {
		$settings = new Settings();
		$delivs   = new DeliveryRepository( $settings );
		return new WebhookPage( new WebhookRepository(), $delivs, new WebhookDispatcher( $delivs, $settings ), new EventRegistry(), new ProUpsell() );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function webhook( int $id = 1, int $enabled = 1, string $preset = '' ): array {
		return [
			'id'          => $id,
			'label'       => 'CRM <sync>',
			'url'         => 'https://hooks.example.net/path/secret-token',
			'event'       => 'post.published',
			'secret'      => self::SECRET,
			'headers'     => '{"X-Api-Key":"k-123"}',
			'preset'      => $preset,
			'enabled'     => $enabled,
			'max_retries' => 5,
			'created_at'  => '2026-01-01 00:00:00',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function delivery( int $id = 10, string $status = 'failed', int $truncated = 0, string $direction = 'out' ): array {
		return [
			'id'            => $id,
			'webhook_id'    => 1,
			'direction'     => $direction,
			'event'         => 'post.published',
			'payload'       => '{"event":"post.published"}',
			'truncated'     => $truncated,
			'status'        => $status,
			'http_status'   => 'failed' === $status ? 500 : 200,
			'response_body' => '',
			'attempt'       => 2,
			'next_retry_at' => null,
			'delivered_at'  => '2026-01-02 00:00:00',
			'created_at'    => '2026-01-01 00:00:00',
		];
	}

	/**
	 * Script the list render: all() → get_results, count() → get_var, last_statuses() → ONE get_results (P8), find() → get_row when editing.
	 *
	 * @param list<array<string, mixed>> $rows     Webhook rows.
	 * @param list<string|null>          $statuses One last status per row (null = no delivery yet).
	 */
	private function prime_list( array $rows, array $statuses = [], ?array $editing = null ): void {
		$this->wpdb->queue( 'get_results', $rows );
		$this->wpdb->queue( 'get_var', count( $rows ) );
		$last = [];
		foreach ( $statuses as $i => $status ) {
			if ( null !== $status ) {
				$last[] = [ 'webhook_id' => (string) $rows[ $i ]['id'], 'status' => $status ];
			}
		}
		$this->wpdb->queue( 'get_results', $last );
		if ( null !== $editing ) {
			$this->wpdb->queue( 'get_row', $editing );
		}
	}

	private function redirect_of( callable $fn ): string {
		$exit = bfag_test_catch_exit( $fn );
		$this->assertNotNull( $exit, 'the handler must end in a redirect' );
		$this->assertSame( 'redirect', $exit->kind );
		return (string) $exit->payload;
	}

	public function test_tab_and_actions(): void {
		$page = $this->page();
		$this->assertSame( 'webhooks', $page->tab() );
		$this->assertSame( [ 'save_webhook', 'delete_webhook', 'toggle_webhook', 'test_webhook' ], $page->actions() );
	}

	public function test_the_list_shows_host_label_event_preset_and_last_status_and_never_the_secret_or_credentials(): void {
		$this->prime_list( [ $this->webhook( 1, 1, 'slack' ), $this->webhook( 2, 0 ) ], [ 'failed', null ] );
		$html = bfag_test_render( [ $this->page(), 'render' ] );

		$this->assertStringContainsString( 'CRM &lt;sync&gt;', $html );
		$this->assertStringContainsString( '<code>hooks.example.net</code>', $html );
		$this->assertStringNotContainsString( 'p4ss', $html, 'credentials never rendered' );
		$this->assertStringNotContainsString( 'secret-token', $html, 'the path never rendered' );
		$this->assertStringNotContainsString( self::SECRET, $html, 'the signing secret never rendered' );
		$this->assertStringContainsString( 'Post published', $html, 'event label, not key' );
		$this->assertStringContainsString( '<td>slack</td>', $html, 'no presets registered: the slug' );
		$this->assertStringContainsString( 'bfag-badge--failed">failed</a>', $html );
		$this->assertStringContainsString( 'view=deliveries&amp;webhook_id=1', $html );
		$this->assertStringContainsString( 'data-bfag-toggle="webhook" data-bfag-id="2" data-bfag-enabled="0"', $html );
		$this->assertStringContainsString( 'bfag_action=test_webhook&amp;id=1&amp;_wpnonce=nonce-bfag_test_webhook', $html );
		$this->assertStringContainsString( 'bfag_action=delete_webhook&amp;id=1&amp;_wpnonce=nonce-bfag_delete_webhook', $html );
		$this->assertStringContainsString( '2 of 3', $html );
		$this->assertStringContainsString( 'name="bfag_action" value="save_webhook"', $html, 'add form under the limit' );
		$this->assertStringContainsString( 'name="_wpnonce" value="nonce-bfag_save_webhook"', $html );
		$this->assertStringNotContainsString( 'name="bfag[preset]"', $html, 'no presets, no select' );
		$this->assertStringNotContainsString( 'name="bfag[max_retries]"', $html, 'free: no retries field' );
		$this->assertStringContainsString( '<option value="post.published"', $html );
		$this->assertStringContainsString( '<optgroup label="Content">', $html, 'F17: group label through EventRegistry::group_label()' );
		$this->assertStringContainsString( '<caption class="screen-reader-text">Webhooks</caption>', $html );
		$this->assertStringContainsString( '<th scope="col">Last delivery</th>', $html );
		$this->assertStringContainsString( 'role="button" aria-pressed="false" title="Click to enable this webhook"', $html );
	}

	/**
	 * P8: rendering five webhooks issues one deliveries query for their
	 * last statuses, not one per row.
	 */
	public function test_rendering_five_webhooks_issues_one_deliveries_query_for_the_last_statuses(): void {
		add_filter( 'bfag_webhook_limit', static fn(): int => 10 );
		$rows = [];
		for ( $id = 1; $id <= 5; $id++ ) {
			$rows[] = $this->webhook( $id );
		}
		$this->prime_list( $rows, [ 'failed', null, 'success', 'pending', 'failed' ] );

		$html = bfag_test_render( [ $this->page(), 'render' ] );

		$deliveries = $this->wpdb->queries_matching( 'bfag_webhook_deliveries' );
		$this->assertCount( 1, $deliveries );
		$this->assertSame( "SELECT webhook_id, status FROM bfw_bfag_webhook_deliveries WHERE id IN ( SELECT MAX(id) FROM bfw_bfag_webhook_deliveries WHERE webhook_id IN (1,2,3,4,5) AND direction = 'out' GROUP BY webhook_id )", $deliveries[0] );
		$this->assertSame( 2, substr_count( $html, 'bfag-badge--failed">failed</a>' ) );
		$this->assertSame( 1, substr_count( $html, 'bfag-badge--success">success</a>' ) );
		$this->assertSame( 1, substr_count( $html, 'bfag-badge--pending">pending</a>' ) );
		$this->assertSame( 0, substr_count( $html, 'bfag-badge--">' ), 'the row with no delivery yet shows no status badge, not an empty one' );
		$this->assertSame( 4, preg_match_all( '/class="bfag-badge bfag-badge--(failed|success|pending)">/', $html ), 'four status badges for five rows' );
	}

	public function test_at_the_limit_the_form_gives_way_to_the_upsell_line(): void {
		$this->prime_list( [ $this->webhook( 1 ), $this->webhook( 2 ), $this->webhook( 3 ) ], [ null, null, null ] );
		$html = bfag_test_render( [ $this->page(), 'render' ] );
		$this->assertStringContainsString( 'The free plugin allows 3 webhooks — Pro allows up to 500', $html );
		$this->assertStringNotContainsString( 'value="save_webhook"', $html );
	}

	public function test_editing_prefills_the_form_without_the_secret_and_fires_the_form_fields_hook(): void {
		$_GET = [
			'action' => 'edit',
			'id'     => '1',
		];
		$seen = null;
		add_action(
			'bfag_webhook_form_fields',
			static function ( array $webhook ) use ( &$seen ): void {
				$seen = $webhook;
				echo '<tr><td>EXTRA-ROW</td></tr>';
			}
		);
		add_filter( 'bfag_webhook_presets', static fn(): array => [ 'slack' => 'Slack' ] );
		add_filter( 'bfag_is_pro', '__return_true' );
		$this->prime_list( [ $this->webhook( 1, 1, 'slack' ) ], [ null ], $this->webhook( 1, 1, 'slack' ) );

		$html = bfag_test_render( [ $this->page(), 'render' ] );

		$this->assertStringContainsString( 'Edit webhook', $html );
		$this->assertStringContainsString( 'name="id" value="1"', $html );
		$this->assertStringContainsString( 'value="https://hooks.example.net/path/secret-token"', $html, 'the admin edits the URL they entered' );
		$this->assertStringNotContainsString( self::SECRET, $html );
		$this->assertStringContainsString( 'name="bfag[secret]" class="regular-text code" maxlength="255" value=""', $html );
		$this->assertStringContainsString( 'name="bfag[regenerate_secret]"', $html );
		$this->assertStringContainsString( 'X-Api-Key: k-123', $html );
		$this->assertStringContainsString( 'name="bfag[preset]"', $html );
		$this->assertMatchesRegularExpression( '/<option value="slack"\s+selected="selected">Slack/', $html );
		// U-14: with a preset chosen the URL is optional; the line says what happens if the preset goes.
		$this->assertMatchesRegularExpression( '/<input type="url" id="bfag-wh-url" name="bfag\[url\]" class="large-text code" data-bfag-preset-url value=/', $html, 'not required with a preset' );
		$this->assertStringContainsString( 'Optional with a preset. If you remove the preset later, deliveries go to this URL as plain signed JSON.', $html );
		$this->assertStringContainsString( 'name="bfag[max_retries]"', $html );
		$this->assertStringContainsString( 'value="5"', $html );
		$this->assertStringContainsString( 'Automatic retries after a failed delivery (Pro). 0 = none.', $html, 'U-23: Max retries help text' );
		$this->assertStringContainsString( 'EXTRA-ROW', $html );
		$this->assertTrue( strpos( $html, 'EXTRA-ROW' ) > strpos( $html, '<form' ) && strpos( $html, 'EXTRA-ROW' ) < strrpos( $html, '</table>' ), 'inside the form table' );
		$this->assertIsArray( $seen );
		$this->assertSame( 1, $seen['id'] );
		$this->assertArrayNotHasKey( 'secret', $seen, 'the hook never sees the secret' );
	}

	public function test_the_delivery_log_sub_view_shows_badges_truncation_and_retry(): void {
		$_GET = [ 'view' => 'deliveries' ];
		update_option( 'date_format', 'd/m/Y' );
		update_option( 'time_format', 'g:i a' );
		$this->wpdb->queue( 'get_results', [ $this->webhook( 1 ) ] );
		$this->wpdb->queue( 'get_results', [ $this->delivery( 10, 'failed' ), $this->delivery( 11, 'success', 1 ), $this->delivery( 12, 'pending', 0, 'in' ), $this->delivery( 13, 'success' ), $this->delivery( 14, 'retrying' ), $this->delivery( 15, 'pending' ) ] );

		$html = bfag_test_render( [ $this->page(), 'render' ] );

		// U-10: Retry only where it means something.
		$this->assertStringNotContainsString( 'data-bfag-retry="13"', $html, 'a successful delivery is not offered a Retry' );
		$this->assertStringContainsString( 'data-bfag-retry="14"', $html, 'a Pro `retrying` row is' );
		$this->assertStringNotContainsString( 'data-bfag-retry="15"', $html, 'an in-flight pending row is not' );
		// U-12: every timestamp through Page::format_datetime(), labelled as site time.
		$this->assertStringContainsString( '<th scope="col">Created (site time)</th>', $html );
		$this->assertStringContainsString( '<th scope="col">Delivered (site time)</th>', $html );
		$this->assertStringContainsString( '<td>' . \BlueFairy\ApiGateway\Admin\Page::format_datetime( '2026-01-01 00:00:00' ) . '</td>', $html );
		$this->assertStringContainsString( '<td>01/01/2026 12:00 am</td>', $html );
		$this->assertStringNotContainsString( '2026-01-01 00:00:00', $html, 'the raw GMT string never reaches the page' );
		$this->assertStringContainsString( '<caption class="screen-reader-text">Delivery log, newest first</caption>', $html );

		$this->assertStringContainsString( 'Delivery log', $html );
		$this->assertStringNotContainsString( 'value="save_webhook"', $html, 'no form in the log view' );
		$this->assertStringContainsString( 'bfag-badge--failed" data-bfag-delivery-status>failed</span>', $html );
		$this->assertStringContainsString( 'bfag-badge--success" data-bfag-delivery-status>success</span>', $html );
		$this->assertStringContainsString( 'data-bfag-retry="10"', $html );
		$this->assertStringNotContainsString( 'data-bfag-retry="11"', $html, 'a truncated payload cannot be retried' );
		$this->assertStringContainsString( '>truncated</span>', $html );
		// NF-20: each response block names its row.
		$this->assertStringContainsString( 'Response to delivery #10', $html );
		$this->assertStringContainsString( 'Response to delivery #13', $html );
		$this->assertStringNotContainsString( 'Response to delivery #15', $html, 'an unfinished row has no response' );
		// NF-06: a retrying row without a scheduled time announces none.
		$this->assertStringNotContainsString( 'next attempt', $html );
		$this->assertStringNotContainsString( 'data-bfag-retry="12"', $html, 'inbound rows are not resent' );
		$this->assertStringContainsString( 'CRM &lt;sync&gt;', $html );
		$this->assertStringNotContainsString( self::SECRET, $html );
		$this->assertStringContainsString( "( 0 = 0 OR webhook_id = 0 ) AND ( '' = '' OR status = '' ) AND ( '' = '' OR direction = '' ) ORDER BY created_at DESC, id DESC LIMIT 50", $this->wpdb->queries_matching( 'bfag_webhook_deliveries' )[0] );
	}

	/**
	 * U-12: the helper itself — site-local output in the site's formats.
	 */
	/**
	 * NF-06: the readme promises the next attempt time under a retrying
	 * delivery; it is printed in site time, for `retrying` rows only.
	 */
	public function test_a_retrying_delivery_shows_its_next_attempt_in_site_time(): void {
		$_GET = [ 'view' => 'deliveries' ];
		update_option( 'date_format', 'd/m/Y' );
		update_option( 'time_format', 'g:i a' );
		$retrying = $this->delivery( 14, 'retrying' );
		$failed   = $this->delivery( 10, 'failed' );
		$retrying['next_retry_at'] = '2026-01-03 05:00:00';
		$failed['next_retry_at']   = '2026-01-03 05:00:00';
		$this->wpdb->queue( 'get_results', [ $this->webhook( 1 ) ] );
		$this->wpdb->queue( 'get_results', [ $retrying, $failed ] );

		$html = bfag_test_render( [ $this->page(), 'render' ] );

		$this->assertSame( 1, substr_count( $html, 'next attempt' ), 'under the retrying badge only, not the failed row' );
		$this->assertMatchesRegularExpression( '/data-bfag-delivery-status>retrying<\/span>\s*<span class="bfag-next-attempt description">\s*next attempt 03\/01\/2026 5:00 am\s*<\/span>/', $html, 'directly under the retrying badge, in site time and site formats' );
		$this->assertDoesNotMatchRegularExpression( '/data-bfag-delivery-status>failed<\/span>\s*<span class="bfag-next-attempt/', $html, 'a failed row with a stale next_retry_at announces nothing' );
		$this->assertStringNotContainsString( '2026-01-03 05:00:00', $html, 'the raw GMT string never reaches the page' );
	}

	/**
	 * NF-21: the Preset column shows the label an add-on registered for
	 * the slug, and the slug itself when the preset is no longer known.
	 */
	public function test_the_preset_column_shows_the_registered_label_or_the_slug(): void {
		add_filter( 'bfag_webhook_presets', static fn(): array => [ 'slack' => 'Slack (incoming webhook)' ] );
		$this->prime_list( [ $this->webhook( 1, 1, 'slack' ), $this->webhook( 2, 1, 'legacy' ), $this->webhook( 3, 1, '' ) ], [ null, null, null ] );
		$html = bfag_test_render( [ $this->page(), 'render' ] );
		$this->assertStringContainsString( '<td>Slack (incoming webhook)</td>', $html, 'the label' );
		$this->assertStringNotContainsString( '<td>slack</td>', $html );
		$this->assertStringContainsString( '<td>legacy</td>', $html, 'an unknown slug is shown as is' );
		$this->assertStringContainsString( '<td>—</td>', $html, 'no preset' );
	}

	/**
	 * U-14: without a preset the URL is required (server and form).
	 */
	public function test_the_url_is_required_only_when_no_preset_is_chosen(): void {
		add_filter( 'bfag_webhook_presets', static fn(): array => [ 'slack' => 'Slack' ] );
		$this->prime_list( [], [] );
		$html = bfag_test_render( [ $this->page(), 'render' ] );
		$this->assertStringContainsString( 'name="bfag[url]" class="large-text code" data-bfag-preset-url required value=""', $html, 'add form, no preset: required' );
		$this->assertStringContainsString( 'If you remove the preset later, deliveries go to this URL as plain signed JSON.', $html );

		unset( $GLOBALS['bfag_test_hooks']['bfag_webhook_presets'] );
		$this->prime_list( [], [] );
		$html = bfag_test_render( [ $this->page(), 'render' ] );
		$this->assertStringContainsString( 'data-bfag-preset-url required value=""', $html, 'no presets at all: required' );
		$this->assertStringNotContainsString( 'If you remove the preset later', $html, 'no presets: no line about them' );
	}

	public function test_a_blank_url_saves_with_a_preset_and_is_refused_without_one(): void {
		$this->wpdb->always( 'get_var', '0' );
		bfag_test_admin_request(
			'save_webhook',
			[
				'label'  => 'x',
				'url'    => '',
				'event'  => 'post.published',
				'preset' => 'slack',
			]
		);
		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=webhook_secret_shown', $location, 'saved' );
		$inserts = $this->wpdb->calls_to( 'insert' );
		$this->assertCount( 1, $inserts );
		$this->assertSame( '', $inserts[0]['data']['url'] );
		$this->assertSame( 'slack', $inserts[0]['data']['preset'] );

		bfag_test_admin_request(
			'save_webhook',
			[
				'label'  => 'x',
				'url'    => '',
				'event'  => 'post.published',
				'preset' => '',
			]
		);
		$this->assertStringEndsWith( '&bfag_notice=webhook_url_invalid', $this->redirect_of( [ $this->page(), 'handle_actions' ] ), 'no preset: the URL is needed' );
		$this->assertCount( 1, $this->wpdb->calls_to( 'insert' ), 'nothing more inserted' );
	}

	public function test_format_datetime_uses_the_site_formats_and_timezone_conversion(): void {
		$this->assertSame( '2026-10-01 12:00', \BlueFairy\ApiGateway\Admin\Page::format_datetime( '2026-10-01 12:00:00' ), 'defaults: Y-m-d H:i' );
		update_option( 'date_format', 'F j, Y' );
		update_option( 'time_format', 'g:i a' );
		$this->assertSame( 'October 1, 2026 12:00 pm', \BlueFairy\ApiGateway\Admin\Page::format_datetime( '2026-10-01 12:00:00' ) );

		// TF1: a non-UTC site. 12:00 GMT on 1 October is 07:00 in Chicago (CDT, UTC−5);
		// rendering the GMT value as if it were local would print 12:00 pm.
		update_option( 'timezone_string', 'America/Chicago' );
		$this->assertSame( 'October 1, 2026 7:00 am', \BlueFairy\ApiGateway\Admin\Page::format_datetime( '2026-10-01 12:00:00' ) );
		$this->assertSame( 'January 15, 2026 6:00 am', \BlueFairy\ApiGateway\Admin\Page::format_datetime( '2026-01-15 12:00:00' ), 'and CST (UTC−6) in January' );
		update_option( 'timezone_string', 'Asia/Tokyo' );
		$this->assertSame( 'October 1, 2026 9:00 pm', \BlueFairy\ApiGateway\Admin\Page::format_datetime( '2026-10-01 12:00:00' ), 'east of UTC' );
	}

	public function test_the_delivery_log_can_be_filtered_to_one_webhook(): void {
		$_GET = [
			'view'       => 'deliveries',
			'webhook_id' => '1',
		];
		$this->wpdb->queue( 'get_results', [ $this->webhook( 1 ) ] );
		$this->wpdb->queue( 'get_results', [] );
		$html = bfag_test_render( [ $this->page(), 'render' ] );
		$this->assertStringContainsString( 'No deliveries yet.', $html );
		$this->assertStringContainsString( 'All webhooks', $html );
		$this->assertStringContainsString( 'webhook_id = 1', $this->wpdb->queries_matching( 'bfag_webhook_deliveries' )[0] );
	}

	public function test_save_creates_a_webhook_with_headers_from_lines_and_runs_the_sanitize_filter(): void {
		$filter_args = null;
		add_filter(
			'bfag_webhook_sanitize',
			static function ( array $data, array $raw ) use ( &$filter_args ): array {
				$filter_args    = [ $data, $raw ];
				$data['preset'] = 'from-filter';
				return $data;
			},
			10,
			2
		);
		bfag_test_admin_request(
			'save_webhook',
			[
				'label'   => 'CRM',
				'url'     => 'https://hooks.example.net/in',
				'event'   => 'post.published',
				'secret'  => '',
				'headers' => "X-Api-Key: abc\nbad line\nX-Trace : 1\n: nothing",
				'enabled' => '1',
			]
		);
		$_POST['bfagp_extra'] = 'pro-field';
		$_REQUEST             = array_merge( $_REQUEST, $_POST );
		$this->wpdb->queue( 'get_var', 0 );
		$this->wpdb->queue( 'get_var', 0 );

		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );

		$this->assertSame( 'https://example.com/wp-admin/options-general.php?page=bfag-gateway&tab=webhooks&bfag_notice=webhook_secret_shown', $location, 'a generated secret is announced so the tab can show it once' );
		$insert = $this->wpdb->calls_to( 'insert' )[0];
		$this->assertSame( 'bfw_bfag_webhooks', $insert['table'] );
		$this->assertSame( 'CRM', $insert['data']['label'] );
		$this->assertSame( 'https://hooks.example.net/in', $insert['data']['url'] );
		$this->assertSame( 'post.published', $insert['data']['event'] );
		$this->assertSame(
			[
				'X-Api-Key' => 'abc',
				'X-Trace'   => '1',
			],
			json_decode( (string) $insert['data']['headers'], true )
		);
		$this->assertSame( 'from-filter', $insert['data']['preset'], 'the filter result is what is stored' );
		$this->assertSame( 1, $insert['data']['enabled'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $insert['data']['secret'], 'blank secret on create: generated' );
		$this->assertIsArray( $filter_args );
		$this->assertArrayNotHasKey( 'secret', $filter_args[0], 'no secret key when the field is blank' );
		$this->assertSame( 'pro-field', $filter_args[1]['bfagp_extra'], 'the whole POST reaches the add-on' );
		$this->assertSame( 'CRM', $filter_args[1]['bfag']['label'] );
	}

	public function test_an_unknown_event_is_refused_before_the_repository(): void {
		bfag_test_admin_request(
			'save_webhook',
			[
				'label' => 'x',
				'url'   => 'https://hooks.example.net/in',
				'event' => 'post.exploded',
			]
		);
		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=webhook_event_invalid', $location );
		$this->assertSame( [], $this->wpdb->calls );

		add_filter(
			'bfag_webhook_events',
			static function ( array $events ): array {
				$events['form.submitted'] = [
					'hook'          => 'bfag_form',
					'label'         => 'Form',
					'accepted_args' => 1,
					'group'         => 'Forms',
				];
				return $events;
			}
		);
		bfag_test_admin_request(
			'save_webhook',
			[
				'label' => 'x',
				'url'   => 'https://hooks.example.net/in',
				'event' => 'form.submitted',
			]
		);
		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=webhook_secret_shown', $location, 'an event an add-on registered is accepted (blank secret: generated and announced)' );
	}

	public function test_an_http_url_is_a_notice_not_a_row(): void {
		bfag_test_admin_request(
			'save_webhook',
			[
				'label' => 'x',
				'url'   => 'http://hooks.example.net/in',
				'event' => 'post.published',
			]
		);
		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=webhook_url_invalid', $location, 'U-11: the URL notice, not the catch-all' );
		$this->assertSame( [], $this->wpdb->calls_to( 'insert' ) );

		foreach ( [ 'https://localhost/in', 'https://127.0.0.1/in', 'https://10.0.0.5/in', 'https://hooks.example.net:8443/in' ] as $url ) {
			bfag_test_admin_request(
				'save_webhook',
				[
					'label' => 'x',
					'url'   => $url,
					'event' => 'post.published',
				]
			);
			$this->assertStringEndsWith( '&bfag_notice=webhook_url_invalid', $this->redirect_of( [ $this->page(), 'handle_actions' ] ), $url );
		}
		$this->assertSame( [], $this->wpdb->calls_to( 'insert' ) );
	}

	/**
	 * U-11: a missing label has its own notice, and a refused save keeps the
	 * typed values (never the secret) for one re-render of the form.
	 */
	public function test_a_failed_save_names_the_label_and_refills_the_form_once_without_the_secret(): void {
		bfag_test_admin_request(
			'save_webhook',
			[
				'label'   => '',
				'url'     => 'https://hooks.example.net/typed',
				'event'   => 'post.updated',
				'secret'  => 'typed-secret',
				'headers' => 'X-Typed: yes',
			]
		);
		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=webhook_label_invalid', $location );
		$parked = get_transient( 'bfag_form_' . get_current_user_id() . '_webhooks' );
		$this->assertIsArray( $parked, 'N-15: parked under the webhooks tab' );
		$this->assertFalse( get_transient( 'bfag_form_' . get_current_user_id() . '_cache' ), 'and not under another tab' );
		$this->assertArrayNotHasKey( 'secret', $parked, 'the secret is never parked' );

		$this->prime_list( [], [] );
		$html = bfag_test_render( [ $this->page(), 'render' ] );
		$this->assertStringContainsString( 'value="https://hooks.example.net/typed"', $html );
		$this->assertMatchesRegularExpression( '/<option value="post.updated"\s+selected="selected">/', $html );
		$this->assertStringContainsString( 'X-Typed: yes', $html );
		$this->assertStringNotContainsString( 'typed-secret', $html );

		$this->prime_list( [], [] );
		$html = bfag_test_render( [ $this->page(), 'render' ] );
		$this->assertStringNotContainsString( 'hooks.example.net/typed', $html, 'shown once' );
	}

	public function test_creating_past_the_limit_is_refused(): void {
		bfag_test_admin_request(
			'save_webhook',
			[
				'label' => 'x',
				'url'   => 'https://hooks.example.net/in',
				'event' => 'post.published',
			]
		);
		$this->wpdb->queue( 'get_var', 3 );
		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=webhook_limit', $location );
		$this->assertSame( [], $this->wpdb->calls_to( 'insert' ) );
	}

	public function test_update_keeps_the_secret_when_blank_replaces_it_when_given_and_regenerates_on_request(): void {
		bfag_test_admin_request(
			'save_webhook',
			[
				'label'   => 'Renamed',
				'url'     => 'https://hooks.example.net/in',
				'event'   => 'post.published',
				'secret'  => '',
				'headers' => '',
			],
			[ 'id' => '1' ]
		);
		$this->wpdb->queue( 'get_row', $this->webhook( 1 ) );
		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=webhook_saved', $location );
		$update = $this->wpdb->calls_to( 'update' )[0];
		$this->assertSame( [ 'id' => 1 ], $update['where'] );
		$this->assertArrayNotHasKey( 'secret', $update['data'], 'blank keeps the stored secret' );
		$this->assertSame( 'Renamed', $update['data']['label'] );
		$this->assertSame( 0, $update['data']['enabled'] );

		bfag_test_admin_request(
			'save_webhook',
			[
				'label'  => 'x',
				'url'    => 'https://hooks.example.net/in',
				'event'  => 'post.published',
				'secret' => 'my-own-secret',
			],
			[ 'id' => '1' ]
		);
		$this->wpdb->queue( 'get_row', $this->webhook( 1 ) );
		$this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertSame( 'my-own-secret', $this->wpdb->calls_to( 'update' )[1]['data']['secret'] );

		bfag_test_admin_request(
			'save_webhook',
			[
				'label'             => 'x',
				'url'               => 'https://hooks.example.net/in',
				'event'             => 'post.published',
				'secret'            => 'ignored-when-regenerating',
				'regenerate_secret' => '1',
			],
			[ 'id' => '1' ]
		);
		$this->wpdb->queue( 'get_row', $this->webhook( 1 ) );
		$this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$new = $this->wpdb->calls_to( 'update' )[2]['data']['secret'];
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $new );
		$this->assertNotSame( self::SECRET, $new );
	}

	public function test_max_retries_is_stored_only_with_pro(): void {
		$fields = [
			'label'       => 'x',
			'url'         => 'https://hooks.example.net/in',
			'event'       => 'post.published',
			'max_retries' => '7',
		];
		bfag_test_admin_request( 'save_webhook', $fields, [ 'id' => '1' ] );
		$this->wpdb->queue( 'get_row', $this->webhook( 1 ) );
		$this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertArrayNotHasKey( 'max_retries', $this->wpdb->calls_to( 'update' )[0]['data'] );

		add_filter( 'bfag_is_pro', '__return_true' );
		bfag_test_admin_request( 'save_webhook', $fields, [ 'id' => '1' ] );
		$this->wpdb->queue( 'get_row', $this->webhook( 1 ) );
		$this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertSame( 7, $this->wpdb->calls_to( 'update' )[1]['data']['max_retries'] );
	}

	public function test_update_of_an_unknown_id_is_not_found(): void {
		bfag_test_admin_request(
			'save_webhook',
			[
				'label' => 'x',
				'url'   => 'https://hooks.example.net/in',
				'event' => 'post.published',
			],
			[ 'id' => '9' ]
		);
		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=webhook_not_found', $location );
		$this->assertSame( [], $this->wpdb->calls_to( 'update' ) );
	}

	public function test_delete_and_toggle(): void {
		bfag_test_admin_request( 'delete_webhook', [], [ 'id' => '1' ] );
		$this->wpdb->queue( 'get_row', $this->webhook( 1 ) );
		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=webhook_deleted', $location );
		$this->assertSame( [ 'id' => 1 ], $this->wpdb->calls_to( 'delete' )[0]['where'] );
		$this->assertNotEmpty( $this->wpdb->queries_matching( 'DELETE FROM bfw_bfag_webhook_deliveries WHERE webhook_id = 1' ), 'deliveries go with it' );

		bfag_test_admin_request( 'delete_webhook', [], [ 'id' => '1' ] );
		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=webhook_not_found', $location );

		bfag_test_admin_request(
			'toggle_webhook',
			[],
			[
				'id'      => '1',
				'enabled' => '0',
			]
		);
		$this->wpdb->queue( 'get_row', $this->webhook( 1 ) );
		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=webhook_toggled', $location );
		$this->assertSame( [ 'enabled' => 0 ], $this->wpdb->calls_to( 'update' )[0]['data'] );
	}

	public function test_send_test_dispatches_a_signed_test_payload_and_reports_the_outcome(): void {
		bfag_test_admin_request( 'test_webhook', [], [ 'id' => '1' ] );
		$this->wpdb->queue( 'get_row', $this->webhook( 1 ) );
		bfag_test_queue_http( 204 );

		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );

		$this->assertStringEndsWith( '&bfag_notice=webhook_test_ok', $location );
		$this->assertCount( 1, $GLOBALS['bfag_test_http_calls'] );
		$call = $GLOBALS['bfag_test_http_calls'][0];
		$this->assertSame( 'https://hooks.example.net/path/secret-token', $call['url'] );
		$body = json_decode( (string) $call['args']['body'], true );
		$this->assertSame( 'test', $body['event'] );
		$this->assertSame( 'https://example.com', $body['site'] );
		$this->assertSame( 1, $body['object']['webhook_id'] );
		$this->assertSame( 'test', $call['args']['headers']['X-BF-Event'] );
		$this->assertStringStartsWith( 'sha256=', $call['args']['headers']['X-BF-Signature'] );
		$this->assertSame( 'test', $this->wpdb->calls_to( 'insert' )[0]['data']['event'], 'logged as a delivery' );

		bfag_test_admin_request( 'test_webhook', [], [ 'id' => '1' ] );
		$this->wpdb->queue( 'get_row', $this->webhook( 1 ) );
		bfag_test_queue_http_error();
		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=webhook_test_failed&bfag_http=none', $location, 'the notice carries the status: none for a transport error' );

		bfag_test_admin_request( 'test_webhook', [], [ 'id' => '5' ] );
		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=webhook_not_found', $location );
		$this->assertCount( 2, $GLOBALS['bfag_test_http_calls'], 'nothing sent for an unknown id' );
	}

	public function test_header_lines_round_trip(): void {
		$page = $this->page();
		$this->assertSame(
			[
				'X-A' => 'one: with colon',
				'X-B' => '',
			],
			$page->lines_to_headers( "X-A: one: with colon\r\nX-B:\n\nNo Colon Here\nBad Name!: v" )
		);
		$this->assertSame( "X-A: 1\nX-B: 2", $page->headers_to_lines( [ 'X-A' => '1', 'X-B' => '2' ] ) );
		$this->assertSame( [], $page->lines_to_headers( '' ) );
	}

	public function test_a_bad_nonce_or_missing_capability_dies_with_403(): void {
		bfag_test_admin_request( 'delete_webhook', [], [ 'id' => '1' ], false );
		$exit = bfag_test_catch_exit( [ $this->page(), 'handle_actions' ] );
		$this->assertSame( [ 'die', 403 ], [ $exit->kind, $exit->status ] );

		$GLOBALS['bfag_test_caps'] = [];
		bfag_test_admin_request( 'test_webhook', [], [ 'id' => '1' ] );
		$exit = bfag_test_catch_exit( [ $this->page(), 'handle_actions' ] );
		$this->assertSame( [ 'die', 403 ], [ $exit->kind, $exit->status ] );
		$this->assertSame( [], $this->wpdb->calls );
		$this->assertSame( [], $GLOBALS['bfag_test_http_calls'] );
	}
}
