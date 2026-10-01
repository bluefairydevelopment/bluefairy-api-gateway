<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Admin;

use BlueFairy\ApiGateway\Admin\AdminMenu;
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

final class AdminMenuTest extends TestCase {

	private CacheStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->store = new CacheStore();
		bfag_test_grant( Capabilities::MANAGE );
	}

	private function menu(): AdminMenu {
		$settings   = new Settings();
		$deliveries = new DeliveryRepository( $settings );
		return new AdminMenu(
			$settings,
			$this->store,
			new CacheRuleRepository(),
			new CacheStatsRepository(),
			new RateLimitRuleRepository(),
			new HitCounter(),
			new WebhookRepository(),
			$deliveries,
			new WebhookDispatcher( $deliveries, $settings ),
			new EventRegistry()
		);
	}

	/**
	 * A tab object as an add-on would supply it.
	 */
	private function addon_tab(): object {
		return new class() {
			public int $rendered = 0;
			public int $handled  = 0;
			public function render(): void {
				++$this->rendered;
				echo 'ADDON-TAB';
			}
			public function handle_actions(): void {
				++$this->handled;
			}
		};
	}

	public function test_register_hooks_binds_menu_init_assets_notices_and_the_four_ajax_actions(): void {
		$menu = $this->menu();
		$menu->register_hooks();
		foreach ( [ 'admin_menu', 'admin_init', 'admin_enqueue_scripts', 'admin_notices', 'wp_ajax_bfag_ajax_purge_cache', 'wp_ajax_bfag_ajax_refresh_kpis', 'wp_ajax_bfag_ajax_retry_webhook', 'wp_ajax_bfag_ajax_toggle_rule' ] as $hook ) {
			$this->assertTrue( (bool) has_action( $hook ), $hook );
		}
		$this->assertSame( 10, has_action( 'admin_menu', [ $menu, 'register_menu' ] ) );
		$this->assertSame( 10, has_action( 'admin_init', [ $menu, 'handle_actions' ] ) );
		$this->assertCount( 2, $GLOBALS['bfag_test_hooks']['admin_notices'][10], 'the notice renderer and the cron notice' );
	}

	public function test_register_menu_adds_an_options_page_gated_by_the_capability(): void {
		$menu = $this->menu();
		$menu->register_menu();
		$this->assertSame(
			[
				'parent_slug' => 'options-general.php',
				'page_title'  => 'API Gateway',
				'menu_title'  => 'API Gateway',
				'capability'  => 'bfag_manage_gateway',
				'menu_slug'   => 'bfag-gateway',
				'callback'    => [ $menu, 'render' ],
			],
			$GLOBALS['bfag_test_admin_pages'][0]
		);
	}

	public function test_tabs_are_the_five_base_tabs_in_order_plus_valid_addon_tabs(): void {
		$menu = $this->menu();
		$this->assertSame( [ 'dashboard', 'cache', 'ratelimit', 'webhooks', 'settings' ], array_keys( $menu->tabs() ) );
		$this->assertSame( 'Rate limit', $menu->tabs()['ratelimit']['label'] );

		$addon = $this->addon_tab();
		add_filter(
			'bfag_admin_tabs',
			static function ( array $tabs ) use ( $addon ): array {
				$tabs['apikeys']  = [
					'label' => 'API keys',
					'page'  => $addon,
				];
				$tabs['no-page']  = [ 'label' => 'x' ];
				$tabs['no-render'] = [
					'label' => 'x',
					'page'  => new \stdClass(),
				];
				$tabs['Bad Key!'] = [
					'label' => 'y',
					'page'  => $addon,
				];
				return $tabs;
			}
		);
		$tabs = $menu->tabs();
		$this->assertSame( [ 'dashboard', 'cache', 'ratelimit', 'webhooks', 'settings', 'apikeys', 'badkey' ], array_keys( $tabs ) );
		$this->assertSame( $addon, $tabs['apikeys']['page'] );
	}

	public function test_current_tab_falls_back_to_the_dashboard(): void {
		$menu = $this->menu();
		$this->assertSame( 'dashboard', $menu->current_tab() );
		$_GET['tab'] = 'webhooks';
		$this->assertSame( 'webhooks', $menu->current_tab() );
		$_GET['tab'] = 'nonsense';
		$this->assertSame( 'dashboard', $menu->current_tab() );
		$_GET['tab'] = '../etc';
		$this->assertSame( 'dashboard', $menu->current_tab() );
	}

	public function test_render_dies_for_a_user_without_the_capability(): void {
		$GLOBALS['bfag_test_caps'] = [];
		$exit                      = bfag_test_catch_exit( [ $this->menu(), 'render' ] );
		$this->assertSame( [ 'die', 403 ], [ $exit->kind, $exit->status ] );
		$this->assertSame( [], $this->wpdb->calls, 'nothing read' );
	}

	public function test_render_prints_the_tab_nav_the_current_tab_and_the_upsell_panel(): void {
		$_GET['tab'] = 'settings';
		$html        = bfag_test_render( [ $this->menu(), 'render' ] );
		$this->assertStringContainsString( '<div class="wrap bfag-wrap">', $html );
		$this->assertStringContainsString( '<h1>API Gateway</h1>', $html );
		$this->assertStringContainsString( 'class="nav-tab nav-tab-active">', $html );
		$this->assertStringContainsString( 'options-general.php?page=bfag-gateway&amp;tab=settings" class="nav-tab nav-tab-active"', $html );
		$this->assertStringContainsString( 'options-general.php?page=bfag-gateway&amp;tab=cache" class="nav-tab"', $html );
		$this->assertStringContainsString( 'name="bfag_action" value="save_settings"', $html, 'the settings tab rendered' );
		$this->assertStringContainsString( 'class="bfag-upsell"', $html );
		$this->assertStringContainsString( '<aside class="bfag-side">', $html );

		add_filter( 'bfag_is_pro', '__return_true' );
		$html = bfag_test_render( [ $this->menu(), 'render' ] );
		$this->assertStringNotContainsString( 'class="bfag-upsell"', $html, 'hidden for Pro' );
	}

	public function test_render_shows_an_addon_tab(): void {
		$addon = $this->addon_tab();
		add_filter(
			'bfag_admin_tabs',
			static function ( array $tabs ) use ( $addon ): array {
				$tabs['apikeys'] = [
					'label' => 'API keys',
					'page'  => $addon,
				];
				return $tabs;
			}
		);
		$_GET['tab'] = 'apikeys';
		$html        = bfag_test_render( [ $this->menu(), 'render' ] );
		$this->assertStringContainsString( 'ADDON-TAB', $html );
		$this->assertMatchesRegularExpression( '/nav-tab-active">\s*API keys\s*<\/a>/', $html );
		$this->assertSame( 1, $addon->rendered );
	}

	public function test_handle_actions_ignores_requests_without_an_action(): void {
		$addon = $this->addon_tab();
		add_filter(
			'bfag_admin_tabs',
			static function ( array $tabs ) use ( $addon ): array {
				$tabs['apikeys'] = [
					'label' => 'API keys',
					'page'  => $addon,
				];
				return $tabs;
			}
		);
		$this->assertNull( bfag_test_catch_exit( [ $this->menu(), 'handle_actions' ] ) );
		$this->assertSame( 0, $addon->handled, 'no action, no dispatch' );
		$this->assertSame( [], $this->wpdb->calls );
	}

	public function test_handle_actions_dies_on_a_bad_nonce_or_a_missing_capability_before_dispatching(): void {
		$fired = 0;
		add_action(
			'bfag_admin_action_purge_cache',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);
		bfag_test_admin_request( 'purge_cache', [], [], false );
		$exit = bfag_test_catch_exit( [ $this->menu(), 'handle_actions' ] );
		$this->assertSame( [ 'die', 403 ], [ $exit->kind, $exit->status ] );

		$GLOBALS['bfag_test_caps'] = [];
		bfag_test_admin_request( 'purge_cache' );
		$exit = bfag_test_catch_exit( [ $this->menu(), 'handle_actions' ] );
		$this->assertSame( [ 'die', 403 ], [ $exit->kind, $exit->status ] );

		$this->assertSame( 1, get_option( 'bfag_cache_generation', 1 ), 'nothing purged' );
		$this->assertSame( 0, $fired );
	}

	public function test_a_known_action_is_dispatched_to_its_tab_and_redirects_back_to_that_tab(): void {
		$before = $this->store->generation();
		bfag_test_admin_request( 'purge_cache' );
		$exit = bfag_test_catch_exit( [ $this->menu(), 'handle_actions' ] );
		$this->assertSame( 'redirect', $exit->kind );
		$this->assertSame( 'https://example.com/wp-admin/options-general.php?page=bfag-gateway&tab=cache&bfag_notice=cache_purged', $exit->payload );
		$this->assertSame( $before + 1, $this->store->generation() );
		$this->assertSame( 0, did_action( 'bfag_admin_action_purge_cache' ), 'a base action is not offered to the add-on' );
	}

	public function test_an_unknown_action_is_offered_to_the_addon_only_after_the_nonce_and_capability_pass(): void {
		$addon = $this->addon_tab();
		add_filter(
			'bfag_admin_tabs',
			static function ( array $tabs ) use ( $addon ): array {
				$tabs['apikeys'] = [
					'label' => 'API keys',
					'page'  => $addon,
				];
				return $tabs;
			}
		);
		$fired = 0;
		add_action(
			'bfag_admin_action_revoke_key',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		bfag_test_admin_request( 'revoke_key', [], [ 'id' => '3' ] );
		$this->assertNull( bfag_test_catch_exit( [ $this->menu(), 'handle_actions' ] ), 'the base does not redirect for an action it does not own' );
		$this->assertSame( 1, $fired );
		$this->assertSame( 1, $addon->handled, 'every tab gets to handle_actions()' );

		bfag_test_admin_request( 'revoke_key', [], [ 'id' => '3' ], false );
		$exit = bfag_test_catch_exit( [ $this->menu(), 'handle_actions' ] );
		$this->assertSame( 'die', $exit->kind );
		$this->assertSame( 1, $fired, 'not fired on a bad nonce' );
		$this->assertSame( 1, $addon->handled );
	}

	public function test_notices_cover_every_code_the_pages_redirect_with_and_are_filterable(): void {
		$menu  = $this->menu();
		$codes = $menu->notices();
		foreach ( [ 'error', 'cache_saved', 'cache_invalid', 'cache_limit', 'cache_deleted', 'cache_not_found', 'cache_toggled', 'cache_purged', 'ratelimit_saved', 'ratelimit_invalid', 'ratelimit_limit', 'ratelimit_deleted', 'ratelimit_not_found', 'ratelimit_toggled', 'webhook_saved', 'webhook_invalid', 'webhook_event_unknown', 'webhook_limit', 'webhook_deleted', 'webhook_not_found', 'webhook_toggled', 'webhook_test_ok', 'webhook_test_failed', 'settings_saved' ] as $code ) {
			$this->assertArrayHasKey( $code, $codes, $code );
			$this->assertContains( $codes[ $code ][0], [ 'success', 'error' ] );
			$this->assertNotSame( '', $codes[ $code ][1] );
		}
		// Every redirect( '<code>' ) in the page sources must have a message.
		foreach ( glob( BFAG_DIR . 'includes/Admin/*Page.php' ) ?: [] as $file ) {
			preg_match_all( "/redirect\( '([a-z_]+)'/", (string) file_get_contents( $file ), $m );
			foreach ( $m[1] as $code ) {
				$this->assertArrayHasKey( $code, $codes, basename( $file ) . " redirects with '$code'" );
			}
		}

		add_filter(
			'bfag_admin_notices',
			static function ( array $messages ): array {
				$messages['key_revoked'] = [ 'success', 'Key revoked.' ];
				return $messages;
			}
		);
		$this->assertSame( [ 'success', 'Key revoked.' ], $menu->notices()['key_revoked'] );
	}

	public function test_render_notices_prints_the_requested_code_on_the_plugin_screen_only(): void {
		$menu                = $this->menu();
		$_GET['bfag_notice'] = 'cache_saved';
		bfag_test_set_screen( 'edit-post' );
		$this->assertSame( '', bfag_test_render( [ $menu, 'render_notices' ] ) );

		bfag_test_set_screen( 'settings_page_bfag-gateway' );
		$this->assertSame( '<div class="notice notice-success is-dismissible"><p>Cache rule saved.</p></div>', bfag_test_render( [ $menu, 'render_notices' ] ) );

		$_GET['bfag_notice'] = 'cache_invalid';
		$this->assertStringContainsString( 'notice-error', bfag_test_render( [ $menu, 'render_notices' ] ) );

		$_GET['bfag_notice'] = 'no_such_code';
		$this->assertSame( '', bfag_test_render( [ $menu, 'render_notices' ] ) );

		add_filter(
			'bfag_admin_notices',
			static function ( array $messages ): array {
				$messages['odd'] = [ 'javascript', '<b>x</b>' ];
				return $messages;
			}
		);
		$_GET['bfag_notice'] = 'odd';
		$this->assertSame( '<div class="notice notice-info is-dismissible"><p>&lt;b&gt;x&lt;/b&gt;</p></div>', bfag_test_render( [ $menu, 'render_notices' ] ), 'unknown type falls back to info; text is escaped' );

		unset( $_GET['bfag_notice'] );
		$this->assertSame( '', bfag_test_render( [ $menu, 'render_notices' ] ) );
	}

	public function test_ajax_handlers_reject_a_bad_nonce_and_a_missing_capability(): void {
		foreach ( [ 'purge_cache', 'refresh_kpis', 'retry_webhook', 'toggle_rule' ] as $action ) {
			bfag_test_ajax_request( $action, [], false );
			$exit = bfag_test_catch_exit( [ $this->menu(), 'ajax_' . $action ] );
			$this->assertSame( [ 'die', 403 ], [ $exit->kind, $exit->status ], $action );
		}
		$GLOBALS['bfag_test_caps'] = [];
		foreach ( [ 'purge_cache', 'refresh_kpis', 'retry_webhook', 'toggle_rule' ] as $action ) {
			bfag_test_ajax_request( $action );
			$exit = bfag_test_catch_exit( [ $this->menu(), 'ajax_' . $action ] );
			$this->assertSame( [ 'json_error', 403 ], [ $exit->kind, $exit->status ], $action );
		}
		$this->assertSame( [], $this->wpdb->calls );
		$this->assertSame( 1, get_option( 'bfag_cache_generation', 1 ) );
	}

	public function test_ajax_purge_flushes_all_or_bumps_the_given_tags(): void {
		$seen = [];
		add_action(
			'bfag_cache_purged',
			static function ( string $scope, array $tags ) use ( &$seen ): void {
				$seen[] = [ $scope, $tags ];
			},
			10,
			2
		);
		$generation = $this->store->generation();
		$posts      = $this->store->tag_generation( 'posts' );

		bfag_test_ajax_request( 'purge_cache' );
		$exit = bfag_test_catch_exit( [ $this->menu(), 'ajax_purge_cache' ] );
		$this->assertSame( 'json_success', $exit->kind );
		$this->assertSame(
			[
				'purged' => 'all',
				'tags'   => [],
			],
			$exit->payload
		);
		$this->assertSame( $generation + 1, $this->store->generation() );

		bfag_test_ajax_request( 'purge_cache', [ 'tags' => [ 'Posts', '', 'x y' ] ] );
		$exit = bfag_test_catch_exit( [ $this->menu(), 'ajax_purge_cache' ] );
		$this->assertSame(
			[
				'purged' => 'tags',
				'tags'   => [ 'posts', 'xy' ],
			],
			$exit->payload
		);
		$this->assertSame( $generation + 1, $this->store->generation(), 'tags do not touch the global generation' );
		$this->assertSame( $posts + 1, $this->store->tag_generation( 'posts' ) );
		$this->assertSame( [ [ 'all', [] ], [ 'tags', [ 'posts', 'xy' ] ] ], $seen );
	}

	public function test_ajax_refresh_recomputes_stores_and_returns_the_kpis(): void {
		$this->wpdb->queue(
			'get_row',
			[
				'hits'   => 3,
				'misses' => 1,
			]
		);
		$this->wpdb->queue( 'get_results', [] );
		$this->wpdb->queue( 'get_var', 42 );
		bfag_test_ajax_request( 'refresh_kpis' );
		$exit = bfag_test_catch_exit( [ $this->menu(), 'ajax_refresh_kpis' ] );
		$this->assertSame( 'json_success', $exit->kind );
		$this->assertSame( 0.75, $exit->payload['cache_hit_rate'] );
		$this->assertSame( 42, $exit->payload['hits_table_rows'] );
		$this->assertSame( $exit->payload, get_transient( 'bfag_kpis' ) );
	}

	public function test_ajax_retry_redelivers_and_reports_the_outcome(): void {
		$delivery = [
			'id'         => 10,
			'webhook_id' => 1,
			'direction'  => 'out',
			'event'      => 'post.published',
			'payload'    => '{"event":"post.published","object":{"id":5}}',
			'truncated'  => 0,
			'status'     => 'failed',
			'attempt'    => 1,
		];
		$webhook  = [
			'id'          => 1,
			'label'       => 'CRM',
			'url'         => 'https://hooks.example.net/in',
			'event'       => 'post.published',
			'secret'      => 's',
			'headers'     => null,
			'preset'      => '',
			'enabled'     => 1,
			'max_retries' => 3,
		];

		bfag_test_ajax_request( 'retry_webhook', [ 'delivery_id' => '99' ] );
		$exit = bfag_test_catch_exit( [ $this->menu(), 'ajax_retry_webhook' ] );
		$this->assertSame( [ 'json_error', 404 ], [ $exit->kind, $exit->status ] );

		$this->wpdb->queue( 'get_row', $delivery );
		bfag_test_ajax_request( 'retry_webhook', [ 'delivery_id' => '10' ] );
		$exit = bfag_test_catch_exit( [ $this->menu(), 'ajax_retry_webhook' ] );
		$this->assertSame( [ 'json_error', 404 ], [ $exit->kind, $exit->status ], 'webhook gone' );
		$this->assertSame( [], $GLOBALS['bfag_test_http_calls'] );

		$this->wpdb->queue( 'get_row', $delivery );
		$this->wpdb->queue( 'get_row', $webhook );
		$this->wpdb->queue( 'get_row', $delivery ); // redeliver() re-reads the row.
		bfag_test_queue_http( 200, 'ok' );
		bfag_test_ajax_request( 'retry_webhook', [ 'delivery_id' => '10' ] );
		$exit = bfag_test_catch_exit( [ $this->menu(), 'ajax_retry_webhook' ] );
		$this->assertSame( 'json_success', $exit->kind );
		$this->assertSame( 10, $exit->payload['delivery_id'] );
		$this->assertTrue( $exit->payload['success'] );
		$this->assertSame( 200, $exit->payload['http_status'] );
		$this->assertSame( $delivery['payload'], $GLOBALS['bfag_test_http_calls'][0]['args']['body'], 'the stored bytes are resent' );

		$this->wpdb->queue( 'get_row', $delivery );
		$this->wpdb->queue( 'get_row', $webhook );
		$this->wpdb->queue( 'get_row', $delivery );
		bfag_test_queue_http( 503 );
		bfag_test_ajax_request( 'retry_webhook', [ 'delivery_id' => '10' ] );
		$exit = bfag_test_catch_exit( [ $this->menu(), 'ajax_retry_webhook' ] );
		$this->assertSame( 'json_error', $exit->kind );
		$this->assertFalse( $exit->payload['success'] );
		$this->assertSame( 503, $exit->payload['http_status'] );
	}

	public function test_ajax_toggle_updates_the_right_table_and_rejects_unknown_kinds_and_ids(): void {
		$row = [
			'id'      => 4,
			'enabled' => 1,
		];
		foreach ( [
			'cache'     => 'bfw_bfag_cache_rules',
			'ratelimit' => 'bfw_bfag_rate_limit_rules',
			'webhook'   => 'bfw_bfag_webhooks',
		] as $kind => $table ) {
			$this->wpdb->queue( 'get_row', $row );
			bfag_test_ajax_request(
				'toggle_rule',
				[
					'kind'    => $kind,
					'id'      => '4',
					'enabled' => '0',
				]
			);
			$exit = bfag_test_catch_exit( [ $this->menu(), 'ajax_toggle_rule' ] );
			$this->assertSame( 'json_success', $exit->kind, $kind );
			$this->assertSame(
				[
					'kind'    => $kind,
					'id'      => 4,
					'enabled' => false,
				],
				$exit->payload
			);
			$update = array_pop( $this->wpdb->calls );
			$this->assertSame( 'update', $update['method'] );
			$this->assertSame( $table, $update['table'] );
			$this->assertSame( [ 'id' => 4 ], $update['where'] );
			$this->assertSame( 0, (int) $update['data']['enabled'] );
		}

		bfag_test_ajax_request(
			'toggle_rule',
			[
				'kind'    => 'cache',
				'id'      => '4',
				'enabled' => '1',
			]
		);
		$exit = bfag_test_catch_exit( [ $this->menu(), 'ajax_toggle_rule' ] );
		$this->assertSame( [ 'json_error', 404 ], [ $exit->kind, $exit->status ], 'unknown id' );

		bfag_test_ajax_request(
			'toggle_rule',
			[
				'kind' => 'apikey',
				'id'   => '4',
			]
		);
		$exit = bfag_test_catch_exit( [ $this->menu(), 'ajax_toggle_rule' ] );
		$this->assertSame( [ 'json_error', 400 ], [ $exit->kind, $exit->status ], 'unknown kind' );
		$this->assertSame( [], $this->wpdb->calls_to( 'update' ) );
	}
}
