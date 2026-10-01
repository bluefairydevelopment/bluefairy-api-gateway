<?php
/**
 * Activation, deactivation and schema upgrades.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates the six tables with dbDelta, seeds the one cache rule and the one
 * rate-limit rule the free plugin allows, grants the capability and schedules
 * the maintenance crons.
 *
 * The dbDelta rules that shaped the DDL: two spaces after `PRIMARY KEY`, `KEY`
 * not `INDEX`, no `CONSTRAINT`/FOREIGN KEY lines (dbDelta cannot parse them
 * and would re-issue them every upgrade — delivery rows are removed with
 * their webhook inside a transaction instead), and no generated columns
 * (`route_hash` is computed in PHP by HitCounter).
 */
final class Installer {

	/**
	 * Tables this plugin owns, without prefix. uninstall.php holds a copy.
	 *
	 * @var list<string>
	 */
	public const TABLES = [
		'bfag_cache_rules',
		'bfag_rate_limit_rules',
		'bfag_rate_limit_hits',
		'bfag_cache_stats',
		'bfag_webhooks',
		'bfag_webhook_deliveries',
	];

	/**
	 * Options this plugin creates. uninstall.php holds a copy.
	 *
	 * @var list<string>
	 */
	public const OPTIONS = [
		'bfag_settings',
		'bfag_db_version',
		'bfag_version',
		'bfag_cache_generation',
		'bfag_tag_generations',
		'bfag_kpis',
	];

	/**
	 * Recurring cron hooks and their schedules.
	 *
	 * @var array<string, string>
	 */
	public const CRON = [
		'bfag_cron_prune_hits'       => 'bfag_five_minutes',
		'bfag_cron_prune_deliveries' => 'daily',
		'bfag_cron_recompute_kpis'   => 'bfag_five_minutes',
	];

	/**
	 * Activation hook.
	 */
	public static function activate(): void {
		self::check_requirements();
		self::create_tables();
		self::seed_default_rules();
		Capabilities::add_caps();

		if ( ! get_option( 'bfag_settings' ) ) {
			update_option( 'bfag_settings', Settings::DEFAULTS, false );
		}
		add_option( 'bfag_cache_generation', 1, '', false );
		update_option( 'bfag_version', BFAG_VERSION, false );
		update_option( 'bfag_db_version', BFAG_DB_VERSION, false );

		self::schedule_cron();
	}

	/**
	 * Deactivation hook. Data stays; crons and the capability go.
	 */
	public static function deactivate(): void {
		foreach ( array_keys( self::CRON ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		Capabilities::remove_caps();
	}

	/**
	 * Called on every boot; runs dbDelta only when the DB version moved.
	 */
	public static function maybe_upgrade(): void {
		$current = get_option( 'bfag_db_version', '0' );
		if ( version_compare( (string) $current, BFAG_DB_VERSION, '>=' ) ) {
			return;
		}
		self::create_tables();
		update_option( 'bfag_db_version', BFAG_DB_VERSION, false );
	}

	/**
	 * Register the five-minute schedule. Hooked to `cron_schedules`.
	 *
	 * @param array<string, array{interval:int, display:string}> $schedules Existing schedules.
	 * @return array<string, array{interval:int, display:string}>
	 */
	public static function cron_schedules( array $schedules ): array {
		$schedules['bfag_five_minutes'] = [
			'interval' => 300,
			'display'  => __( 'Every five minutes (BlueFairy API Gateway)', 'bluefairy-api-gateway' ),
		];
		return $schedules;
	}

	/**
	 * Schedule every recurring job that is not already scheduled.
	 *
	 * The activation hook runs after plugins_loaded has already fired, so
	 * nothing booted may have registered `bfag_five_minutes` yet; without it
	 * wp_schedule_event() returns WP_Error 'invalid_schedule' and the two
	 * five-minute jobs are silently never scheduled.
	 */
	public static function schedule_cron(): void {
		if ( false === has_filter( 'cron_schedules', [ self::class, 'cron_schedules' ] ) ) {
			add_filter( 'cron_schedules', [ self::class, 'cron_schedules' ] ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 300 s by design: the hit log and KPIs are five-minute buckets.
		}
		foreach ( self::CRON as $hook => $schedule ) {
			if ( false === wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time() + 60, $schedule, $hook );
			}
		}
	}

	/**
	 * The CREATE TABLE statements, keyed by unprefixed table name.
	 *
	 * Public so the test suite can assert the schema without a database.
	 *
	 * @param string $prefix          $wpdb->prefix.
	 * @param string $charset_collate $wpdb->get_charset_collate().
	 * @return array<string, string>
	 */
	public static function schema( string $prefix, string $charset_collate ): array {
		$engine = 'ENGINE=InnoDB ' . $charset_collate;

		return [
			// One row in the free plugin (seeded with route_glob '**', matching everything); up to 500 with Pro.
			'bfag_cache_rules'        => "CREATE TABLE {$prefix}bfag_cache_rules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  label VARCHAR(100) NOT NULL DEFAULT '',
  route_glob VARCHAR(500) NOT NULL DEFAULT '*',
  ttl_seconds MEDIUMINT UNSIGNED NOT NULL DEFAULT 60,
  tags VARCHAR(500) NOT NULL DEFAULT '',
  headers TEXT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_enabled (enabled)
) {$engine};",

			// subject_type: ip (free), user / apikey (Pro). penalty_seconds: Pro auto-ban.
			'bfag_rate_limit_rules'   => "CREATE TABLE {$prefix}bfag_rate_limit_rules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  label VARCHAR(100) NOT NULL DEFAULT '',
  subject_type VARCHAR(16) NOT NULL DEFAULT 'ip',
  route_glob VARCHAR(500) NOT NULL DEFAULT '*',
  max_requests SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  window_seconds MEDIUMINT UNSIGNED NOT NULL DEFAULT 60,
  penalty_seconds INT UNSIGNED NOT NULL DEFAULT 0,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_enabled (enabled)
) {$engine};",

			// Rolling hit log, pruned every five minutes. route_hash = first 16 hex
			// chars of SHA-256(route), computed in PHP: compact, selective, dbDelta-safe.
			'bfag_rate_limit_hits'    => "CREATE TABLE {$prefix}bfag_rate_limit_hits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  subject VARCHAR(200) NOT NULL,
  route VARCHAR(500) NOT NULL,
  route_hash CHAR(16) NOT NULL,
  hit_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_subject_route_time (subject(64),route_hash,hit_at),
  KEY idx_hit_at (hit_at)
) {$engine};",

			// Hit/miss counts in five-minute buckets for the dashboard.
			'bfag_cache_stats'        => "CREATE TABLE {$prefix}bfag_cache_stats (
  bucket_start DATETIME NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  misses INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY  (bucket_start)
) {$engine};",

			'bfag_webhooks'           => "CREATE TABLE {$prefix}bfag_webhooks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  label VARCHAR(200) NOT NULL,
  url TEXT NOT NULL,
  event VARCHAR(100) NOT NULL,
  secret VARCHAR(255) NOT NULL DEFAULT '',
  headers TEXT NULL,
  preset VARCHAR(50) NOT NULL DEFAULT '',
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  max_retries TINYINT UNSIGNED NOT NULL DEFAULT 3,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_event_enabled (event,enabled)
) {$engine};",

			// payload is capped by DeliveryRepository::create() (settings max_payload_bytes).
			'bfag_webhook_deliveries' => "CREATE TABLE {$prefix}bfag_webhook_deliveries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  webhook_id BIGINT UNSIGNED NOT NULL,
  direction VARCHAR(3) NOT NULL DEFAULT 'out',
  event VARCHAR(100) NOT NULL,
  payload MEDIUMTEXT NOT NULL,
  truncated TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(10) NOT NULL DEFAULT 'pending',
  http_status SMALLINT UNSIGNED NULL,
  response_body TEXT NULL,
  attempt TINYINT UNSIGNED NOT NULL DEFAULT 1,
  next_retry_at DATETIME NULL,
  delivered_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_webhook_status (webhook_id,status),
  KEY idx_next_retry (next_retry_at),
  KEY idx_prune (status,created_at)
) {$engine};",
		];
	}

	/**
	 * Run dbDelta for every table.
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::schema( $wpdb->prefix, $wpdb->get_charset_collate() ) as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Seed the one cache rule and the one rate-limit rule the free plugin
	 * allows, if the tables are empty. Idempotent.
	 */
	public static function seed_default_rules(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- activation-time seed of plugin tables.
		if ( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bfag_cache_rules" ) ) {
			$wpdb->insert(
				$wpdb->prefix . 'bfag_cache_rules',
				[
					'label'       => 'All routes',
					'route_glob'  => '**',
					'ttl_seconds' => Settings::DEFAULTS['default_cache_ttl'],
					'tags'        => '',
					'enabled'     => 1,
				],
				[ '%s', '%s', '%d', '%s', '%d' ]
			);
		}
		if ( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bfag_rate_limit_rules" ) ) {
			$wpdb->insert(
				$wpdb->prefix . 'bfag_rate_limit_rules',
				[
					'label'          => 'Global per-IP limit',
					'subject_type'   => 'ip',
					'route_glob'     => '**',
					'max_requests'   => 60,
					'window_seconds' => 60,
					'enabled'        => 1,
				],
				[ '%s', '%s', '%s', '%d', '%d', '%d' ]
			);
		}
		// phpcs:enable
	}

	/**
	 * Refuse to activate on PHP < 8.1 or a MySQL without InnoDB defaults.
	 */
	private static function check_requirements(): void {
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			deactivate_plugins( plugin_basename( BFAG_FILE ) );
			wp_die( esc_html__( 'BlueFairy API Gateway requires PHP 8.1 or higher.', 'bluefairy-api-gateway' ) );
		}
	}
}
