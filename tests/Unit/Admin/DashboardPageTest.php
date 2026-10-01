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
		return new DashboardPage( new CacheRuleRepository(), new RateLimitRuleRepository(), new WebhookRepository(), $deliveries, $hits, new KpiCron( new CacheStatsRepository(), $deliveries, $hits ) );
	}

	/**
	 * Script render(): webhooks all() → get_results, failed recent() → get_results, four counts → get_var.
	 *
	 * @param list<array<string, mixed>> $failed Failed delivery rows.
	 * @param list<int>                  $counts cache rules, rate-limit rules, webhooks, hits rows.
	 */
	private function prime( array $failed, array $counts = [ 1, 1, 2, 345 ] ): void {
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
		$this->assertSame( 3, substr_count( $html, 'data-bfag-format="rate">—<' ) + substr_count( $html, 'data-bfag-kpi="blocked_today">—<' ) );
		$this->assertStringContainsString( 'Not computed yet', $html );
		$this->assertStringContainsString( 'data-bfag-refresh', $html );
		$this->assertStringContainsString( 'No failed deliveries.', $html );
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
		$this->prime( [ $this->delivery( 10 ), $this->delivery( 11, 1 ) ], [ 1, 2, 3, 345 ] );

		$html = bfag_test_render( [ $this->page(), 'render' ] );

		$this->assertStringContainsString( 'data-bfag-kpi="cache_hit_rate" data-bfag-format="rate">75.6%<', $html );
		$this->assertStringContainsString( 'data-bfag-kpi="cache_hits">1,234<', $html );
		$this->assertStringContainsString( 'data-bfag-kpi="webhook_success_rate" data-bfag-format="rate">50.0%<', $html );
		$this->assertStringContainsString( 'data-bfag-kpi="blocked_today">9<', $html );
		$this->assertStringContainsString( 'Updated 120 secs ago', $html );
		$this->assertStringNotContainsString( 'Not computed yet', $html );

		$this->assertStringContainsString( 'CRM &lt;sync&gt;', $html );
		$this->assertStringContainsString( 'data-bfag-retry="10"', $html );
		$this->assertStringNotContainsString( 'data-bfag-retry="11"', $html );
		$this->assertStringContainsString( 'Truncated — cannot retry', $html );
		$this->assertStringContainsString( 'view=deliveries', $html );
		$this->assertStringContainsString( "status = 'failed' ORDER BY id DESC LIMIT 10", $this->wpdb->queries_matching( 'bfag_webhook_deliveries' )[0] );

		$this->assertStringContainsString( '<th>Cache rules</th><td>1</td>', $html );
		$this->assertStringContainsString( '<th>Rate-limit rules</th><td>2</td>', $html );
		$this->assertStringContainsString( '<th>Webhooks</th><td>3</td>', $html );
		$this->assertStringContainsString( '<th>Rows in the rate-limit hits table</th><td>345</td>', $html );
	}
}
