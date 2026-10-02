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
 * Creates the six tables with dbDelta, seeds one cache rule and one
 * rate-limit rule (a site may keep up to Limits::DEFAULT_LIMIT of each),
 * grants the capability and schedules the maintenance crons.
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
		'bfag_install_error',
		'bfag_webhook_events',
	];

	/**
	 * Options read on every request, stored autoloaded so a site without a
	 * persistent object cache pays no query for them (P7 / WPMAP-106): the
	 * version markers `maybe_upgrade()` compares on every boot, the settings,
	 * the global cache generation every cacheable REST request keys on, and
	 * the enabled-event list `EventBus` hooks at boot. All are small.
	 * `bfag_tag_generations` stays non-autoloaded: it is read only when a
	 * tagged rule matches, and it grows with the tags rules carry.
	 *
	 * @var list<string>
	 */
	public const AUTOLOAD_OPTIONS = [
		'bfag_settings',
		'bfag_db_version',
		'bfag_version',
		'bfag_cache_generation',
		'bfag_webhook_events',
	];

	/**
	 * Option that records a failed table creation: the missing table names
	 * and the database error. Present only while the tables are missing.
	 */
	public const INSTALL_ERROR_OPTION = 'bfag_install_error';

	/**
	 * Seconds a boot waits after a failed install before it tries dbDelta
	 * again (PN6 / WPMAP-115). The `at` of INSTALL_ERROR_OPTION is the last
	 * attempt; activate() always retries.
	 */
	public const INSTALL_RETRY_SECONDS = 300;

	/**
	 * The first schema version whose upgrade step nulls `next_retry_at` on
	 * finished delivery rows (PN2).
	 */
	private const RETRY_CLEANUP_VERSION = '1.0.2';

	/**
	 * The first schema version whose upgrade step turns autoload on for
	 * AUTOLOAD_OPTIONS (P7): `update_option()` changes the flag of an
	 * existing option only together with its value, so an install made
	 * before 1.0.3 would otherwise keep them off.
	 */
	private const AUTOLOAD_VERSION = '1.0.3';

	/**
	 * Recurring cron hooks and their schedules.
	 *
	 * @var array<string, string>
	 */
	public const CRON = [
		'bfag_cron_prune_hits'       => 'bfag_five_minutes',
		'bfag_cron_prune_deliveries' => 'daily',
		'bfag_cron_recompute_kpis'   => 'bfag_five_minutes',
		'bfag_cron_prune_responses'  => 'bfag_five_minutes',
	];

	/**
	 * Single (one-off) cron events the plugin schedules on demand. Cleared
	 * with the recurring ones; uninstall.php holds a copy.
	 *
	 * @var list<string>
	 */
	public const SINGLE_EVENTS = [
		'bfag_cron_flush_pending',
	];

	/**
	 * Activation hook. A network-wide activation provisions every site;
	 * otherwise only the current one.
	 *
	 * @param bool $network_wide True when activated for the whole network.
	 */
	public static function activate( bool $network_wide = false ): void {
		self::each_site( $network_wide, [ self::class, 'provision_site' ] );
	}

	/**
	 * Run $callback on every site of the network when $network_wide and
	 * this is a multisite install, else on the current site only.
	 *
	 * @param bool     $network_wide Whether the hook fired for the whole network.
	 * @param callable $callback     What to run per site.
	 */
	private static function each_site( bool $network_wide, callable $callback ): void {
		if ( ! $network_wide || ! is_multisite() ) {
			$callback();
			return;
		}
		// PN8: a large network is provisioned inline (DDL and seeds per site);
		// lift the time limit where PHP allows, and keep every site's option
		// reads out of the object cache so the loop's memory stays flat.
		// Why the limit is lifted: WordPress records a network activation only
		// after this hook returns, so a run that hits max_execution_time on a
		// network of a few hundred sites dies half-provisioned and the plugin is
		// left inactive. Reached only for a network-wide (de)activation by a
		// super admin; a single-site activation returns above without it.
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- network-wide activation must finish every site; see above.
		}
		wp_suspend_cache_addition( true );
		try {
			foreach ( get_sites(
				[
					'fields' => 'ids',
					'number' => 0,
				]
			) as $site_id ) {
				switch_to_blog( (int) $site_id );
				try {
					$callback();
				} finally {
					restore_current_blog(); // N-14: a callback that throws must not leave the request on another site.
				}
			}
		} finally {
			wp_suspend_cache_addition( false );
		}
	}

	/**
	 * Everything one site needs: the capability, settings, tables, the
	 * seeded rules, the version markers and the crons. Idempotent. Stops
	 * after a failed table creation (recorded in INSTALL_ERROR_OPTION, shown
	 * by the admin notice) so nothing is seeded into tables that do not exist
	 * and no version is recorded for an install that did not happen.
	 */
	public static function provision_site(): void {
		Capabilities::add_caps();
		if ( ! get_option( 'bfag_settings' ) ) {
			update_option( 'bfag_settings', Settings::DEFAULTS, true );
		}
		if ( ! self::create_tables() ) {
			return;
		}
		self::seed_default_rules();
		add_option( 'bfag_cache_generation', 1, '', true );
		update_option( 'bfag_version', BFAG_VERSION, true );
		update_option( 'bfag_db_version', BFAG_DB_VERSION, true );
		// A re-activated install keeps options written before 1.0.3 with autoload off.
		self::autoload_hot_options();
		self::schedule_cron();
	}

	/**
	 * Turn autoload on for every existing option in AUTOLOAD_OPTIONS,
	 * whatever its value (one statement; options not yet written are
	 * skipped and get the flag when they are created).
	 */
	private static function autoload_hot_options(): void {
		wp_set_option_autoload_values( array_fill_keys( self::AUTOLOAD_OPTIONS, true ) );
	}

	/**
	 * Hooked to `wp_initialize_site` (priority 20): provision a site created
	 * after the plugin was network-activated, which the activation hook
	 * never saw.
	 *
	 * @param \WP_Site $site The new site.
	 */
	public static function initialize_site( \WP_Site $site ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active_for_network( plugin_basename( BFAG_FILE ) ) ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		try {
			self::provision_site();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Deactivation hook. Data stays; crons and the capability go — on every
	 * site when the plugin is network-deactivated, mirroring activate().
	 *
	 * @param bool $network_wide True when deactivated for the whole network.
	 */
	public static function deactivate( bool $network_wide = false ): void {
		self::each_site( $network_wide, [ self::class, 'unprovision_site' ] );
	}

	/**
	 * Clear one site's crons and capability.
	 */
	public static function unprovision_site(): void {
		foreach ( array_keys( self::CRON ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		foreach ( self::SINGLE_EVENTS as $hook ) {
			wp_unschedule_hook( $hook );
		}
		Capabilities::remove_caps();
	}

	/**
	 * Called on every boot. A site with no DB version at all (a sub-site the
	 * activation hook never ran on) is provisioned in full; a site whose
	 * version is behind gets dbDelta, and the capability and crons are
	 * re-asserted. The version is recorded only when the tables verifiably
	 * exist, so a failed upgrade is retried on the next boot instead of
	 * being remembered as done. `bfag_version` (the plugin version, as
	 * distinct from the schema version) is brought up to date whenever it
	 * differs (F13), so a code-only release still leaves the right number
	 * behind.
	 *
	 * A broken install (INSTALL_ERROR_OPTION present) is retried at most
	 * once per INSTALL_RETRY_SECONDS rather than on every boot (PN6): six
	 * dbDelta statements, six SHOW TABLES, an option write and an error-log
	 * line per request is what a site with a missing CREATE privilege paid.
	 */
	public static function maybe_upgrade(): void {
		$current = get_option( 'bfag_db_version', '' );
		$missing = '' === $current || false === $current;
		if ( ! $missing && BFAG_VERSION !== (string) get_option( 'bfag_version', '' ) ) {
			update_option( 'bfag_version', BFAG_VERSION, true );
		}
		if ( ! $missing && version_compare( (string) $current, BFAG_DB_VERSION, '>=' ) ) {
			return;
		}
		if ( self::install_retry_throttled() ) {
			return;
		}
		if ( $missing ) {
			self::provision_site();
			return;
		}
		if ( self::create_tables() ) {
			self::upgrade_data( (string) $current );
			update_option( 'bfag_db_version', BFAG_DB_VERSION, true );
		}
		Capabilities::add_caps();
		self::schedule_cron();
	}

	/**
	 * Is a retry of a failed install still inside the back-off window?
	 */
	private static function install_retry_throttled(): bool {
		$error = get_option( self::INSTALL_ERROR_OPTION );
		if ( ! is_array( $error ) || ! isset( $error['at'] ) || ! is_numeric( $error['at'] ) ) {
			return false;
		}
		return ( time() - (int) $error['at'] ) < self::INSTALL_RETRY_SECONDS;
	}

	/**
	 * One-off data steps between schema versions, run after dbDelta and
	 * before the new version is recorded.
	 *
	 * From < 1.0.2 (PN2): `next_retry_at` was never cleared when a delivery
	 * finished, so `idx_next_retry` held every finished row and the
	 * pending-flush sweep scanned them all. Null it on the finished rows;
	 * `pending`/`retrying` rows keep theirs (they are queued or scheduled).
	 *
	 * From < 1.0.3 (P7 / WPMAP-106): the hot-path options were written with
	 * autoload off, costing a query each per request without a persistent
	 * object cache; turn it on (AUTOLOAD_OPTIONS).
	 *
	 * Settings are NOT migrated here, and `provision_site()` never rewrites
	 * an existing `bfag_settings`: a site keeps every value it was installed
	 * with. So a change to a key's DEFAULT in `Settings::DEFAULTS` reaches
	 * new installs only. When a default changes in a way existing sites
	 * must follow (a key that used to default on and now defaults off, say),
	 * add a version-gated step here that rewrites that one key — and only
	 * where the stored value is still the old default, so an administrator's
	 * deliberate choice survives. (Observed: a site installed from an early
	 * development build keeps `incoming_enabled` true; nothing shipped with
	 * that default, so no step is needed.)
	 *
	 * @param string $from The schema version being upgraded from.
	 */
	private static function upgrade_data( string $from ): void {
		global $wpdb;

		if ( version_compare( $from, self::RETRY_CLEANUP_VERSION, '<' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off upgrade step on a plugin table.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}bfag_webhook_deliveries SET next_retry_at = NULL WHERE status IN (%s, %s) AND next_retry_at IS NOT NULL",
					'success',
					'failed'
				)
			);
		}
		if ( version_compare( $from, self::AUTOLOAD_VERSION, '<' ) ) {
			self::autoload_hot_options();
		}
	}

	/**
	 * Register the five-minute schedule. Hooked to `cron_schedules`.
	 *
	 * The label is translated only once `init` has run (D6): on an upgrade
	 * boot `maybe_upgrade()` → `schedule_cron()` → `wp_schedule_event()` →
	 * `wp_get_schedules()` runs this filter on `plugins_loaded`, and a
	 * `__()` there loads the text domain early, which WP 6.7+ reports as a
	 * `_load_textdomain_just_in_time` notice. Nothing reads the label before
	 * init except that scheduling, so the untranslated string serves it.
	 *
	 * @param array<string, array{interval:int, display:string}> $schedules Existing schedules.
	 * @return array<string, array{interval:int, display:string}>
	 */
	public static function cron_schedules( array $schedules ): array {
		$schedules['bfag_five_minutes'] = [
			'interval' => 300,
			'display'  => did_action( 'init' ) > 0
				? __( 'Every five minutes (BlueFairy API Gateway)', 'bluefairy-api-gateway' )
				: 'Every five minutes (BlueFairy API Gateway)',
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
			// Seeded with one rule (route_glob '**', matching everything); up to Limits::DEFAULT_LIMIT (500) rows.
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
  KEY idx_webhook_id (webhook_id),
  KEY idx_next_retry (next_retry_at),
  KEY idx_status_retry (status,next_retry_at),
  KEY idx_webhook_dir_id (webhook_id,direction,id),
  KEY idx_prune (status,created_at),
  KEY idx_created_at (created_at)
) {$engine};",
		];
	}

	/**
	 * Run dbDelta for every table, then verify each one exists. dbDelta
	 * reports nothing useful when CREATE TABLE fails (a database user
	 * without CREATE, a full disk), so the check is `SHOW TABLES LIKE`.
	 *
	 * @return bool True when every table exists afterwards. False records the
	 *              missing names and the database error in
	 *              INSTALL_ERROR_OPTION and writes to the PHP error log
	 *              unconditionally — this is not a gateway decision but a
	 *              broken install, and the opt-in Logger would hide it.
	 */
	public static function create_tables(): bool {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// last_error is per statement, so it is read after EACH dbDelta() and
		// the first failure is kept: read once after the loop, a later table
		// that succeeded would have blanked it (R2-11).
		$db_error = '';
		foreach ( self::schema( $wpdb->prefix, $wpdb->get_charset_collate() ) as $sql ) {
			dbDelta( $sql );
			if ( '' === $db_error ) {
				$db_error = (string) $wpdb->last_error;
			}
		}

		$missing = [];
		foreach ( self::TABLES as $table ) {
			$name = $wpdb->prefix . $table;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- install-time existence check of the plugin's own tables.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
			if ( ! is_string( $found ) || 0 !== strcasecmp( $found, $name ) ) {
				$missing[] = $name;
			}
		}

		if ( [] === $missing ) {
			delete_option( self::INSTALL_ERROR_OPTION );
			return true;
		}

		// `at` is the time of this attempt: maybe_upgrade() throttles the
		// next one on it. The option is rewritten only when something it
		// carries changed — the tables, the error, or (on a retry) the time.
		$previous = get_option( self::INSTALL_ERROR_OPTION );
		$previous = is_array( $previous ) ? $previous : [];
		$same     = ( $previous['tables'] ?? null ) === $missing && ( $previous['db_error'] ?? null ) === $db_error;
		if ( ! $same || ! self::install_retry_throttled() ) {
			update_option(
				self::INSTALL_ERROR_OPTION,
				[
					'tables'   => $missing,
					'db_error' => $db_error,
					'at'       => time(),
				],
				false
			);
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- a failed install must be visible whatever the debug_logging setting.
		error_log( '[bfag] could not create tables: ' . implode( ', ', $missing ) . ( '' !== $db_error ? ' — ' . $db_error : '' ) );
		return false;
	}

	/**
	 * Seed the one cache rule and the one rate-limit rule the free plugin
	 * allows, if the tables are empty. Idempotent. The rate-limit rule is
	 * 300 requests per 60 s per IP: a ceiling for anonymous API traffic from
	 * one address, generous enough that a headless front end calling from
	 * one server is not cut off on day one.
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
					'max_requests'   => 300,
					'window_seconds' => 60,
					'enabled'        => 1,
				],
				[ '%s', '%s', '%s', '%d', '%d', '%d' ]
			);
		}
		// phpcs:enable
	}
}
