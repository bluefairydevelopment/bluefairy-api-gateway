<?php
/**
 * The edges of each tab's action handler that the per-tab tests did not
 * reach: a toggle of a row that is gone, a write the database refuses, an
 * action routed to a tab that does not own it, and a base action whose tab
 * an add-on removed (WPMAP-375).
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Admin;

use BlueFairy\ApiGateway\Admin\AdminMenu;
use BlueFairy\ApiGateway\Admin\CachePage;
use BlueFairy\ApiGateway\Admin\DashboardPage;
use BlueFairy\ApiGateway\Admin\KpiCron;
use BlueFairy\ApiGateway\Admin\Page;
use BlueFairy\ApiGateway\Admin\ProUpsell;
use BlueFairy\ApiGateway\Admin\RateLimitPage;
use BlueFairy\ApiGateway\Admin\SettingsPage;
use BlueFairy\ApiGateway\Admin\WebhookPage;
use BlueFairy\ApiGateway\Cache\CachePurger;
use BlueFairy\ApiGateway\Cache\CacheRuleRepository;
use BlueFairy\ApiGateway\Cache\CacheStatsRepository;
use BlueFairy\ApiGateway\Cache\CacheStore;
use BlueFairy\ApiGateway\Capabilities;
use BlueFairy\ApiGateway\RateLimit\HitCounter;
use BlueFairy\ApiGateway\RateLimit\RateLimitRuleRepository;
use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Tests\TestCase;
use BlueFairy\ApiGateway\Webhook\DeliveryRepository;
use BlueFairy\ApiGateway\Webhook\EventRegistry;
use BlueFairy\ApiGateway\Webhook\WebhookDispatcher;
use BlueFairy\ApiGateway\Webhook\WebhookRepository;

final class PageActionEdgesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		bfag_test_grant( Capabilities::MANAGE );
	}

	private function cache_page(): CachePage {
		return new CachePage( new CacheRuleRepository(), new CachePurger( new CacheStore(), new CacheRuleRepository() ), new Settings() );
	}

	private function rate_page(): RateLimitPage {
		return new RateLimitPage( new RateLimitRuleRepository(), new ProUpsell() );
	}

	private function webhook_page(): WebhookPage {
		$settings = new Settings();
		$delivs   = new DeliveryRepository( $settings );
		return new WebhookPage( new WebhookRepository(), $delivs, new WebhookDispatcher( $delivs, $settings ), new EventRegistry(), new ProUpsell() );
	}

	private function dashboard_page(): DashboardPage {
		$deliveries = new DeliveryRepository( new Settings() );
		return new DashboardPage( new CacheRuleRepository(), new RateLimitRuleRepository(), new WebhookRepository(), $deliveries, new KpiCron( new CacheStatsRepository(), $deliveries, new HitCounter() ) );
	}

	private function menu(): AdminMenu {
		$settings   = new Settings();
		$deliveries = new DeliveryRepository( $settings );
		return new AdminMenu(
			$settings,
			new CachePurger( new CacheStore(), new CacheRuleRepository() ),
			new CacheRuleRepository(),
			new RateLimitRuleRepository(),
			new WebhookRepository(),
			$deliveries,
			new WebhookDispatcher( $deliveries, $settings ),
			new EventRegistry(),
			new KpiCron( new CacheStatsRepository(), $deliveries, new HitCounter() )
		);
	}

	private function redirect_of( callable $fn ): string {
		$exit = bfag_test_catch_exit( $fn );
		$this->assertNotNull( $exit, 'the handler must end in a redirect' );
		$this->assertSame( 'redirect', $exit->kind );
		return (string) $exit->payload;
	}

	/**
	 * Call a tab's run_action() directly with an action handle_actions()
	 * would never route to it, and return where it redirects.
	 */
	private function stray_action_redirect( Page $page, string $action ): string {
		$run = new \ReflectionMethod( $page, 'run_action' );
		return $this->redirect_of( static fn() => $run->invoke( $page, $action, [], [] ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function cache_rule( int $id ): array {
		return [
			'id'          => $id,
			'label'       => 'All',
			'route_glob'  => '**',
			'ttl_seconds' => 60,
			'tags'        => '',
			'headers'     => null,
			'enabled'     => 1,
			'created_at'  => '2026-01-01 00:00:00',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function rate_rule( int $id ): array {
		return [
			'id'              => $id,
			'label'           => 'Global',
			'subject_type'    => 'ip',
			'route_glob'      => '**',
			'max_requests'    => 60,
			'window_seconds'  => 60,
			'penalty_seconds' => 0,
			'enabled'         => 1,
			'created_at'      => '2026-01-01 00:00:00',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function webhook_row( int $id ): array {
		return [
			'id'          => $id,
			'label'       => 'CRM',
			'url'         => 'https://hooks.example.net/in',
			'event'       => 'post.published',
			'secret'      => str_repeat( 'ab', 32 ),
			'headers'     => null,
			'preset'      => '',
			'enabled'     => 1,
			'max_retries' => 5,
			'created_at'  => '2026-01-01 00:00:00',
		];
	}

	public function test_toggling_a_row_that_is_gone_is_not_found_on_every_tab_and_writes_nothing(): void {
		$cases = [
			'cache'     => [ $this->cache_page(), 'toggle_cache_rule', 'cache_not_found' ],
			'ratelimit' => [ $this->rate_page(), 'toggle_rate_limit_rule', 'ratelimit_not_found' ],
			'webhooks'  => [ $this->webhook_page(), 'toggle_webhook', 'webhook_not_found' ],
		];
		foreach ( $cases as $tab => [ $page, $action, $notice ] ) {
			bfag_test_admin_request(
				$action,
				[],
				[
					'id'      => '42',
					'enabled' => '0',
				]
			);
			$location = $this->redirect_of( [ $page, 'handle_actions' ] );
			$this->assertSame( 'https://example.com/wp-admin/options-general.php?page=bfag-gateway&tab=' . $tab . '&bfag_notice=' . $notice, $location, $tab );
		}
		$this->assertSame( [], $this->wpdb->calls_to( 'update' ), 'nothing is toggled' );
	}

	public function test_an_action_routed_to_a_tab_that_does_not_own_it_redirects_with_error(): void {
		$this->assertStringEndsWith( '&tab=cache&bfag_notice=error', $this->stray_action_redirect( $this->cache_page(), 'save_webhook' ) );
		$this->assertStringEndsWith( '&tab=ratelimit&bfag_notice=error', $this->stray_action_redirect( $this->rate_page(), 'purge_cache' ) );
		$this->assertStringEndsWith( '&tab=webhooks&bfag_notice=error', $this->stray_action_redirect( $this->webhook_page(), 'purge_cache' ) );
		$this->assertStringEndsWith( '&tab=settings&bfag_notice=error', $this->stray_action_redirect( new SettingsPage( new Settings() ), 'purge_cache' ) );
		$this->assertStringEndsWith( '&tab=dashboard&bfag_notice=error', $this->stray_action_redirect( $this->dashboard_page(), 'purge_cache' ) );
		$this->assertSame( [], $this->wpdb->calls, 'no tab touched the database for an action it does not own' );
		$this->assertFalse( get_option( 'bfag_settings' ), 'settings were not saved for a stray action' );
	}

	public function test_a_cache_rule_update_the_database_refuses_is_cache_invalid_and_keeps_the_form(): void {
		bfag_test_admin_request(
			'save_cache_rule',
			[
				'label'      => 'Typed',
				'route_glob' => '**',
			],
			[ 'id' => '7' ]
		);
		$this->wpdb->queue( 'get_row', $this->cache_rule( 7 ) );
		$this->wpdb->queue( 'update', false );
		$location = $this->redirect_of( [ $this->cache_page(), 'handle_actions' ] );
		$this->assertStringContainsString( 'bfag_notice=cache_invalid', $location );
		$this->assertStringNotContainsString( 'cache_saved', $location );
		$this->assertCount( 1, $this->wpdb->calls_to( 'update' ) );
		$form = get_transient( 'bfag_form_' . get_current_user_id() . '_cache' );
		$this->assertIsArray( $form, 'what was typed is parked for the re-render' );
		$this->assertSame( 'Typed', $form['label'] ?? null );
	}

	public function test_a_rate_limit_rule_update_or_insert_the_database_refuses_is_ratelimit_invalid(): void {
		$fields = [
			'label'          => 'Typed',
			'subject_type'   => 'ip',
			'route_glob'     => '**',
			'max_requests'   => '5',
			'window_seconds' => '5',
		];
		bfag_test_admin_request( 'save_rate_limit_rule', $fields, [ 'id' => '2' ] );
		$this->wpdb->queue( 'get_row', $this->rate_rule( 2 ) );
		$this->wpdb->queue( 'update', false );
		$location = $this->redirect_of( [ $this->rate_page(), 'handle_actions' ] );
		$this->assertStringContainsString( 'bfag_notice=ratelimit_invalid', $location );
		$this->assertCount( 1, $this->wpdb->calls_to( 'update' ) );

		bfag_test_admin_request( 'save_rate_limit_rule', $fields );
		$this->wpdb->queue( 'get_var', 0 );
		$this->wpdb->queue( 'get_var', 0 );
		$this->wpdb->queue( 'insert', false );
		$location = $this->redirect_of( [ $this->rate_page(), 'handle_actions' ] );
		$this->assertStringContainsString( 'bfag_notice=ratelimit_invalid', $location );
		$this->assertStringNotContainsString( 'ratelimit_saved', $location );
		$this->assertCount( 1, $this->wpdb->calls_to( 'insert' ), 'the insert was tried once' );
	}

	public function test_a_webhook_update_or_insert_the_database_refuses_is_webhook_invalid(): void {
		$fields = [
			'label' => 'Typed',
			'url'   => 'https://hooks.example.net/in',
			'event' => 'post.published',
		];
		bfag_test_admin_request( 'save_webhook', $fields, [ 'id' => '1' ] );
		$this->wpdb->queue( 'get_row', $this->webhook_row( 1 ) );
		$this->wpdb->queue( 'update', false );
		$location = $this->redirect_of( [ $this->webhook_page(), 'handle_actions' ] );
		$this->assertStringContainsString( 'bfag_notice=webhook_invalid', $location );
		$this->assertCount( 1, $this->wpdb->calls_to( 'update' ) );

		bfag_test_admin_request( 'save_webhook', $fields );
		$this->wpdb->queue( 'get_var', 0 );
		$this->wpdb->queue( 'get_var', 0 );
		$this->wpdb->queue( 'insert', false );
		$location = $this->redirect_of( [ $this->webhook_page(), 'handle_actions' ] );
		$this->assertStringContainsString( 'bfag_notice=webhook_invalid', $location );
		$this->assertStringNotContainsString( 'webhook_secret_shown', $location, 'a secret that was never stored is not announced' );
		$this->assertCount( 1, $this->wpdb->calls_to( 'insert' ) );
	}

	public function test_a_base_action_whose_tab_an_addon_removed_is_neither_handled_nor_offered_to_the_addon(): void {
		add_filter(
			'bfag_admin_tabs',
			static function ( array $tabs ): array {
				unset( $tabs['cache'] );
				return $tabs;
			}
		);
		$fired = 0;
		add_action(
			'bfag_admin_action_purge_cache',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);
		$store  = new CacheStore();
		$before = $store->generation();

		bfag_test_admin_request( 'purge_cache' );
		$this->assertNull( bfag_test_catch_exit( [ $this->menu(), 'handle_actions' ] ), 'no tab handled it, so nothing redirects' );
		$this->assertSame( 0, $fired, 'a base action is never offered to the add-on, even with its tab gone' );
		$this->assertSame( $before, $store->generation(), 'and the cache was not purged' );
	}

	public function test_a_view_file_that_does_not_exist_renders_nothing(): void {
		$this->assertSame( '', bfag_test_render( static fn() => Page::include_view( 'no-such-view', [ 'x' => 1 ] ) ) );
		$this->assertNotSame( '', bfag_test_render( static fn() => Page::include_view( 'upsell', [ 'product_url' => 'https://example.com/pro', 'features' => [ 'One' ] ] ) ), 'control: a real view renders' );
	}
}
