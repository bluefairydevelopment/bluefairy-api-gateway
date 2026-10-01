<?php
/**
 * The Settings tab.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BlueFairy\ApiGateway\Settings;

/**
 * Every `Settings::DEFAULTS` key as a field, grouped, then the add-on's own
 * rows through `bfag_settings_fields`. Saving goes through
 * Settings::update() — the only sanitiser — and then `bfag_settings_saved`
 * carries the raw POST to the add-on.
 */
final class SettingsPage extends Page {

	public const TAB = 'settings';

	/**
	 * Constructor.
	 *
	 * @param Settings       $settings Plugin settings.
	 * @param ProUpsell|null $upsell   Pro state (the Pro-only fields hide without it); null builds one.
	 */
	public function __construct( private readonly Settings $settings, ?ProUpsell $upsell = null ) {
		$this->upsell = $upsell ?? new ProUpsell();
	}

	/**
	 * Pro state.
	 *
	 * @var ProUpsell
	 */
	private ProUpsell $upsell;

	/**
	 * Tab id.
	 */
	public function tab(): string {
		return self::TAB;
	}

	/**
	 * Actions this tab handles.
	 *
	 * @return list<string>
	 */
	public function actions(): array {
		return [ 'save_settings' ];
	}

	/**
	 * Field definitions, grouped. Every key of Settings::DEFAULTS appears
	 * exactly once; the test asserts it.
	 *
	 * A field flagged `pro_only` is rendered as a hidden input (its value
	 * kept) unless the Pro add-on is active: nothing in the free plugin
	 * reads it, so showing it would be a promise (U-23).
	 *
	 * @return array<string, array{label:string, note?:string, fields:array<string, array{label:string, type:string, description:string, min?:int, max?:int, options?:array<string, string>, pro_only?:bool}>}>
	 */
	public function groups(): array {
		return [
			'general'   => [
				'label'  => __( 'General', 'bluefairy-api-gateway' ),
				'fields' => [
					'notification_email' => [
						'label'       => __( 'Notification email', 'bluefairy-api-gateway' ),
						'type'        => 'email',
						'pro_only'    => true,
						'description' => __( 'Where the Pro add-on sends failure alerts. Leave blank to use the site admin email.', 'bluefairy-api-gateway' ),
					],
					'failure_threshold'  => [
						'label'       => __( 'Failure threshold', 'bluefairy-api-gateway' ),
						'type'        => 'number',
						'pro_only'    => true,
						'min'         => 1,
						'max'         => 100,
						'description' => __( 'Consecutive webhook failures before the Pro add-on raises an alert. The free plugin stores this but sends nothing.', 'bluefairy-api-gateway' ),
					],
				],
			],
			'cache'     => [
				'label'  => __( 'Cache', 'bluefairy-api-gateway' ),
				'note'   => __( 'Without a persistent object cache (Redis, Memcached) responses are stored as transients in the options table and capped at 64 KB and one hour.', 'bluefairy-api-gateway' ),
				'fields' => [
					'cache_enabled'     => [
						'label'       => __( 'Enable response cache', 'bluefairy-api-gateway' ),
						'type'        => 'checkbox',
						'description' => __( 'Serve anonymous GET responses from the cache when a rule matches.', 'bluefairy-api-gateway' ),
					],
					'default_cache_ttl' => [
						'label'       => __( 'New-rule TTL (seconds)', 'bluefairy-api-gateway' ),
						'type'        => 'number',
						'min'         => 1,
						'max'         => 86400,
						'description' => __( 'New-rule TTL — pre-fills the TTL of a new cache rule. Every rule carries its own TTL once saved.', 'bluefairy-api-gateway' ),
					],
				],
			],
			'ratelimit' => [
				'label'  => __( 'Rate limit', 'bluefairy-api-gateway' ),
				'fields' => [
					'rate_limit_enabled'        => [
						'label'       => __( 'Enable rate limiting', 'bluefairy-api-gateway' ),
						'type'        => 'checkbox',
						'description' => __( 'Return 429 when a subject exceeds a matching rule.', 'bluefairy-api-gateway' ),
					],
					'rate_limit_exempt_editors' => [
						'label'       => __( 'Exempt editors', 'bluefairy-api-gateway' ),
						'type'        => 'checkbox',
						'description' => __( 'Do not rate-limit logged-in users who can edit posts (the block editor\'s own REST calls).', 'bluefairy-api-gateway' ),
					],
					'trusted_proxies'           => [
						'label'       => __( 'Trusted proxies', 'bluefairy-api-gateway' ),
						'type'        => 'textarea',
						'description' => __( 'One IP or CIDR per line. Needed behind Cloudflare, a load balancer or a CDN. Without it the proxy\'s address is the client IP and all visitors share one bucket; with it, the chosen header is read only from these addresses so visitors cannot forge their IP.', 'bluefairy-api-gateway' ),
					],
					'forwarded_header'          => [
						'label'       => __( 'Client address header', 'bluefairy-api-gateway' ),
						'type'        => 'select',
						'options'     => array_combine( Settings::FORWARDED_HEADERS, Settings::FORWARDED_HEADERS ),
						'description' => __( 'The header your proxy writes the real client address into. Cloudflare: CF-Connecting-IP. Only this header is read, and only when the request comes from a trusted proxy — so a visitor cannot pick their own address.', 'bluefairy-api-gateway' ),
					],
				],
			],
			'webhooks'  => [
				'label'  => __( 'Webhooks', 'bluefairy-api-gateway' ),
				'fields' => [
					'max_payload_bytes' => [
						'label'       => __( 'Max stored payload (bytes)', 'bluefairy-api-gateway' ),
						'type'        => 'number',
						'min'         => 1024,
						'max'         => 16777215,
						'description' => __( 'Longer payloads are sent in full but stored truncated, and cannot be retried.', 'bluefairy-api-gateway' ),
					],
					'retention_days'    => [
						'label'       => __( 'Delivery log retention (days)', 'bluefairy-api-gateway' ),
						'type'        => 'number',
						'min'         => 1,
						'max'         => 365,
						'description' => __( 'Finished deliveries older than this are pruned daily.', 'bluefairy-api-gateway' ),
					],
				],
			],
			'advanced'  => [
				'label'  => __( 'Advanced', 'bluefairy-api-gateway' ),
				'fields' => [
					'debug_logging'            => [
						'label'       => __( 'Debug logging', 'bluefairy-api-gateway' ),
						'type'        => 'checkbox',
						'description' => __( 'Write gateway decisions to the PHP error log.', 'bluefairy-api-gateway' ),
					],
					'disable_cron_notice'      => [
						'label'       => __( 'Hide the WP-Cron notice', 'bluefairy-api-gateway' ),
						'type'        => 'checkbox',
						'description' => __( 'Tick when a system cron runs wp-cron.php and DISABLE_WP_CRON is intentional.', 'bluefairy-api-gateway' ),
					],
					'delete_data_on_uninstall' => [
						'label'       => __( 'Delete data on uninstall', 'bluefairy-api-gateway' ),
						'type'        => 'checkbox',
						'description' => __( 'Drop the plugin tables and options when the plugin is deleted.', 'bluefairy-api-gateway' ),
					],
				],
			],
		];
	}

	/**
	 * Print the tab.
	 */
	public function render(): void {
		$this->view(
			'settings',
			[
				'groups'   => $this->groups(),
				'values'   => $this->settings->all(),
				'settings' => $this->settings,
				'is_pro'   => $this->upsell->is_pro(),
				'form_url' => $this->url(),
			]
		);
	}

	/**
	 * Hooked to admin_notices: warn when WP-Cron is off and the admin has not said so
	 * is intentional. Only on the plugin's own screen.
	 */
	public function render_cron_notice(): void {
		if ( ! $this->cron_notice_due() ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! is_object( $screen ) || Assets::SCREEN_ID !== $screen->id ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>'
			. esc_html__( 'WP-Cron is disabled (DISABLE_WP_CRON). The gateway prunes its logs and recomputes dashboard figures on WP-Cron: make sure a system cron runs wp-cron.php, then tick \'Hide the WP-Cron notice\' on this page\'s Settings tab (Advanced).', 'bluefairy-api-gateway' )
			. '</p></div>';
	}

	/**
	 * Should the WP-Cron notice show?
	 */
	public function cron_notice_due(): bool {
		if ( ! defined( 'DISABLE_WP_CRON' ) || ! constant( 'DISABLE_WP_CRON' ) ) {
			return false;
		}
		return ! Settings::to_bool( $this->settings->get( 'disable_cron_notice' ) );
	}

	/**
	 * Dispatch a verified action.
	 *
	 * @param string               $action Action name.
	 * @param array<string, mixed> $fields Sanitised fields (unused: Settings sanitises its own).
	 * @param array<string, mixed> $raw    Unslashed POST.
	 */
	protected function run_action( string $action, array $fields, array $raw ): void {
		unset( $fields );
		if ( 'save_settings' !== $action ) {
			$this->redirect( 'error' );
		}
		$values = isset( $raw['bfag'] ) && is_array( $raw['bfag'] ) ? $raw['bfag'] : [];
		$clean  = $this->settings->update( $values );

		/**
		 * The base settings were saved. The add-on stores its own fields
		 * from $raw here.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $clean What Settings stored.
		 * @param array<string, mixed> $raw   The whole unslashed POST body.
		 */
		do_action( 'bfag_settings_saved', $clean, $raw );

		$this->redirect( 'settings_saved' );
	}
}
