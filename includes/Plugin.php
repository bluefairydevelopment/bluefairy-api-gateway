<?php
/**
 * Plugin bootstrap — wires all subsystems.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BlueFairy\ApiGateway\Support\RequestContext;

/**
 * Registered as the plugins_loaded callback. Instantiates subsystems, wires
 * hooks and exposes the instances to the Pro add-on through service().
 *
 * Order matters: `bfag_loaded` fires BEFORE any subsystem binds so the add-on
 * can attach its filters first — a webhook limit raised after
 * WebhookRepository has already read it is a limit raised too late.
 */
final class Plugin {

	/**
	 * Re-entrancy guard for webhook dispatch. True while EventBus is
	 * delivering; a `save_post` fired from inside a delivery must not start
	 * another (review finding A5).
	 *
	 * @var bool
	 */
	public static bool $dispatching = false;

	/**
	 * Guard against booting twice.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Shared instances, keyed by id.
	 *
	 * @var array<string, object>
	 */
	private static array $services = [];

	/**
	 * Boot the plugin on plugins_loaded.
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		Installer::maybe_upgrade();
		// A recurring job lost to a cron wipe or a restore is booked again (on init).
		add_action( 'init', [ Installer::class, 'ensure_cron' ] );
		// A site created after a network activation is provisioned as it is born.
		add_action( 'wp_initialize_site', [ Installer::class, 'initialize_site' ], 20 );

		$settings = new Settings();
		Logger::use_settings( $settings );
		$context = new RequestContext( $settings );
		self::set( 'settings', $settings );
		self::set( 'request', $context );

		/**
		 * The base plugin is ready to be extended.
		 *
		 * Fired before the subsystems register. Add-ons hook here to attach
		 * the `bfag_*` filters that raise limits, add subject types, supply
		 * purge tags, register presets and add admin tabs. Does not fire when
		 * the base plugin is inactive, so an add-on must also check the
		 * dependency on plugins_loaded if it wants to explain itself.
		 *
		 * @since 1.0.0
		 */
		do_action( 'bfag_loaded' );

		// Cache.
		$store       = new Cache\CacheStore();
		$cache_rules = new Cache\CacheRuleRepository();
		$stats       = new Cache\CacheStatsRepository();
		$cache       = new Cache\CacheManager( $store, new Cache\CacheKeyGenerator( $store ), $cache_rules, $stats, $settings );
		$cache->register_hooks();
		$purger = new Cache\CachePurger( $store, $cache_rules );
		$purger->register_hooks();
		( new Cache\PruneCron( $store ) )->register_hooks();
		self::set( 'cache.store', $store );
		self::set( 'cache.rules', $cache_rules );
		self::set( 'cache.stats', $stats );
		self::set( 'cache.manager', $cache );
		self::set( 'cache.purger', $purger );

		// Rate limiting.
		$rl_rules = new RateLimit\RateLimitRuleRepository();
		$hits     = new RateLimit\HitCounter();
		( new RateLimit\RateLimitMiddleware( $rl_rules, $hits, $settings, $context ) )->register_hooks();
		( new RateLimit\PruneCron( $hits, $rl_rules ) )->register_hooks();
		self::set( 'ratelimit.rules', $rl_rules );
		self::set( 'ratelimit.hits', $hits );

		// Webhooks.
		$webhooks   = new Webhook\WebhookRepository();
		$deliveries = new Webhook\DeliveryRepository( $settings );
		$dispatcher = new Webhook\WebhookDispatcher( $deliveries, $settings, $webhooks );
		$registry   = new Webhook\EventRegistry();
		( new Webhook\EventBus( $webhooks, $dispatcher, $registry, $deliveries ) )->register_hooks();
		( new Webhook\PruneCron( $deliveries, $settings ) )->register_hooks();
		self::set( 'webhook.repository', $webhooks );
		self::set( 'webhook.deliveries', $deliveries );
		self::set( 'webhook.dispatcher', $dispatcher );
		self::set( 'webhook.events', $registry );

		// Dashboard KPIs: one instance, shared with the admin's Refresh button.
		$kpi_cron = new Admin\KpiCron( $stats, $deliveries, $hits );
		$kpi_cron->register_hooks();
		self::set( 'kpi.cron', $kpi_cron );

		// REST.
		$status = new Rest\StatusController( $webhooks );
		$purge  = new Rest\CacheController( $purger );
		add_action( 'rest_api_init', [ $status, 'register_routes' ] );
		add_action( 'rest_api_init', [ $purge, 'register_routes' ] );

		// Admin.
		if ( is_admin() ) {
			( new Admin\AdminMenu( $settings, $purger, $cache_rules, $rl_rules, $webhooks, $deliveries, $dispatcher, $registry, $kpi_cron ) )->register_hooks();
		}

		/**
		 * Every subsystem is wired. Add-ons that need an instance (a repository,
		 * the cache store) fetch it with Plugin::service().
		 *
		 * @since 1.0.0
		 */
		do_action( 'bfag_booted' );
	}

	/**
	 * A shared instance by id, or null.
	 *
	 * @param string $id Service id, e.g. 'cache.store'.
	 */
	public static function service( string $id ): ?object {
		return self::$services[ $id ] ?? null;
	}

	/**
	 * Register a shared instance.
	 *
	 * @param string $id       Service id.
	 * @param object $instance The instance.
	 */
	public static function set( string $id, object $instance ): void {
		self::$services[ $id ] = $instance;
	}

	/**
	 * Reset boot state. A test seam, like RequestContext::reset() and
	 * Logger::clear(): nothing in the plugin calls it.
	 */
	public static function reset(): void {
		self::$booted      = false;
		self::$dispatching = false;
		self::$services    = [];
	}
}
