<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Admin;

use BlueFairy\ApiGateway\Admin\DashboardPage;
use BlueFairy\ApiGateway\Admin\KpiCron;
use BlueFairy\ApiGateway\Cache\CacheRuleRepository;
use BlueFairy\ApiGateway\Cache\CacheStatsRepository;
use BlueFairy\ApiGateway\Capabilities;
use BlueFairy\ApiGateway\RateLimit\HitCounter;
use BlueFairy\ApiGateway\RateLimit\RateLimitRuleRepository;
use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Tests\TestCase;
use BlueFairy\ApiGateway\Webhook\DeliveryRepository;
use BlueFairy\ApiGateway\Webhook\WebhookRepository;

final class DashboardPageTest extends TestCase {

	private function page(): DashboardPage {
		$settings   = new Settings();
		$deliveries = new DeliveryRepository( $settings );
		$hits       = new HitCounter();
		return new DashboardPage( new CacheRuleRepository(), new RateLimitRuleRepository(), new WebhookRepository(), $deliveries, new KpiCron( new CacheStatsRepository(), $deliveries, $hits ) );
	}

	/**
	 * Script render(): webhooks all() → get_results, failed recent() → get_results, three counts → get_var.
	 * (P10: the hits-table row count comes from the KPI transient, never a live COUNT(*).)
	 *
	 * @param list<array<string, mixed>> $failed Failed delivery rows.
	 * @param list<int>                  $counts cache rules, rate-limit rules, webhooks.
	 */
	private function prime( array $failed, array $counts = [ 1, 1, 2 ] ): void {
		$this->wpdb->queue(
			'get_results',
			[
				[
					'id'    => 1,
					'label' => 'CRM <sync>',
					'url'   => 'https://h.example.net/x',
					'event' => 'post.published',
				],
			]
		);
		$this->wpdb->queue( 'get_results', $failed );
		foreach ( $counts as $n ) {
			$this->wpdb->queue( 'get_var', $n );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function delivery( int $id, int $truncated = 0 ): array {
		return [
			'id'            => $id,
			'webhook_id'    => 1,
			'direction'     => 'out',
			'event'         => 'post.published',
			'payload'       => '{}',
			'truncated'     => $truncated,
			'status'        => 'failed',
			'http_status'   => null,
			'response_body' => 'timeout',
			'attempt'       => 3,
			'next_retry_at' => null,
			'delivered_at'  => null,
			'created_at'    => '2026-01-01 00:00:00',
		];
	}

	public function test_tab_has_no_form_actions_and_ignores_every_request(): void {
		bfag_test_grant( Capabilities::MANAGE );
		$page = $this->page();
		$this->assertSame( 'dashboard', $page->tab() );
		$this->assertSame( [], $page->actions() );
		bfag_test_admin_request( 'save_settings' );
		$this->assertNull( bfag_test_catch_exit( [ $page, 'handle_actions' ] ) );
		$this->assertSame( [], $this->wpdb->calls );
	}

	public function test_cold_kpis_render_as_dashes_with_the_cold_explanation(): void {
		$this->prime( [] );
		$html = bfag_test_render( [ $this->page(), 'render' ] );
		$this->assertSame( 2, substr_count( $html, 'data-bfag-format="rate">—<' ) );
		// U-19: free: the third card says what it would show, instead of a permanent 0.
		$this->assertStringContainsString( '<span class="bfag-kpi__label">Blocked (Pro)</span>', $html );
		$this->assertStringContainsString( '<span class="bfag-kpi__value">—</span>', $html );
		$this->assertStringContainsString( 'Blocks need the Pro add-on', $html );
		$this->assertDoesNotMatchRegularExpression( '/\bbans?\b|\bbanned\b/i', $html, 'NF-16: the base UI says block, never ban' );
		$this->assertStringNotContainsString( 'data-bfag-kpi="blocked_today"', $html );
		$this->assertStringNotContainsString( 'Blocked today', $html );
		$this->assertStringContainsString( 'A monitoring tool authenticated with an Application Password (or, with Pro, an API key) can read the same figures here:', $html, 'U-23: status-endpoint copy' );
		$this->assertStringNotContainsString( 'A monitor logged in with the gateway capability', $html );
		$this->assertStringContainsString( '<caption class="screen-reader-text">At a glance</caption>', $html );
		$this->assertStringContainsString( 'Not computed yet', $html );
		$this->assertStringContainsString( 'data-bfag-refresh', $html );
		$this->assertStringContainsString( 'No failed deliveries.', $html );
		$this->assertStringContainsString( '<th>Rows in the rate-limit hits table</th><td><span data-bfag-kpi="hits_table_rows">—</span></td>', $html, 'cold: a dash, not a live count' );
		$this->assertSame( [], $this->wpdb->queries_matching( 'bfag_rate_limit_hits' ) );
		$this->assertStringContainsString( 'https://example.com/wp-json/bfag/v1/status', $html );
		$this->assertStringContainsString( 'data-bfag-copy="https://example.com/wp-json/bfag/v1/status"', $html );
	}

	public function test_warm_kpis_counts_and_failed_deliveries_render(): void {
		bfag_test_set_time( 1_700_000_000 );
		set_transient(
			'bfag_kpis',
			[
				'cache_hit_rate'       => 0.756,
				'cache_hits'           => 1234,
				'cache_misses'         => 400,
				'webhook_success_rate' => 0.5,
				'webhook_failed'       => 2,
				'webhook_total'        => 4,
				'blocked_today'        => 9,
				'hits_table_rows'      => 345,
				'computed_at'          => 1_700_000_000 - 120,
			],
			360
		);
		$this->prime( [ $this->delivery( 10 ), $this->delivery( 11, 1 ) ], [ 1, 2, 3 ] );

		$html = bfag_test_render( [ $this->page(), 'render' ] );

		$this->assertStringContainsString( 'data-bfag-kpi="cache_hit_rate" data-bfag-format="rate">75.6%<', $html );
		$this->assertStringContainsString( 'data-bfag-kpi="cache_hits">1,234<', $html );
		$this->assertStringContainsString( 'data-bfag-kpi="webhook_success_rate" data-bfag-format="rate">50.0%<', $html );
		$this->assertStringNotContainsString( 'data-bfag-kpi="blocked_today">9<', $html, 'free: the ban count is not a free figure' );
		$this->assertStringContainsString( 'Blocked (Pro)', $html );
		$this->assertMatchesRegularExpression( '/Updated [^<]+ ago/', $html, 'TF6: the human time, whatever core phrases it as' );
		$this->assertStringNotContainsString( 'Updated just now', $html );
		$this->assertStringNotContainsString( 'Not computed yet', $html );

		$this->assertStringContainsString( 'CRM &lt;sync&gt;', $html );
		$this->assertStringContainsString( 'data-bfag-retry="10"', $html );
		$this->assertStringNotContainsString( 'data-bfag-retry="11"', $html );
		// U-12: the failed-delivery time goes through Page::format_datetime() and is labelled site time.
		$this->assertStringContainsString( '<th scope="col">When (site time)</th>', $html );
		$this->assertStringContainsString( '<td>' . \BlueFairy\ApiGateway\Admin\Page::format_datetime( '2026-01-01 00:00:00' ) . '</td>', $html );
		$this->assertStringNotContainsString( '2026-01-01 00:00:00', $html, 'the raw GMT string never reaches the page' );
		$this->assertStringContainsString( '<caption class="screen-reader-text">Recent failed deliveries</caption>', $html );
		$this->assertStringContainsString( 'Truncated — cannot retry', $html );
		$this->assertStringContainsString( 'view=deliveries', $html );
		$this->assertStringContainsString( "( 'failed' = '' OR status = 'failed' ) AND ( '' = '' OR direction = '' ) ORDER BY created_at DESC, id DESC LIMIT 10", $this->wpdb->queries_matching( 'bfag_webhook_deliveries' )[0] );

		$this->assertStringContainsString( '<th>Cache rules</th><td>1</td>', $html );
		$this->assertStringContainsString( '<th>Rate-limit rules</th><td>2</td>', $html );
		$this->assertStringContainsString( '<th>Webhooks</th><td>3</td>', $html );
		$this->assertStringContainsString( '<th>Rows in the rate-limit hits table</th><td><span data-bfag-kpi="hits_table_rows">345</span></td>', $html, 'from the KPI transient' );
		$this->assertSame( [], $this->wpdb->queries_matching( 'bfag_rate_limit_hits' ), 'P10: no live COUNT(*) of the hits table on a render' );
		$this->assertCount( 3, $this->wpdb->calls_to( 'get_var' ), 'exactly the three rule/webhook counts' );
	}

	/**
	 * D5: the dashboard offers Retry under the same rule as the delivery log
	 * — an outgoing row in AdminMenu::RETRYABLE_STATUSES that is not
	 * truncated. An inbound row (Pro's receiving endpoint) gets no button
	 * however it failed; a Pro `retrying` outgoing row does.
	 */
	public function test_retry_is_offered_only_on_retryable_outgoing_rows(): void {
		$inbound             = $this->delivery( 20 );
		$inbound['direction'] = 'in';
		$retrying            = $this->delivery( 21 );
		$retrying['status']  = 'retrying';
		$pending             = $this->delivery( 22 );
		$pending['status']   = 'pending';
		$this->prime( [ $this->delivery( 19 ), $inbound, $retrying, $pending ] );

		$html = bfag_test_render( [ $this->page(), 'render' ] );

		$this->assertStringContainsString( 'data-bfag-retry="19"', $html, 'control: a failed outgoing row is retryable' );
		$this->assertStringNotContainsString( 'data-bfag-retry="20"', $html, 'an inbound row is never retryable' );
		$this->assertStringContainsString( 'data-bfag-retry="21"', $html, 'a retrying outgoing row is (RETRYABLE_STATUSES)' );
		$this->assertStringNotContainsString( 'data-bfag-retry="22"', $html, 'an in-flight row is not' );
		$this->assertSame( 2, substr_count( $html, 'data-bfag-retry="' ), 'exactly the two retryable rows carry a button' );
		$this->assertStringContainsString( 'data-bfag-delivery="20"', $html, 'the inbound row is still listed' );
		$this->assertStringNotContainsString( 'Truncated — cannot retry', $html, 'none of these rows is truncated' );
	}

	/**
	 * Refresh rewrites every `[data-bfag-kpi]` element from the answer; the
	 * At a glance hits-table row must be one, or a free site (no Pro card,
	 * the only other `hits_table_rows` element) keeps "—" beside
	 * "Updated just now" until a reload (found by the Step 3b screenshots).
	 */
	public function test_the_at_a_glance_hits_row_is_a_refreshable_kpi_on_the_free_plugin(): void {
		$this->prime( [] );
		$html = bfag_test_render( [ $this->page(), 'render' ] );
		$this->assertStringNotContainsString( 'hits in the rate-limit log', $html, 'precondition: free, no Pro card' );
		$this->assertMatchesRegularExpression(
			'#Rows in the rate-limit hits table</th><td><span data-bfag-kpi="hits_table_rows">[^<]*</span></td>#',
			$html
		);
		$this->assertSame( 1, substr_count( $html, 'data-bfag-kpi="hits_table_rows"' ) );

		add_filter( 'bfag_is_pro', '__return_true' );
		$this->prime( [] );
		$html = bfag_test_render( [ $this->page(), 'render' ] );
		$this->assertSame( 2, substr_count( $html, 'data-bfag-kpi="hits_table_rows"' ), 'with Pro: the card and the row both refresh' );
	}

	/**
	 * U-19: with Pro the third card is the count of active bans, named
	 * "Active blocks" unless the KPI array carries a `blocked_label`.
	 */
	public function test_with_pro_the_third_card_is_the_active_ban_count(): void {
		add_filter( 'bfag_is_pro', '__return_true' );
		set_transient(
			'bfag_kpis',
			[
				'blocked_today'   => 9,
				'hits_table_rows' => 345,
				'computed_at'     => time(),
			],
			360
		);
		$this->prime( [] );
		$html = bfag_test_render( [ $this->page(), 'render' ] );
		$this->assertStringContainsString( '<span class="bfag-kpi__label" data-bfag-kpi-label="blocked">Active blocks</span>', $html, 'D5: the label is addressable so Refresh can rename it from blocked_label' );
		$this->assertStringContainsString( 'data-bfag-kpi="blocked_today">9<', $html );
		$this->assertStringNotContainsString( 'Blocked (Pro)', $html );
		$this->assertStringNotContainsString( 'Blocks need the Pro add-on', $html );
		$this->assertDoesNotMatchRegularExpression( '/\bbans?\b|\bbanned\b/i', $html, 'NF-16' );

		set_transient(
			'bfag_kpis',
			[
				'blocked_today' => 2,
				'blocked_label' => 'Banned <now>',
				'computed_at'   => time(),
			],
			360
		);
		$this->prime( [] );
		$html = bfag_test_render( [ $this->page(), 'render' ] );
		$this->assertStringContainsString( '<span class="bfag-kpi__label" data-bfag-kpi-label="blocked">Banned &lt;now&gt;</span>', $html, 'the KPI array may rename the card' );
		$this->assertStringNotContainsString( 'Active blocks', $html );
	}
}
