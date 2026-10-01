<?php
/**
 * The Settings › API Gateway page: menu, tabs, actions, notices and AJAX.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Admin;

use BlueFairy\ApiGateway\Cache\CacheRuleRepository;
use BlueFairy\ApiGateway\Cache\CacheStatsRepository;
use BlueFairy\ApiGateway\Cache\CacheStore;
use BlueFairy\ApiGateway\Capabilities;
use BlueFairy\ApiGateway\RateLimit\HitCounter;
use BlueFairy\ApiGateway\RateLimit\RateLimitRuleRepository;
use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Webhook\DeliveryRepository;
use BlueFairy\ApiGateway\Webhook\EventRegistry;
use BlueFairy\ApiGateway\Webhook\WebhookDispatcher;
use BlueFairy\ApiGateway\Webhook\WebhookRepository;

/**
 * One options page, five tabs (the add-on adds more through
 * `bfag_admin_tabs`). Form POSTs come back to the same URL with
 * `bfag_action=<action>` and a `bfag_<action>` nonce; handle_actions()
 * verifies both, lets every tab handle its own actions and fires
 * `bfag_admin_action_<action>` for the ones no base tab knows.
 */
final class AdminMenu {

	public const MENU_SLUG = Page::MENU_SLUG;

	/**
	 * Base tabs, in display order.
	 *
	 * @var array<string, Page>
	 */
	private array $pages;

	/**
	 * Pro state and the side panel.
	 *
	 * @var ProUpsell
	 */
	private ProUpsell $upsell;

	/**
	 * Stylesheet, script and the screen check.
	 *
	 * @var Assets
	 */
	private Assets $assets;

	/**
	 * KPI transient writer (Refresh button).
	 *
	 * @var KpiCron
	 */
	private KpiCron $kpi_cron;

	/**
	 * The Settings tab, kept typed for its cron notice.
	 *
	 * @var SettingsPage
	 */
	private SettingsPage $settings_page;

	/**
	 * Constructor.
	 *
	 * @param Settings                $settings    Plugin settings.
	 * @param CacheStore              $store       Response store.
	 * @param CacheRuleRepository     $cache_rules Cache rules.
	 * @param CacheStatsRepository    $stats       Cache statistics.
	 * @param RateLimitRuleRepository $rl_rules    Rate-limit rules.
	 * @param HitCounter              $hits        Rate-limit hits.
	 * @param WebhookRepository       $webhooks    Webhooks.
	 * @param DeliveryRepository      $deliveries  Delivery log.
	 * @param WebhookDispatcher       $dispatcher  Webhook sender.
	 * @param EventRegistry           $registry    Known events.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly CacheStore $store,
		private readonly CacheRuleRepository $cache_rules,
		private readonly CacheStatsRepository $stats,
		private readonly RateLimitRuleRepository $rl_rules,
		private readonly HitCounter $hits,
		private readonly WebhookRepository $webhooks,
		private readonly DeliveryRepository $deliveries,
		private readonly WebhookDispatcher $dispatcher,
		private readonly EventRegistry $registry
	) {
		$this->upsell        = new ProUpsell();
		$this->assets        = new Assets();
		$this->kpi_cron      = new KpiCron( $this->stats, $this->deliveries, $this->hits );
		$this->settings_page = new SettingsPage( $this->settings );
		$this->pages         = [
			DashboardPage::TAB => new DashboardPage( $this->cache_rules, $this->rl_rules, $this->webhooks, $this->deliveries, $this->hits, $this->kpi_cron ),
			CachePage::TAB     => new CachePage( $this->cache_rules, $this->store, $this->upsell ),
			RateLimitPage::TAB => new RateLimitPage( $this->rl_rules, $this->upsell ),
			WebhookPage::TAB   => new WebhookPage( $this->webhooks, $this->deliveries, $this->dispatcher, $this->registry, $this->upsell ),
			SettingsPage::TAB  => $this->settings_page,
		];
	}

	/**
	 * Bind everything.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'handle_actions' ] );
		add_action( 'admin_enqueue_scripts', [ $this->assets, 'enqueue' ] );
		add_action( 'admin_notices', [ $this, 'render_notices' ] );
		add_action( 'admin_notices', [ $this->settings_page, 'render_cron_notice' ] );
		add_action( 'wp_ajax_bfag_ajax_purge_cache', [ $this, 'ajax_purge_cache' ] );
		add_action( 'wp_ajax_bfag_ajax_refresh_kpis', [ $this, 'ajax_refresh_kpis' ] );
		add_action( 'wp_ajax_bfag_ajax_retry_webhook', [ $this, 'ajax_retry_webhook' ] );
		add_action( 'wp_ajax_bfag_ajax_toggle_rule', [ $this, 'ajax_toggle_rule' ] );
	}

	/**
	 * Hooked to admin_menu.
	 */
	public function register_menu(): void {
		add_options_page(
			__( 'API Gateway', 'bluefairy-api-gateway' ),
			__( 'API Gateway', 'bluefairy-api-gateway' ),
			Capabilities::MANAGE,
			self::MENU_SLUG,
			[ $this, 'render' ]
		);
	}

	/**
	 * A base page by tab id.
	 *
	 * @param string $tab Tab id.
	 */
	public function page( string $tab ): Page {
		return $this->pages[ $tab ];
	}

	/**
	 * The tabs: the five base ones, then whatever `bfag_admin_tabs` adds.
	 * An added tab must carry a label and a page object with render() and
	 * handle_actions(); anything else is dropped.
	 *
	 * @return array<string, array{label:string, page:object}>
	 */
	public function tabs(): array {
		$tabs = [
			DashboardPage::TAB => [
				'label' => __( 'Dashboard', 'bluefairy-api-gateway' ),
				'page'  => $this->pages[ DashboardPage::TAB ],
			],
			CachePage::TAB     => [
				'label' => __( 'Cache', 'bluefairy-api-gateway' ),
				'page'  => $this->pages[ CachePage::TAB ],
			],
			RateLimitPage::TAB => [
				'label' => __( 'Rate limit', 'bluefairy-api-gateway' ),
				'page'  => $this->pages[ RateLimitPage::TAB ],
			],
			WebhookPage::TAB   => [
				'label' => __( 'Webhooks', 'bluefairy-api-gateway' ),
				'page'  => $this->pages[ WebhookPage::TAB ],
			],
			SettingsPage::TAB  => [
				'label' => __( 'Settings', 'bluefairy-api-gateway' ),
				'page'  => $this->pages[ SettingsPage::TAB ],
			],
		];

		/**
		 * Add admin tabs. Each entry is keyed by tab id and carries `label`
		 * and `page` (an object with render(): void and handle_actions(): void).
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array{label:string, page:object}> $tabs The tabs.
		 */
		$filtered = apply_filters( 'bfag_admin_tabs', $tabs );

		$clean = [];
		foreach ( is_array( $filtered ) ? $filtered : $tabs as $id => $tab ) {
			if ( ! is_string( $id ) || ! is_array( $tab ) || ! isset( $tab['label'], $tab['page'] ) ) {
				continue;
			}
			if ( ! is_string( $tab['label'] ) || ! is_object( $tab['page'] ) || ! method_exists( $tab['page'], 'render' ) || ! method_exists( $tab['page'], 'handle_actions' ) ) {
				continue;
			}
			$clean[ sanitize_key( $id ) ] = [
				'label' => $tab['label'],
				'page'  => $tab['page'],
			];
		}
		return $clean;
	}

	/**
	 * The requested tab id, falling back to the dashboard.
	 */
	public function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only: which tab to show.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		return isset( $this->tabs()[ $tab ] ) ? $tab : DashboardPage::TAB;
	}

	/**
	 * The page callback.
	 */
	public function render(): void {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to manage the API Gateway.', 'bluefairy-api-gateway' ), '', [ 'response' => 403 ] );
		}
		$tabs    = $this->tabs();
		$current = $this->current_tab();

		echo '<div class="wrap bfag-wrap">';
		echo '<h1>' . esc_html__( 'API Gateway', 'bluefairy-api-gateway' ) . '</h1>';
		Page::include_view(
			'tabs',
			[
				'tabs'     => $tabs,
				'current'  => $current,
				'base_url' => admin_url( 'options-general.php?page=' . self::MENU_SLUG ),
			]
		);
		echo '<div class="bfag-layout"><div class="bfag-main">';
		$tabs[ $current ]['page']->render();
		echo '</div><aside class="bfag-side">';
		$this->upsell->render();
		echo '</aside></div></div>';
	}

	/**
	 * Hooked to admin_init: verify and dispatch `bfag_action`.
	 *
	 * Every base tab checks the action against its own list; an action none
	 * of them owns is offered to the add-on through
	 * `bfag_admin_action_<action>` — after the nonce and capability checks,
	 * so the add-on's handler can trust the request.
	 */
	public function handle_actions(): void {
		$action = isset( $_REQUEST['bfag_action'] ) ? sanitize_key( wp_unslash( $_REQUEST['bfag_action'] ) ) : '';
		if ( '' === $action ) {
			return;
		}

		check_admin_referer( 'bfag_' . $action );
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to manage the API Gateway.', 'bluefairy-api-gateway' ), '', [ 'response' => 403 ] );
		}

		$known = false;
		foreach ( $this->pages as $page ) {
			if ( in_array( $action, $page->actions(), true ) ) {
				$known = true;
			}
		}
		foreach ( $this->tabs() as $tab ) {
			$tab['page']->handle_actions();
		}
		if ( $known ) {
			return;
		}

		/**
		 * An admin action the base plugin does not handle, with its nonce
		 * (`bfag_<action>`) and the capability already verified.
		 *
		 * @since 1.0.0
		 */
		do_action( 'bfag_admin_action_' . $action );
	}

	/**
	 * Notice codes → type and text, then `bfag_admin_notices`.
	 *
	 * @return array<string, array{0:string, 1:string}>
	 */
	public function notices(): array {
		$messages = [
			'error'                 => [ 'error', __( 'That did not work. Please try again.', 'bluefairy-api-gateway' ) ],
			'cache_saved'           => [ 'success', __( 'Cache rule saved.', 'bluefairy-api-gateway' ) ],
			'cache_invalid'         => [ 'error', __( 'Cache rule not saved: check the route glob (letters, digits, / * ? . - _) and the TTL (1–86400 seconds).', 'bluefairy-api-gateway' ) ],
			'cache_limit'           => [ 'error', __( 'Cache rule not added: the free plugin allows one rule.', 'bluefairy-api-gateway' ) ],
			'cache_deleted'         => [ 'success', __( 'Cache rule deleted.', 'bluefairy-api-gateway' ) ],
			'cache_not_found'       => [ 'error', __( 'That cache rule no longer exists.', 'bluefairy-api-gateway' ) ],
			'cache_toggled'         => [ 'success', __( 'Cache rule updated.', 'bluefairy-api-gateway' ) ],
			'cache_purged'          => [ 'success', __( 'Response cache purged.', 'bluefairy-api-gateway' ) ],
			'ratelimit_saved'       => [ 'success', __( 'Rate-limit rule saved.', 'bluefairy-api-gateway' ) ],
			'ratelimit_invalid'     => [ 'error', __( 'Rate-limit rule not saved: check the subject type, route glob, requests (1–65535) and window (1–86400 seconds).', 'bluefairy-api-gateway' ) ],
			'ratelimit_limit'       => [ 'error', __( 'Rate-limit rule not added: the free plugin allows one rule.', 'bluefairy-api-gateway' ) ],
			'ratelimit_deleted'     => [ 'success', __( 'Rate-limit rule deleted.', 'bluefairy-api-gateway' ) ],
			'ratelimit_not_found'   => [ 'error', __( 'That rate-limit rule no longer exists.', 'bluefairy-api-gateway' ) ],
			'ratelimit_toggled'     => [ 'success', __( 'Rate-limit rule updated.', 'bluefairy-api-gateway' ) ],
			'webhook_saved'         => [ 'success', __( 'Webhook saved.', 'bluefairy-api-gateway' ) ],
			'webhook_invalid'       => [ 'error', __( 'Webhook not saved: it needs a label, an https URL and a known event.', 'bluefairy-api-gateway' ) ],
			'webhook_event_unknown' => [ 'error', __( 'Webhook not saved: that event is not available.', 'bluefairy-api-gateway' ) ],
			'webhook_limit'         => [ 'error', __( 'Webhook not added: the free plugin allows three webhooks.', 'bluefairy-api-gateway' ) ],
			'webhook_deleted'       => [ 'success', __( 'Webhook and its delivery log deleted.', 'bluefairy-api-gateway' ) ],
			'webhook_not_found'     => [ 'error', __( 'That webhook no longer exists.', 'bluefairy-api-gateway' ) ],
			'webhook_toggled'       => [ 'success', __( 'Webhook updated.', 'bluefairy-api-gateway' ) ],
			'webhook_test_ok'       => [ 'success', __( 'Test delivery succeeded. See the delivery log for the response.', 'bluefairy-api-gateway' ) ],
			'webhook_test_failed'   => [ 'error', __( 'Test delivery failed. See the delivery log for the response.', 'bluefairy-api-gateway' ) ],
			'settings_saved'        => [ 'success', __( 'Settings saved.', 'bluefairy-api-gateway' ) ],
		];

		/**
		 * Add notice codes for `?bfag_notice=<code>`: `code => [ type, text ]`.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array{0:string, 1:string}> $messages The messages.
		 */
		$filtered = apply_filters( 'bfag_admin_notices', $messages );
		return is_array( $filtered ) ? $filtered : $messages;
	}

	/**
	 * Hooked to admin_notices: show the `?bfag_notice=` message on the plugin's screen.
	 */
	public function render_notices(): void {
		if ( ! $this->assets->is_our_screen() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only: a message code, looked up in a fixed map.
		$code = isset( $_GET['bfag_notice'] ) ? sanitize_key( wp_unslash( $_GET['bfag_notice'] ) ) : '';
		if ( '' === $code ) {
			return;
		}
		$notice = $this->notices()[ $code ] ?? null;
		if ( ! is_array( $notice ) || ! isset( $notice[0], $notice[1] ) ) {
			return;
		}
		$type = in_array( $notice[0], [ 'success', 'error', 'warning', 'info' ], true ) ? $notice[0] : 'info';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( (string) $notice[1] ) . '</p></div>';
	}

	/**
	 * Hooked to wp_ajax_bfag_ajax_purge_cache: `tags[]` bumps those tags, none flushes all.
	 */
	public function ajax_purge_cache(): void {
		check_ajax_referer( 'bfag_ajax_purge_cache', 'nonce' );
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_send_json_error( [ 'message' => __( 'Not allowed.', 'bluefairy-api-gateway' ) ], 403 );
		}
		$tags = [];
		if ( isset( $_POST['tags'] ) && is_array( $_POST['tags'] ) ) {
			$tags = array_values( array_filter( array_map( 'sanitize_key', wp_unslash( $_POST['tags'] ) ) ) );
		}
		if ( [] === $tags ) {
			$this->store->flush_all();
			do_action( 'bfag_cache_purged', 'all', [] );
		} else {
			$this->store->bump_tags( $tags );
			do_action( 'bfag_cache_purged', 'tags', $tags );
		}
		wp_send_json_success(
			[
				'purged' => [] === $tags ? 'all' : 'tags',
				'tags'   => $tags,
			]
		);
	}

	/**
	 * Hooked to wp_ajax_bfag_ajax_refresh_kpis: recompute and return the KPIs.
	 */
	public function ajax_refresh_kpis(): void {
		check_ajax_referer( 'bfag_ajax_refresh_kpis', 'nonce' );
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_send_json_error( [ 'message' => __( 'Not allowed.', 'bluefairy-api-gateway' ) ], 403 );
		}
		wp_send_json_success( $this->kpi_cron->run() );
	}

	/**
	 * Hooked to wp_ajax_bfag_ajax_retry_webhook: redeliver one delivery.
	 */
	public function ajax_retry_webhook(): void {
		check_ajax_referer( 'bfag_ajax_retry_webhook', 'nonce' );
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_send_json_error( [ 'message' => __( 'Not allowed.', 'bluefairy-api-gateway' ) ], 403 );
		}
		$delivery_id = isset( $_POST['delivery_id'] ) ? absint( wp_unslash( $_POST['delivery_id'] ) ) : 0;
		$delivery    = $delivery_id > 0 ? $this->deliveries->find( $delivery_id ) : null;
		if ( null === $delivery ) {
			wp_send_json_error( [ 'message' => __( 'Delivery not found.', 'bluefairy-api-gateway' ) ], 404 );
		}
		$webhook = $this->webhooks->find( (int) $delivery['webhook_id'] );
		if ( null === $webhook ) {
			wp_send_json_error( [ 'message' => __( 'Webhook no longer exists.', 'bluefairy-api-gateway' ) ], 404 );
		}
		$result = $this->dispatcher->redeliver( $delivery_id, $webhook );
		if ( $result['success'] ) {
			wp_send_json_success( $result );
		}
		wp_send_json_error( $result );
	}

	/**
	 * Hooked to wp_ajax_bfag_ajax_toggle_rule: `kind` cache|ratelimit|webhook, `id`, `enabled`.
	 */
	public function ajax_toggle_rule(): void {
		check_ajax_referer( 'bfag_ajax_toggle_rule', 'nonce' );
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_send_json_error( [ 'message' => __( 'Not allowed.', 'bluefairy-api-gateway' ) ], 403 );
		}
		$kind    = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		$id      = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		$enabled = isset( $_POST['enabled'] ) && Settings::to_bool( sanitize_key( wp_unslash( $_POST['enabled'] ) ) );

		$result = match ( $kind ) {
			'cache'     => $id > 0 && null !== $this->cache_rules->find( $id ) && $this->cache_rules->set_enabled( $id, $enabled ),
			'ratelimit' => $id > 0 && null !== $this->rl_rules->find( $id ) && $this->rl_rules->set_enabled( $id, $enabled ),
			'webhook'   => $id > 0 && null !== $this->webhooks->find( $id ) && $this->webhooks->set_enabled( $id, $enabled ),
			default     => null,
		};
		if ( null === $result ) {
			wp_send_json_error( [ 'message' => __( 'Unknown rule kind.', 'bluefairy-api-gateway' ) ], 400 );
		}
		if ( ! $result ) {
			wp_send_json_error( [ 'message' => __( 'Rule not found.', 'bluefairy-api-gateway' ) ], 404 );
		}
		wp_send_json_success(
			[
				'kind'    => $kind,
				'id'      => $id,
				'enabled' => $enabled,
			]
		);
	}
}
