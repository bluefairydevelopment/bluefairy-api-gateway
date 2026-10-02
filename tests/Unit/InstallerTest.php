<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit;

use BlueFairy\ApiGateway\Capabilities;
use BlueFairy\ApiGateway\Installer;
use BlueFairy\ApiGateway\Plugin;
use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Tests\TestCase;

final class InstallerTest extends TestCase {

	public function test_schema_defines_exactly_the_six_owned_tables_as_innodb(): void {
		$schema = Installer::schema( 'bfw_', 'DEFAULT CHARSET utf8mb4' );
		$this->assertSame( Installer::TABLES, array_keys( $schema ) );
		foreach ( $schema as $table => $sql ) {
			$this->assertStringStartsWith( "CREATE TABLE bfw_{$table} (", $sql );
			$this->assertStringContainsString( 'ENGINE=InnoDB DEFAULT CHARSET utf8mb4', $sql );
			$this->assertStringContainsString( 'PRIMARY KEY  (', $sql, 'dbDelta needs two spaces after PRIMARY KEY' );
			$this->assertStringNotContainsString( 'CONSTRAINT', $sql, 'dbDelta cannot parse FK constraints' );
			$this->assertStringNotContainsString( 'GENERATED', $sql, 'dbDelta cannot manage generated columns' );
			$this->assertStringNotContainsString( ' INDEX ', $sql, 'dbDelta wants KEY, not INDEX' );
		}
		$this->assertStringContainsString( 'route_hash CHAR(16) NOT NULL', $schema['bfag_rate_limit_hits'] );
		$this->assertStringContainsString( 'payload MEDIUMTEXT NOT NULL', $schema['bfag_webhook_deliveries'] );
		$this->assertStringContainsString( 'truncated TINYINT(1)', $schema['bfag_webhook_deliveries'] );
		$this->assertStringContainsString( "  KEY idx_webhook_id (webhook_id),\n", $schema['bfag_webhook_deliveries'], 'P8: last_status / per-webhook log read by webhook_id ORDER BY id' );
		$this->assertStringContainsString( "  KEY idx_created_at (created_at)\n", $schema['bfag_webhook_deliveries'], 'P14: recent() orders by created_at' );
		$this->assertStringContainsString( "  KEY idx_status_retry (status,next_retry_at),\n", $schema['bfag_webhook_deliveries'], 'PN2: pending() filters on status then next_retry_at' );
		$this->assertStringContainsString( "  KEY idx_webhook_dir_id (webhook_id,direction,id),\n", $schema['bfag_webhook_deliveries'], 'PN3: last_statuses() groups MAX(id) by webhook_id under direction = out — a loose index scan' );
		// N-16: the harness reads BFAG_DB_VERSION from the plugin file; the shipped value is what the tests see.
		$this->assertSame( 1, preg_match( "/define\\( 'BFAG_DB_VERSION', '([^']+)' \\);/", (string) file_get_contents( BFAG_FILE ), $v ) );
		$this->assertSame( '1.0.3', $v[1], 'bumped so an existing install runs the upgrade steps (1.0.2 keys, 1.0.3 autoload)' );
		$this->assertSame( $v[1], BFAG_DB_VERSION, 'the harness constant is the plugin file\'s, not a mirror' );
	}

	public function test_create_tables_runs_db_delta_once_per_table(): void {
		Installer::create_tables();
		$this->assertCount( 6, $GLOBALS['bfag_test_db_delta'] );
	}

	public function test_activate_seeds_options_caps_rules_and_cron(): void {
		$this->wpdb->queue( 'get_var', '0' );
		$this->wpdb->queue( 'get_var', '0' );
		Installer::activate();

		$this->assertSame( Settings::DEFAULTS, get_option( 'bfag_settings' ) );
		$this->assertSame( 1, (int) get_option( 'bfag_cache_generation' ) );
		$this->assertSame( BFAG_VERSION, get_option( 'bfag_version' ) );
		$this->assertSame( BFAG_DB_VERSION, get_option( 'bfag_db_version' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );

		$inserts = $this->wpdb->calls_to( 'insert' );
		$this->assertCount( 2, $inserts );
		$this->assertSame( 'bfw_bfag_cache_rules', $inserts[0]['table'] );
		$this->assertSame( '**', $inserts[0]['data']['route_glob'] );
		$this->assertSame( 'bfw_bfag_rate_limit_rules', $inserts[1]['table'] );
		$this->assertSame( 'ip', $inserts[1]['data']['subject_type'] );

		foreach ( Installer::CRON as $hook => $schedule ) {
			$this->assertNotFalse( wp_next_scheduled( $hook ), $hook );
			$this->assertSame( $schedule, $GLOBALS['bfag_test_cron'][ $hook ]['recurrence'] );
		}
	}

	public function test_activate_schedules_the_five_minute_jobs_without_plugin_boot(): void {
		// The activation hook fires after plugins_loaded: nothing has registered the schedule yet.
		$this->assertFalse( has_filter( 'cron_schedules' ) );
		$this->assertArrayNotHasKey( 'bfag_five_minutes', wp_get_schedules() );
		$this->assertFalse( wp_schedule_event( time(), 'bfag_five_minutes', 'bfag_probe' ), 'the harness refuses an unregistered recurrence, as core does' );
		$this->assertArrayNotHasKey( 'bfag_probe', $GLOBALS['bfag_test_cron'] );

		$this->wpdb->always( 'get_var', '0' );
		Installer::activate();

		$this->assertSame( [ 'bfag_cron_prune_hits', 'bfag_cron_prune_deliveries', 'bfag_cron_recompute_kpis', 'bfag_cron_prune_responses' ], array_keys( $GLOBALS['bfag_test_cron'] ) );
		$this->assertSame( 'bfag_five_minutes', $GLOBALS['bfag_test_cron']['bfag_cron_prune_hits']['recurrence'] );
		$this->assertSame( 'bfag_five_minutes', $GLOBALS['bfag_test_cron']['bfag_cron_prune_responses']['recurrence'] );
		$this->assertSame( 'daily', $GLOBALS['bfag_test_cron']['bfag_cron_prune_deliveries']['recurrence'] );
		$this->assertSame( 'bfag_five_minutes', $GLOBALS['bfag_test_cron']['bfag_cron_recompute_kpis']['recurrence'] );
		$this->assertSame( 10, has_filter( 'cron_schedules', [ Installer::class, 'cron_schedules' ] ), 'activation registered the schedule itself' );
		$this->assertSame( 300, wp_get_schedules()['bfag_five_minutes']['interval'] );
	}

	public function test_activate_does_not_reseed_or_overwrite_existing_settings(): void {
		update_option( 'bfag_settings', [ 'default_cache_ttl' => 120 ] );
		update_option( 'bfag_cache_generation', 9 );
		$this->wpdb->queue( 'get_var', '1' );
		$this->wpdb->queue( 'get_var', '3' );
		Installer::activate();
		$this->assertSame( [ 'default_cache_ttl' => 120 ], get_option( 'bfag_settings' ) );
		$this->assertSame( 9, (int) get_option( 'bfag_cache_generation' ) );
		$this->assertCount( 0, $this->wpdb->calls_to( 'insert' ) );
	}

	public function test_deactivate_clears_cron_and_capability(): void {
		$this->wpdb->always( 'get_var', '0' );
		Installer::activate();
		wp_schedule_single_event( time(), 'bfag_cron_flush_pending' );
		wp_schedule_single_event( time() + 5, 'bfag_cron_flush_pending', [ 'args' ] );
		Installer::deactivate();
		foreach ( array_keys( Installer::CRON ) as $hook ) {
			$this->assertFalse( wp_next_scheduled( $hook ), $hook );
		}
		$this->assertSame( [], $GLOBALS['bfag_test_single_events'], 'every pending flush event, whatever its args, is unscheduled' );
		$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );
		$this->assertSame( Settings::DEFAULTS, get_option( 'bfag_settings' ), 'data survives deactivation' );
	}

	/**
	 * F13: a code-only release (same schema) still brings bfag_version up
	 * to date on the next boot; nothing else runs.
	 */
	public function test_maybe_upgrade_writes_the_plugin_version_when_it_differs_without_touching_the_schema(): void {
		update_option( 'bfag_db_version', BFAG_DB_VERSION );
		update_option( 'bfag_version', '0.9.0' );
		Installer::maybe_upgrade();
		$this->assertSame( BFAG_VERSION, get_option( 'bfag_version' ) );
		$this->assertCount( 0, $GLOBALS['bfag_test_db_delta'], 'schema untouched' );
		$this->assertCount( 0, $this->wpdb->calls_to( 'insert' ), 'nothing reseeded' );
	}

	/**
	 * F13: the unreachable PHP-version gate is gone — `Requires PHP` in the
	 * header is what WordPress enforces.
	 */
	public function test_check_requirements_is_gone(): void {
		$this->assertFalse( method_exists( Installer::class, 'check_requirements' ) );
		$this->assertStringNotContainsString( 'check_requirements', (string) file_get_contents( BFAG_DIR . 'includes/Installer.php' ) );
	}

	/**
	 * F14: a network deactivation clears the crons and the capability on
	 * every site, like activate(); a single-site one touches only the
	 * current site.
	 */
	public function test_network_deactivation_unprovisions_every_site(): void {
		$GLOBALS['bfag_test_multisite'] = true;
		$GLOBALS['bfag_test_sites']     = [ 1, 2, 3 ];
		$this->wpdb->always( 'get_var', '0' );
		Installer::activate( true );
		$GLOBALS['bfag_test_switched'] = [];

		Installer::deactivate( false );
		$this->assertSame( [], $GLOBALS['bfag_test_switched'], 'single-site: no switch' );
		$this->assertSame( [], $GLOBALS['bfag_test_cron'], 'site 1 cleared' );
		$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );
		$this->assertSame( array_keys( Installer::CRON ), array_keys( $this->site_state( 2 )['cron'] ), 'site 2 untouched' );
		$this->assertTrue( $this->site_state( 2 )['roles']['administrator']->has_cap( Capabilities::MANAGE ) );

		Installer::deactivate( true );
		$this->assertSame( [ 1, 2, 3 ], $GLOBALS['bfag_test_switched'] );
		$this->assertSame( 1, get_current_blog_id(), 'restored to the main site' );
		foreach ( [ 1, 2, 3 ] as $id ) {
			$state = $this->site_state( $id );
			$this->assertSame( [], $state['cron'], "site {$id}: cron cleared" );
			$this->assertFalse( $state['roles']['administrator']->has_cap( Capabilities::MANAGE ), "site {$id}: capability removed" );
			$this->assertSame( Settings::DEFAULTS, $state['options']['bfag_settings'], "site {$id}: data stays" );
		}
	}

	public function test_maybe_upgrade_runs_only_when_db_version_is_behind(): void {
		update_option( 'bfag_db_version', BFAG_DB_VERSION );
		Installer::maybe_upgrade();
		$this->assertCount( 0, $GLOBALS['bfag_test_db_delta'] );

		update_option( 'bfag_db_version', '0.9.0' );
		Installer::maybe_upgrade();
		$this->assertCount( 6, $GLOBALS['bfag_test_db_delta'] );
		$this->assertSame( BFAG_DB_VERSION, get_option( 'bfag_db_version' ) );
	}

	public function test_cron_schedules_adds_five_minute_interval(): void {
		$schedules = Installer::cron_schedules( [] );
		$this->assertSame( 300, $schedules['bfag_five_minutes']['interval'] );
	}

	/**
	 * D6: `wp_get_schedules()` runs this filter from `maybe_upgrade()` →
	 * `schedule_cron()` on `plugins_loaded`, before `init`; a `__()` there
	 * trips `_load_textdomain_just_in_time` on WP 6.7+. The label is the
	 * plain string until init has run, and translated afterwards.
	 */
	public function test_cron_schedules_label_is_not_translated_before_init_and_is_after(): void {
		add_filter(
			'gettext',
			static function ( string $translation, string $text ): string {
				return '[translated] ' . $text;
			},
			10,
			2
		);
		$this->assertSame( 0, did_action( 'init' ), 'control: before init' );
		$before = Installer::cron_schedules( [] )['bfag_five_minutes'];
		$this->assertSame( 'Every five minutes (BlueFairy API Gateway)', $before['display'], 'before init: the literal — no gettext call' );
		$this->assertSame( 300, $before['interval'] );

		do_action( 'init' );
		$after = Installer::cron_schedules( [] )['bfag_five_minutes'];
		$this->assertSame( '[translated] Every five minutes (BlueFairy API Gateway)', $after['display'], 'after init: translated' );
		$this->assertSame( 300, $after['interval'] );
	}

	public function test_schedule_cron_is_idempotent(): void {
		Installer::schedule_cron();
		$first = $GLOBALS['bfag_test_cron'];
		Installer::schedule_cron();
		$this->assertSame( $first, $GLOBALS['bfag_test_cron'] );
	}

	public function test_table_and_option_lists_match_uninstall_php(): void {
		$source = (string) file_get_contents( BFAG_DIR . 'uninstall.php' );
		preg_match( '/\$bfag_tables = \[(.*?)\];/s', $source, $t );
		preg_match( '/\$bfag_option_keys = \[(.*?)\];/s', $source, $o );
		preg_match( '/\$bfag_cron_hooks = \[(.*?)\];/s', $source, $c );
		preg_match( '/\$bfag_single_events = \[(.*?)\];/s', $source, $e );
		$extract = static fn( string $block ): array => array_map( static fn( string $s ): string => trim( $s, "'" ), preg_split( '/[\s,]+/', trim( $block ), -1, PREG_SPLIT_NO_EMPTY ) ?: [] );
		$this->assertSame( Installer::TABLES, $extract( $t[1] ?? '' ) );
		$this->assertSame( Installer::OPTIONS, $extract( $o[1] ?? '' ) );
		$this->assertSame( array_keys( Installer::CRON ), $extract( $c[1] ?? '' ) );
		$this->assertSame( Installer::SINGLE_EVENTS, $extract( $e[1] ?? '' ) );
		$this->assertSame( [ 'bfag_cron_flush_pending' ], Installer::SINGLE_EVENTS );
	}

	// ------------------------------------------------------------------
	// U-01b: the seeded rate-limit rule
	// ------------------------------------------------------------------

	public function test_the_seeded_rate_limit_rule_is_300_per_60_seconds_per_ip_on_every_route(): void {
		$this->wpdb->queue( 'get_var', '0' );
		$this->wpdb->queue( 'get_var', '0' );
		Installer::seed_default_rules();
		$rule = $this->wpdb->calls_to( 'insert' )[1];
		$this->assertSame( 'bfw_bfag_rate_limit_rules', $rule['table'] );
		$this->assertSame( 'Global per-IP limit', $rule['data']['label'] );
		$this->assertSame( 300, $rule['data']['max_requests'] );
		$this->assertSame( 60, $rule['data']['window_seconds'] );
		$this->assertSame( '**', $rule['data']['route_glob'] );
		$this->assertSame( 'ip', $rule['data']['subject_type'] );
	}

	// ------------------------------------------------------------------
	// F2: dbDelta's result is verified
	// ------------------------------------------------------------------

	public function test_create_tables_verifies_every_table_with_show_tables_and_reports_success(): void {
		$this->assertTrue( Installer::create_tables() );
		$shows = $this->wpdb->queries_matching( 'SHOW TABLES LIKE' );
		$this->assertCount( 6, $shows );
		foreach ( Installer::TABLES as $i => $table ) {
			$this->assertSame( "SHOW TABLES LIKE 'bfw_{$table}'", str_replace( '\\', '', $shows[ $i ] ), 'the name is esc_like()d and prepared' );
		}
		$this->assertFalse( get_option( Installer::INSTALL_ERROR_OPTION ) );
	}

	public function test_a_missing_table_leaves_the_version_unwritten_records_the_error_and_a_later_success_clears_it(): void {
		ini_set( 'error_log', '/dev/null' );
		$this->wpdb->missing_tables( [ 'bfag_webhooks' ] );
		$this->wpdb->last_error = "CREATE command denied to user 'wp'@'localhost'";

		Installer::activate();

		$this->assertFalse( get_option( 'bfag_db_version' ), 'no version for an install that did not happen' );
		$this->assertFalse( get_option( 'bfag_version' ) );
		$this->assertCount( 0, $this->wpdb->calls_to( 'insert' ), 'nothing seeded into tables that do not exist' );
		$this->assertSame( [], $GLOBALS['bfag_test_cron'], 'no crons for a broken install' );
		$error = get_option( Installer::INSTALL_ERROR_OPTION );
		$this->assertIsArray( $error );
		$this->assertSame( [ 'bfw_bfag_webhooks' ], $error['tables'] );
		$this->assertSame( "CREATE command denied to user 'wp'@'localhost'", $error['db_error'] );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ), 'the capability comes first, so the admin sees the notice and the settings' );
		$this->assertSame( Settings::DEFAULTS, get_option( 'bfag_settings' ) );

		// Reactivated once the privilege is granted: the error clears and the install completes.
		$this->wpdb->missing_tables( [] );
		$this->wpdb->last_error = '';
		$this->wpdb->queue( 'get_var', '0' );
		$this->wpdb->queue( 'get_var', '0' );
		Installer::activate();
		$this->assertFalse( get_option( Installer::INSTALL_ERROR_OPTION ), 'cleared by the later success' );
		$this->assertSame( BFAG_DB_VERSION, get_option( 'bfag_db_version' ) );
		$this->assertCount( 2, $this->wpdb->calls_to( 'insert' ) );
		$this->assertSame( array_keys( Installer::CRON ), array_keys( $GLOBALS['bfag_test_cron'] ) );
	}

	/**
	 * R2-11: `$wpdb->last_error` holds the LAST statement's error, so it is
	 * read after each dbDelta() and the first failure is what the install
	 * error records — not the blank a later, successful table left behind.
	 */
	public function test_create_tables_records_the_first_db_error_of_the_six_statements(): void {
		ini_set( 'error_log', '/dev/null' );
		$this->wpdb->missing_tables( [ 'bfag_rate_limit_rules' ] );
		$GLOBALS['bfag_test_db_delta_errors'] = [ '', "CREATE command denied to user 'wp'@'localhost'", '', 'Table is full', '', '' ];

		$this->assertFalse( Installer::create_tables() );
		$this->assertCount( 6, $GLOBALS['bfag_test_db_delta'], 'control: six statements ran' );
		$this->assertSame( '', $this->wpdb->last_error, 'control: the last statement succeeded, so a read after the loop would see nothing' );
		$error = get_option( Installer::INSTALL_ERROR_OPTION );
		$this->assertIsArray( $error );
		$this->assertSame( "CREATE command denied to user 'wp'@'localhost'", $error['db_error'], 'the first failure, not the last' );
	}

	public function test_an_upgrade_whose_tables_are_missing_keeps_the_old_version_so_the_next_boot_retries(): void {
		ini_set( 'error_log', '/dev/null' );
		update_option( 'bfag_db_version', '0.9.0' );
		$this->wpdb->missing_tables( [ 'bfag_cache_stats' ] );

		Installer::maybe_upgrade();

		$this->assertSame( '0.9.0', get_option( 'bfag_db_version' ) );
		$this->assertSame( [ 'bfw_bfag_cache_stats' ], get_option( Installer::INSTALL_ERROR_OPTION )['tables'] );
		$this->assertCount( 6, $GLOBALS['bfag_test_db_delta'] );

		$this->wpdb->missing_tables( [] );
		$this->age_install_error( Installer::INSTALL_RETRY_SECONDS + 1 ); // PN6: a boot inside the back-off would not retry.
		Installer::maybe_upgrade();
		$this->assertSame( BFAG_DB_VERSION, get_option( 'bfag_db_version' ), 'control: the next boot completes the upgrade' );
		$this->assertFalse( get_option( Installer::INSTALL_ERROR_OPTION ) );
	}

	public function test_install_error_is_an_owned_option(): void {
		$this->assertSame( 'bfag_install_error', Installer::INSTALL_ERROR_OPTION );
		$this->assertContains( Installer::INSTALL_ERROR_OPTION, Installer::OPTIONS );
		$this->assertNotContains( 'bfag_kpis', Installer::OPTIONS, 'N-09: bfag_kpis is a transient, not an option' );
	}

	/**
	 * Move the recorded install failure $seconds into the past.
	 */
	private function age_install_error( int $seconds ): void {
		$error = get_option( Installer::INSTALL_ERROR_OPTION );
		$this->assertIsArray( $error, 'precondition: an install error is recorded' );
		$error['at'] -= $seconds;
		update_option( Installer::INSTALL_ERROR_OPTION, $error, false );
	}

	// ------------------------------------------------------------------
	// PN6 / WPMAP-115: a broken install backs off instead of retrying every boot
	// ------------------------------------------------------------------

	public function test_a_broken_install_is_retried_once_per_back_off_window_not_on_every_boot(): void {
		ini_set( 'error_log', '/dev/null' );
		$this->assertSame( 300, Installer::INSTALL_RETRY_SECONDS );
		$this->wpdb->missing_tables( [ 'bfag_webhooks' ] );
		$this->wpdb->always( 'get_var', '0' );

		Installer::maybe_upgrade(); // First boot: no version, provision, fail.
		$this->assertCount( 6, $GLOBALS['bfag_test_db_delta'] );
		$first = get_option( Installer::INSTALL_ERROR_OPTION );
		$this->assertEqualsWithDelta( time(), $first['at'], 5 );

		// Second boot, seconds later: nothing runs — no dbDelta, no SHOW TABLES, no option write.
		$GLOBALS['bfag_test_db_delta'] = [];
		$this->wpdb->calls             = [];
		Installer::maybe_upgrade();
		$this->assertCount( 0, $GLOBALS['bfag_test_db_delta'], 'inside the back-off: not retried' );
		$this->assertSame( [], $this->wpdb->queries_matching( 'SHOW TABLES' ) );
		$this->assertSame( $first, get_option( Installer::INSTALL_ERROR_OPTION ), 'the record is untouched' );
		$this->assertFalse( get_option( 'bfag_db_version' ) );

		// The same boot, with the failure older than the window: retried, and the attempt time moves.
		$this->age_install_error( Installer::INSTALL_RETRY_SECONDS + 1 );
		Installer::maybe_upgrade();
		$this->assertCount( 6, $GLOBALS['bfag_test_db_delta'], 'past the back-off: retried' );
		$this->assertEqualsWithDelta( time(), get_option( Installer::INSTALL_ERROR_OPTION )['at'], 5, 'the attempt time is refreshed, so the next back-off starts now' );

		// Activation always retries, back-off or not.
		$GLOBALS['bfag_test_db_delta'] = [];
		Installer::activate();
		$this->assertCount( 6, $GLOBALS['bfag_test_db_delta'], 'activate() ignores the back-off' );

		// A site behind on the schema (not a fresh install) is throttled the same way.
		$GLOBALS['bfag_test_db_delta'] = [];
		update_option( 'bfag_db_version', '0.9.0' );
		Installer::maybe_upgrade();
		$this->assertCount( 0, $GLOBALS['bfag_test_db_delta'], 'an upgrade inside the back-off is not retried either' );
		$this->assertSame( '0.9.0', get_option( 'bfag_db_version' ) );

		// Healthy install: the back-off never enters into it.
		$this->wpdb->missing_tables( [] );
		delete_option( Installer::INSTALL_ERROR_OPTION );
		update_option( 'bfag_db_version', BFAG_DB_VERSION );
		Installer::maybe_upgrade();
		$this->assertCount( 0, $GLOBALS['bfag_test_db_delta'], 'control: up to date, nothing to do' );
		update_option( 'bfag_db_version', '0.9.0' );
		Installer::maybe_upgrade();
		$this->assertCount( 6, $GLOBALS['bfag_test_db_delta'], 'control: behind with no error recorded — upgraded at once' );
		$this->assertSame( BFAG_DB_VERSION, get_option( 'bfag_db_version' ) );
	}

	public function test_the_install_error_is_rewritten_only_when_it_changes_or_a_retry_is_due(): void {
		ini_set( 'error_log', '/dev/null' );
		$this->wpdb->missing_tables( [ 'bfag_webhooks' ] );
		$this->wpdb->last_error = 'denied';
		Installer::activate();
		$first = get_option( Installer::INSTALL_ERROR_OPTION );
		$this->assertSame( 'denied', $first['db_error'] );

		// Same tables, same error, inside the window (an activation retry): the record stands, its time included.
		$first['at'] -= 10;
		update_option( Installer::INSTALL_ERROR_OPTION, $first, false );
		Installer::activate();
		$this->assertSame( $first, get_option( Installer::INSTALL_ERROR_OPTION ), 'unchanged content inside the window is not rewritten' );

		// A different error: rewritten at once.
		$this->wpdb->last_error = 'disk full';
		Installer::activate();
		$this->assertSame( 'disk full', get_option( Installer::INSTALL_ERROR_OPTION )['db_error'] );
		$this->assertEqualsWithDelta( time(), get_option( Installer::INSTALL_ERROR_OPTION )['at'], 5 );

		// Different tables: rewritten.
		$this->wpdb->missing_tables( [ 'bfag_webhooks', 'bfag_cache_stats' ] );
		Installer::activate();
		$this->assertSame( [ 'bfw_bfag_cache_stats', 'bfw_bfag_webhooks' ], get_option( Installer::INSTALL_ERROR_OPTION )['tables'] );
	}

	// ------------------------------------------------------------------
	// PN2: the 1.0.2 data step
	// ------------------------------------------------------------------

	public function test_upgrading_from_before_1_0_2_nulls_next_retry_at_on_finished_deliveries_once(): void {
		update_option( 'bfag_db_version', '1.0.1' );
		Installer::maybe_upgrade();
		$this->assertSame(
			[ "UPDATE bfw_bfag_webhook_deliveries SET next_retry_at = NULL WHERE status IN ('success', 'failed') AND next_retry_at IS NOT NULL" ],
			$this->wpdb->queries_matching( 'next_retry_at = NULL' ),
			'finished rows leave idx_next_retry; pending and retrying rows keep their due time'
		);
		$this->assertSame( BFAG_DB_VERSION, get_option( 'bfag_db_version' ) );

		$this->wpdb->calls = [];
		Installer::maybe_upgrade();
		$this->assertSame( [], $this->wpdb->queries_matching( 'next_retry_at = NULL' ), 'once: the version is recorded, the step does not run again' );

		// The step belongs to the < 1.0.2 transition only.
		$step = new \ReflectionMethod( Installer::class, 'upgrade_data' );
		$this->wpdb->calls = [];
		$step->invoke( null, '1.0.2' );
		$step->invoke( null, '1.0.3' );
		$this->assertSame( [], $this->wpdb->queries_matching( 'next_retry_at' ), 'from 1.0.2 or later: nothing' );
		$step->invoke( null, '0.9.0' );
		$this->assertCount( 1, $this->wpdb->queries_matching( 'next_retry_at = NULL' ), 'control: from any earlier version it runs' );

		// Tables that failed to create: no data step, no version.
		ini_set( 'error_log', '/dev/null' );
		$this->wpdb->calls = [];
		$this->wpdb->missing_tables( [ 'bfag_webhook_deliveries' ] );
		update_option( 'bfag_db_version', '1.0.1' );
		delete_option( Installer::INSTALL_ERROR_OPTION );
		Installer::maybe_upgrade();
		$this->assertSame( [], $this->wpdb->queries_matching( 'next_retry_at' ), 'no UPDATE against a table that is not there' );
		$this->assertSame( '1.0.1', get_option( 'bfag_db_version' ) );
	}

	// ------------------------------------------------------------------
	// N-14 / PN8: each_site()
	// ------------------------------------------------------------------

	public function test_each_site_restores_the_current_site_and_cache_addition_even_when_a_site_throws(): void {
		$GLOBALS['bfag_test_multisite'] = true;
		$GLOBALS['bfag_test_sites']     = [ 1, 2, 3 ];
		$each                           = new \ReflectionMethod( Installer::class, 'each_site' );
		$visited                        = [];

		try {
			$each->invoke(
				null,
				true,
				static function () use ( &$visited ): void {
					$visited[] = get_current_blog_id();
					if ( 2 === get_current_blog_id() ) {
						throw new \RuntimeException( 'site 2 exploded' );
					}
				}
			);
			self::fail( 'the exception must propagate' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'site 2 exploded', $e->getMessage() );
		}
		$this->assertSame( [ 1, 2 ], $visited );
		$this->assertSame( 1, get_current_blog_id(), 'N-14: restored to the main site in finally' );
		$this->assertSame( [], $GLOBALS['bfag_test_blog_stack'], 'nothing left on the switch stack' );
		$this->assertSame( [ true, false ], $GLOBALS['bfag_test_suspended'], 'PN8: cache addition suspended around the loop and resumed in finally' );

		// Control: a clean run visits every site, resumes the cache, and a single-site run never suspends it.
		$GLOBALS['bfag_test_suspended'] = [];
		$visited                        = [];
		$each->invoke( null, true, static function () use ( &$visited ): void {
			$visited[] = get_current_blog_id();
		} );
		$this->assertSame( [ 1, 2, 3 ], $visited );
		$this->assertSame( [ true, false ], $GLOBALS['bfag_test_suspended'] );
		$GLOBALS['bfag_test_suspended'] = [];
		$each->invoke( null, false, static function (): void {} );
		$this->assertSame( [], $GLOBALS['bfag_test_suspended'], 'single site: no suspension' );

		// set_time_limit() is a PHP builtin the harness cannot intercept: pin the guarded call in the source.
		$source = (string) file_get_contents( BFAG_DIR . 'includes/Installer.php' );
		$this->assertMatchesRegularExpression( '/if \( function_exists\( \'set_time_limit\' \) \) \{\s*set_time_limit\( 0 \);/', $source, 'PN8: the time limit is lifted where PHP allows' );
		// WPMAP-142: Plugin Check warns on set_time_limit(); the one call carries a targeted, explained ignore, and there is no other.
		$this->assertMatchesRegularExpression( '/set_time_limit\( 0 \); \/\/ phpcs:ignore Squiz\.PHP\.DiscouragedFunctions\.Discouraged -- \S.+$/m', $source, 'the set_time_limit() call states why it is needed' );
		$this->assertSame( 1, substr_count( $source, 'set_time_limit(' ), 'one call — no unexplained set_time_limit() elsewhere' );
	}

	// ------------------------------------------------------------------
	// F1: multisite
	// ------------------------------------------------------------------

	/**
	 * The options, cron and roles of one site as the harness holds them.
	 *
	 * @return array<string, mixed>
	 */
	private function site_state( int $id ): array {
		if ( get_current_blog_id() === $id ) {
			return [
				'options' => $GLOBALS['bfag_test_options'],
				'cron'    => $GLOBALS['bfag_test_cron'],
				'roles'   => $GLOBALS['bfag_test_roles'],
			];
		}
		return $GLOBALS['bfag_test_site_state'][ $id ];
	}

	public function test_network_activation_provisions_every_site(): void {
		$GLOBALS['bfag_test_multisite'] = true;
		$GLOBALS['bfag_test_sites']     = [ 1, 2, 3 ];
		$this->wpdb->always( 'get_var', '0' );

		Installer::activate( true );

		$this->assertSame( [ 1, 2, 3 ], $GLOBALS['bfag_test_switched'] );
		$this->assertSame( 1, get_current_blog_id(), 'restored to the main site' );
		$this->assertSame( 'bfw_', $this->wpdb->prefix );
		$this->assertCount( 18, $GLOBALS['bfag_test_db_delta'], 'six tables per site' );
		$this->assertSame(
			[ 'bfw_bfag_cache_rules', 'bfw_bfag_rate_limit_rules', 'bfw_2_bfag_cache_rules', 'bfw_2_bfag_rate_limit_rules', 'bfw_3_bfag_cache_rules', 'bfw_3_bfag_rate_limit_rules' ],
			array_column( $this->wpdb->calls_to( 'insert' ), 'table' ),
			'the two rules are seeded into every site\'s own tables'
		);
		foreach ( [ 1, 2, 3 ] as $id ) {
			$state = $this->site_state( $id );
			$this->assertSame( Settings::DEFAULTS, $state['options']['bfag_settings'], "site {$id}: settings" );
			$this->assertSame( BFAG_DB_VERSION, $state['options']['bfag_db_version'], "site {$id}: version" );
			$this->assertTrue( $state['roles']['administrator']->has_cap( Capabilities::MANAGE ), "site {$id}: capability" );
			$this->assertSame( array_keys( Installer::CRON ), array_keys( $state['cron'] ), "site {$id}: cron" );
		}
	}

	public function test_a_single_site_activation_on_multisite_provisions_only_the_current_site(): void {
		$GLOBALS['bfag_test_multisite'] = true;
		$GLOBALS['bfag_test_sites']     = [ 1, 2, 3 ];
		$this->wpdb->always( 'get_var', '0' );

		Installer::activate( false );

		$this->assertSame( [], $GLOBALS['bfag_test_switched'], 'no site switch' );
		$this->assertSame( [ 'bfw_bfag_cache_rules', 'bfw_bfag_rate_limit_rules' ], array_column( $this->wpdb->calls_to( 'insert' ), 'table' ) );
		$this->assertArrayNotHasKey( 2, $GLOBALS['bfag_test_site_state'] );
	}

	public function test_network_wide_on_a_single_site_install_is_an_ordinary_activation(): void {
		$GLOBALS['bfag_test_sites'] = [ 1, 2, 3 ];
		$this->wpdb->always( 'get_var', '0' );

		Installer::activate( true );

		$this->assertFalse( is_multisite() );
		$this->assertSame( [], $GLOBALS['bfag_test_switched'] );
		$this->assertCount( 2, $this->wpdb->calls_to( 'insert' ) );
		$this->assertSame( BFAG_DB_VERSION, get_option( 'bfag_db_version' ) );
	}

	public function test_maybe_upgrade_on_a_site_with_no_version_provisions_it_in_full(): void {
		$this->wpdb->always( 'get_var', '0' );
		$this->assertFalse( get_option( 'bfag_db_version' ) );

		Installer::maybe_upgrade();

		$this->assertCount( 6, $GLOBALS['bfag_test_db_delta'] );
		$this->assertCount( 2, $this->wpdb->calls_to( 'insert' ), 'rules seeded' );
		$this->assertSame( Settings::DEFAULTS, get_option( 'bfag_settings' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );
		$this->assertSame( array_keys( Installer::CRON ), array_keys( $GLOBALS['bfag_test_cron'] ) );
		$this->assertSame( BFAG_DB_VERSION, get_option( 'bfag_db_version' ) );
		$this->assertSame( BFAG_VERSION, get_option( 'bfag_version' ) );
	}

	public function test_maybe_upgrade_on_a_site_behind_reasserts_the_capability_and_crons_without_reseeding(): void {
		update_option( 'bfag_db_version', '0.9.0' );
		$this->wpdb->always( 'get_var', '0' );

		Installer::maybe_upgrade();

		$this->assertCount( 6, $GLOBALS['bfag_test_db_delta'] );
		$this->assertCount( 0, $this->wpdb->calls_to( 'insert' ), 'an upgrade never reseeds' );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );
		$this->assertSame( array_keys( Installer::CRON ), array_keys( $GLOBALS['bfag_test_cron'] ) );
		$this->assertSame( BFAG_DB_VERSION, get_option( 'bfag_db_version' ) );
	}

	public function test_a_site_created_after_network_activation_is_provisioned_on_wp_initialize_site(): void {
		$GLOBALS['bfag_test_multisite'] = true;
		$this->wpdb->always( 'get_var', '0' );

		Plugin::boot();
		$this->assertSame( 20, has_action( 'wp_initialize_site', [ Installer::class, 'initialize_site' ] ) );
		$this->wpdb->calls             = []; // boot() provisioned the main site; not what this test is about.
		$GLOBALS['bfag_test_db_delta'] = [];

		do_action( 'wp_initialize_site', new \WP_Site( 5 ), [] );

		$this->assertSame( [ 5 ], $GLOBALS['bfag_test_switched'] );
		$this->assertSame( 1, get_current_blog_id(), 'restored' );
		$this->assertCount( 6, $GLOBALS['bfag_test_db_delta'] );
		$this->assertSame( [ 'bfw_5_bfag_cache_rules', 'bfw_5_bfag_rate_limit_rules' ], array_column( $this->wpdb->calls_to( 'insert' ), 'table' ) );
		$state = $GLOBALS['bfag_test_site_state'][5];
		$this->assertSame( BFAG_DB_VERSION, $state['options']['bfag_db_version'] );
		$this->assertTrue( $state['roles']['administrator']->has_cap( Capabilities::MANAGE ) );
		$this->assertSame( array_keys( Installer::CRON ), array_keys( $state['cron'] ) );

		// Active on some sites only, not the network: a new site is left alone.
		$GLOBALS['bfag_test_network_active'] = false;
		$this->wpdb->calls                   = [];
		do_action( 'wp_initialize_site', new \WP_Site( 6 ), [] );
		$this->assertSame( [ 5 ], $GLOBALS['bfag_test_switched'] );
		$this->assertSame( [], $this->wpdb->calls );
		$this->assertArrayNotHasKey( 6, $GLOBALS['bfag_test_site_state'] );
	}
}
