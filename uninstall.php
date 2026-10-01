<?php
/**
 * Uninstall routine for BlueFairy API Gateway.
 *
 * Standalone: no autoloader, inline SQL only. WordPress runs this file in a
 * bare context where the plugin's classes are not loaded.
 *
 * Always removes options, transients, cron events and the capability. Drops
 * the six custom tables ONLY when the admin ticked "Delete all data on
 * uninstall" in Settings — webhook definitions and delivery logs are the
 * customer's data, and deleting them by default is not a cleanup, it is a loss.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Tables this plugin creates (without prefix). Installer::TABLES must list the
 * same six: an uninstall that forgets one leaves data behind, and one that
 * names a table it never created would drop something belonging to the Pro
 * add-on, whose tables carry the bfagp_ prefix precisely so this cannot.
 */
$bfag_tables = [
	'bfag_cache_rules',
	'bfag_rate_limit_rules',
	'bfag_rate_limit_hits',
	'bfag_cache_stats',
	'bfag_webhooks',
	'bfag_webhook_deliveries',
];

/**
 * Every option this plugin creates, and nothing else. Installer::OPTIONS must
 * stay equal to this list in both directions.
 */
$bfag_option_keys = [
	'bfag_settings',
	'bfag_db_version',
	'bfag_version',
	'bfag_cache_generation',
	'bfag_tag_generations',
	'bfag_kpis',
];

$bfag_cron_hooks = [
	'bfag_cron_prune_hits',
	'bfag_cron_prune_deliveries',
	'bfag_cron_recompute_kpis',
];

/**
 * Remove everything for the current site.
 *
 * Declared as a closure rather than a named function so it cannot collide
 * with the Pro add-on's uninstall running in the same request.
 */
$bfag_uninstall_site = static function () use ( $bfag_tables, $bfag_option_keys, $bfag_cron_hooks ): void {
	global $wpdb;

	$settings    = get_option( 'bfag_settings', [] );
	$delete_data = is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] );

	if ( $delete_data ) {
		foreach ( $bfag_tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping the plugin's own tables on an opted-in uninstall.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table ) );
		}
	}

	foreach ( $bfag_option_keys as $key ) {
		delete_option( $key );
	}

	// Transients: the dashboard KPI cache. (Tag generations are an option,
	// deleted above; a transient in an external object cache could not be).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_bfag_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_bfag_' ) . '%'
		)
	);

	foreach ( $bfag_cron_hooks as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}

	$role = get_role( 'administrator' );
	if ( $role instanceof WP_Role ) {
		$role->remove_cap( 'bfag_manage_gateway' );
	}

	if ( function_exists( 'wp_cache_flush_group' ) ) {
		wp_cache_flush_group( 'bfag' );
		wp_cache_flush_group( 'bfag_responses' );
	}
};

// Multisite awareness: page through EVERY site, not the first hundred.
$bfag_site_ids = [ null ];
if ( is_multisite() && function_exists( 'get_sites' ) ) {
	$bfag_site_ids = [];
	$bfag_offset   = 0;
	do {
		$bfag_batch    = get_sites(
			[
				'number'  => 200,
				'offset'  => $bfag_offset,
				'fields'  => 'ids',
				'orderby' => 'id',
			]
		);
		$bfag_site_ids = array_merge( $bfag_site_ids, $bfag_batch );
		$bfag_offset  += 200;
		$bfag_got      = count( $bfag_batch );
	} while ( 200 === $bfag_got );
}

foreach ( $bfag_site_ids as $bfag_site ) {
	if ( $bfag_site ) {
		switch_to_blog( (int) $bfag_site );
	}
	$bfag_uninstall_site();
	if ( $bfag_site ) {
		restore_current_blog();
	}
}
