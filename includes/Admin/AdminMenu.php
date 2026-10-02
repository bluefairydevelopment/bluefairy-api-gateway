<?php
/**
 * The Settings › API Gateway page: menu, tabs, actions, notices and AJAX.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BlueFairy\ApiGateway\Cache\CachePurger;
use BlueFairy\ApiGateway\Cache\CacheRuleRepository;
use BlueFairy\ApiGateway\Cache\CacheStore;
use BlueFairy\ApiGateway\Capabilities;
use BlueFairy\ApiGateway\Installer;
use BlueFairy\ApiGateway\RateLimit\RateLimitRuleRepository;
use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Support\RequestContext;
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
	 * User meta that records a dismissed trusted-proxies notice.
	 */
	public const PROXY_NOTICE_META = 'bfag_proxy_notice_dismissed';

	/**
	 * Delivery statuses the Retry button is offered for and the AJAX
	 * handler accepts (U-10).
	 *
	 * @var list<string>
	 */
	public const RETRYABLE_STATUSES = [ 'failed', 'retrying' ];

	/**
	 * Screens the trusted-proxies notice may appear on: the plugin's own,
	 * Plugins and the Dashboard (and their network-admin twins).
	 *
	 * @var list<string>
	 */
	private const PROXY_NOTICE_SCREENS = [ Assets::SCREEN_ID, 'plugins', 'plugins-network', 'dashboard', 'dashboard-network' ];

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
	 * The Settings tab, kept typed for its cron notice.
	 *
	 * @var SettingsPage
	 */
	private SettingsPage $settings_page;

	/**
	 * Constructor.
	 *
	 * @param Settings                $settings    Plugin settings.
	 * @param CachePurger             $purger      The one purge entry point (Purge all, AJAX purge).
	 * @param CacheRuleRepository     $cache_rules Cache rules.
	 * @param RateLimitRuleRepository $rl_rules    Rate-limit rules.
	 * @param WebhookRepository       $webhooks    Webhooks.
	 * @param DeliveryRepository      $deliveries  Delivery log.
	 * @param WebhookDispatcher       $dispatcher  Webhook sender.
	 * @param EventRegistry           $registry    Known events.
	 * @param KpiCron                 $kpi_cron    The KPI writer Plugin::boot() registered (Refresh button, dashboard cards).
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly CachePurger $purger,
		private readonly CacheRuleRepository $cache_rules,
		private readonly RateLimitRuleRepository $rl_rules,
		private readonly WebhookRepository $webhooks,
		private readonly DeliveryRepository $deliveries,
		private readonly WebhookDispatcher $dispatcher,
		private readonly EventRegistry $registry,
		private readonly KpiCron $kpi_cron
	) {
		$this->upsell        = new ProUpsell();
		$this->assets        = new Assets();
		$this->settings_page = new SettingsPage( $this->settings, $this->upsell );
		$this->pages         = [
			DashboardPage::TAB => new DashboardPage( $this->cache_rules, $this->rl_rules, $this->webhooks, $this->deliveries, $this->kpi_cron, $this->upsell ),
			CachePage::TAB     => new CachePage( $this->cache_rules, $this->purger, $this->upsell, $this->settings ),
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
		add_action( 'admin_notices', [ $this, 'render_install_error_notice' ] );
		add_action( 'admin_notices', [ $this, 'render_proxy_notice' ] );
		add_action( 'wp_ajax_bfag_ajax_purge_cache', [ $this, 'ajax_purge_cache' ] );
		add_action( 'wp_ajax_bfag_ajax_refresh_kpis', [ $this, 'ajax_refresh_kpis' ] );
		add_action( 'wp_ajax_bfag_ajax_retry_webhook', [ $this, 'ajax_retry_webhook' ] );
		add_action( 'wp_ajax_bfag_ajax_toggle_rule', [ $this, 'ajax_toggle_rule' ] );
		add_action( 'wp_ajax_bfag_ajax_dismiss_notice', [ $this, 'ajax_dismiss_notice' ] );
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
	 *
	 * This runs on every admin request, so it acts only on the plugin's own
	 * screen (`page=bfag-gateway`) and only for a user who may manage the
	 * gateway; anything else is ignored rather than refused, because a
	 * stray `bfag_action` on profile.php from a subscriber is not an attack
	 * worth a wp_die, and the nonce check — which does die — must not be
	 * reachable from every admin page.
	 */
	public function handle_actions(): void {
		$action = isset( $_REQUEST['bfag_action'] ) ? sanitize_key( wp_unslash( $_REQUEST['bfag_action'] ) ) : '';
		if ( '' === $action ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which admin screen this is; the nonce is checked just below.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::MENU_SLUG !== $page || ! Capabilities::current_user_can_manage() ) {
			return;
		}

		check_admin_referer( 'bfag_' . $action );

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
			'error'                   => [ 'error', __( 'That did not work. Please try again.', 'bluefairy-api-gateway' ) ],
			'cache_saved'             => [ 'success', __( 'Cache rule saved.', 'bluefairy-api-gateway' ) ],
			'cache_invalid'           => [ 'error', __( 'Cache rule not saved: the route glob may contain only letters, digits and / * ? . - _ % : @ = & + ~ (up to 500 characters). Your entries are kept in the form below.', 'bluefairy-api-gateway' ) ],
			'cache_limit'             => [ 'error', __( 'Cache rule not added: the free plugin allows one rule.', 'bluefairy-api-gateway' ) ],
			'cache_deleted'           => [ 'success', __( 'Cache rule deleted.', 'bluefairy-api-gateway' ) ],
			'cache_not_found'         => [ 'error', __( 'That cache rule no longer exists.', 'bluefairy-api-gateway' ) ],
			'cache_toggled'           => [ 'success', __( 'Cache rule updated.', 'bluefairy-api-gateway' ) ],
			'cache_purged'            => [ 'success', __( 'Response cache purged.', 'bluefairy-api-gateway' ) ],
			'ratelimit_saved'         => [ 'success', __( 'Rate-limit rule saved.', 'bluefairy-api-gateway' ) ],
			'ratelimit_invalid'       => [ 'error', __( 'Rate-limit rule not saved: that "Count by" subject type is not available (per-user and per-API-key limits need the Pro add-on). Your entries are kept in the form below.', 'bluefairy-api-gateway' ) ],
			'ratelimit_glob_invalid'  => [ 'error', __( 'Rate-limit rule not saved: the route glob may contain only letters, digits and / * ? . - _ % : @ = & + ~ (up to 500 characters). Your entries are kept in the form below.', 'bluefairy-api-gateway' ) ],
			'ratelimit_range_invalid' => [ 'error', __( 'Rate-limit rule not saved: requests must be 1–65535, the window 1–86400 seconds and the penalty 0–2592000 seconds. Your entries are kept in the form below.', 'bluefairy-api-gateway' ) ],
			'ratelimit_limit'         => [ 'error', __( 'Rate-limit rule not added: the free plugin allows one rule.', 'bluefairy-api-gateway' ) ],
			'ratelimit_deleted'       => [ 'success', __( 'Rate-limit rule deleted.', 'bluefairy-api-gateway' ) ],
			'ratelimit_not_found'     => [ 'error', __( 'That rate-limit rule no longer exists.', 'bluefairy-api-gateway' ) ],
			'ratelimit_toggled'       => [ 'success', __( 'Rate-limit rule updated.', 'bluefairy-api-gateway' ) ],
			'webhook_saved'           => [ 'success', __( 'Webhook saved.', 'bluefairy-api-gateway' ) ],
			'webhook_invalid'         => [ 'error', __( 'Webhook not saved: check the extra headers (one Name: value per line) and the secret (255 characters at most). Your entries are kept in the form below.', 'bluefairy-api-gateway' ) ],
			'webhook_url_invalid'     => [ 'error', __( 'URL refused: it must start with https:// and resolve to a public address (localhost, 127.0.0.1, private ranges and non-standard ports are refused). Your entries are kept in the form below.', 'bluefairy-api-gateway' ) ],
			'webhook_label_invalid'   => [ 'error', __( 'Webhook not saved: the label must be 1–200 characters. Your entries are kept in the form below.', 'bluefairy-api-gateway' ) ],
			'webhook_event_invalid'   => [ 'error', __( 'Webhook not saved: choose an event from the list (a WooCommerce event disappears when WooCommerce is deactivated). Your entries are kept in the form below.', 'bluefairy-api-gateway' ) ],
			'webhook_limit'           => [ 'error', __( 'Webhook not added: the free plugin allows three webhooks.', 'bluefairy-api-gateway' ) ],
			'webhook_deleted'         => [ 'success', __( 'Webhook and its delivery log deleted.', 'bluefairy-api-gateway' ) ],
			'webhook_not_found'       => [ 'error', __( 'That webhook no longer exists.', 'bluefairy-api-gateway' ) ],
			'webhook_toggled'         => [ 'success', __( 'Webhook updated.', 'bluefairy-api-gateway' ) ],
			'webhook_secret_shown'    => [ 'success', __( 'Webhook saved. Its signing secret is shown below — copy it into your receiver now; it is not shown again.', 'bluefairy-api-gateway' ) ],
			'webhook_test_ok'         => [ 'success', __( 'Test delivery succeeded. The receiver\'s response is in the delivery log.', 'bluefairy-api-gateway' ) ],
			'webhook_test_failed'     => [ 'error', __( 'Test delivery failed. The receiver\'s response is in the delivery log.', 'bluefairy-api-gateway' ) ],
			'settings_saved'          => [ 'success', __( 'Settings saved.', 'bluefairy-api-gateway' ) ],
			'settings_save_failed'    => [ 'error', __( 'Settings not saved: the database refused the write. Nothing was changed; try again, and check the database if it keeps failing.', 'bluefairy-api-gateway' ) ],
			'cache_purge_failed'      => [ 'error', __( 'The response cache was not purged: the database refused the write. Try again, and check the database if it keeps failing.', 'bluefairy-api-gateway' ) ],
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
		$text = (string) $notice[1];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only: the HTTP status the test send got, digits or `none`.
		$http = 'webhook_test_failed' === $code && isset( $_GET['bfag_http'] ) ? sanitize_key( wp_unslash( $_GET['bfag_http'] ) ) : '';
		if ( '' !== $http ) {
			$detail = ctype_digit( $http )
				/* translators: %s: HTTP status code */
				? sprintf( __( 'HTTP %s', 'bluefairy-api-gateway' ), $http )
				: __( 'no HTTP response: transport error', 'bluefairy-api-gateway' );
			/* translators: %s: "HTTP 500" or "no HTTP response: transport error" */
			$text = sprintf( __( 'Test delivery failed (%s). The receiver\'s response is in the delivery log.', 'bluefairy-api-gateway' ), $detail );
		}
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * Hooked to admin_notices, every screen: the tables could not be created
	 * (Installer::INSTALL_ERROR_OPTION is set until a later create_tables()
	 * succeeds). Nothing in the plugin works without them, so this is not
	 * dismissible.
	 */
	public function render_install_error_notice(): void {
		$error = get_option( Installer::INSTALL_ERROR_OPTION );
		if ( ! is_array( $error ) || ! Capabilities::current_user_can_manage() ) {
			return;
		}
		$tables = implode( ', ', array_map( 'strval', is_array( $error['tables'] ?? null ) ? $error['tables'] : [] ) );
		$detail = isset( $error['db_error'] ) && is_string( $error['db_error'] ) && '' !== $error['db_error'] ? ' (' . $error['db_error'] . ')' : '';
		echo '<div class="notice notice-error"><p>'
			/* translators: %s: comma-separated table names, optionally followed by the database error in parentheses */
			. esc_html( sprintf( __( 'API Gateway could not create its database tables: %s. Check the database user\'s CREATE privilege and reactivate.', 'bluefairy-api-gateway' ), $tables . $detail ) )
			. '</p></div>';
	}

	/**
	 * Should the trusted-proxies notice show for this request and user?
	 * Rate limiting on, no trusted proxies, a forwarded-address header on
	 * the request, and the user has not dismissed it.
	 */
	public function proxy_notice_due(): bool {
		if ( ! Capabilities::current_user_can_manage() ) {
			return false;
		}
		if ( ! Settings::to_bool( $this->settings->get( 'rate_limit_enabled' ) ) ) {
			return false;
		}
		$proxies = $this->settings->get( 'trusted_proxies' );
		if ( is_array( $proxies ) && [] !== $proxies ) {
			return false;
		}
		if ( ! $this->forwarded_header_present() ) {
			return false;
		}
		return ! Settings::to_bool( get_user_meta( get_current_user_id(), self::PROXY_NOTICE_META, true ) );
	}

	/**
	 * Hooked to admin_notices: on the plugin's screen, Plugins and the
	 * Dashboard, warn that a proxy is in front of the site and every
	 * visitor is sharing one rate-limit bucket. Dismissible per user.
	 */
	public function render_proxy_notice(): void {
		$screen = get_current_screen();
		if ( ! is_object( $screen ) || ! in_array( $screen->id, self::PROXY_NOTICE_SCREENS, true ) ) {
			return;
		}
		if ( ! $this->proxy_notice_due() ) {
			return;
		}
		$this->assets->enqueue_assets();
		$link = '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::MENU_SLUG . '&tab=' . SettingsPage::TAB ) . '#bfag-group-ratelimit' ) . '">';
		echo '<div class="notice notice-warning is-dismissible bfag-proxy-notice" data-bfag-dismiss="proxy"><p>'
			. wp_kses(
				sprintf(
					/* translators: 1: opening link tag to the Settings tab, 2: closing link tag */
					__( 'Requests reach this site through a proxy (a forwarded-address header is present) but no trusted proxies are configured, so every visitor shares one rate-limit bucket. %1$sAdd your proxy ranges under Settings → API Gateway → Settings%2$s, or switch rate limiting off.', 'bluefairy-api-gateway' ),
					$link,
					'</a>'
				),
				[ 'a' => [ 'href' => [] ] ]
			)
			. '</p></div>';
	}

	/**
	 * Hooked to wp_ajax_bfag_ajax_dismiss_notice: remember the dismissal for this user.
	 */
	public function ajax_dismiss_notice(): void {
		check_ajax_referer( 'bfag_ajax_dismiss_notice', 'nonce' );
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_send_json_error( [ 'message' => __( 'Not allowed.', 'bluefairy-api-gateway' ) ], 403 );
		}
		update_user_meta( get_current_user_id(), self::PROXY_NOTICE_META, 1 );
		wp_send_json_success( [ 'dismissed' => 'proxy' ] );
	}

	/**
	 * Does the current request carry any forwarded-address header? Only
	 * presence matters; the value is never read here.
	 */
	private function forwarded_header_present(): bool {
		foreach ( RequestContext::FORWARDED_SERVER_KEYS as $key ) {
			if ( isset( $_SERVER[ $key ] ) && '' !== $_SERVER[ $key ] ) {
				return true;
			}
		}
		return false;
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
			// CacheStore::sanitize_tag, not sanitize_key(): a Pro tag such as `post:42` must keep its colon.
			$tags = array_values(
				array_unique(
					array_filter(
						array_map(
							[ CacheStore::class, 'sanitize_tag' ],
							array_map( 'sanitize_text_field', wp_unslash( $_POST['tags'] ) )
						)
					)
				)
			);
		}
		$purged = [] === $tags ? $this->purger->purge_all() : $this->purger->purge_tags( $tags );
		if ( false === $purged ) {
			wp_send_json_error( [ 'message' => __( 'The response cache was not purged: the database refused the write.', 'bluefairy-api-gateway' ) ], 500 );
		}
		$tags = is_array( $purged ) ? $purged : [];
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
	 * Only a `failed` or `retrying` outbound row can be retried (U-10); a
	 * successful or in-flight one is refused with 400.
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
		if ( ! in_array( (string) ( $delivery['status'] ?? '' ), self::RETRYABLE_STATUSES, true ) || 'out' !== (string) ( $delivery['direction'] ?? '' ) ) {
			wp_send_json_error( [ 'message' => __( 'Only a failed outgoing delivery can be retried.', 'bluefairy-api-gateway' ) ], 400 );
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
