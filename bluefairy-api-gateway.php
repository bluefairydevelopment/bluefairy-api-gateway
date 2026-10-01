<?php
/**
 * Plugin Name:       BlueFairy API Gateway
 * Plugin URI:        https://wordpress.bluefairydevelopment.com/product/bluefairy-api-gateway/
 * Description:       REST response caching, per-IP rate limiting and signed outbound webhooks for WordPress — a hardened API layer for headless sites and integrations. Add BlueFairy API Gateway Pro for per-content-type cache rules, tag purging, DDoS auto-ban, webhook retries, CRM presets and API keys.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Blue Fairy Development
 * Author URI:        https://bluefairydevelopment.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bluefairy-api-gateway
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BFAG_FILE', __FILE__ );
define( 'BFAG_DIR', plugin_dir_path( __FILE__ ) );
define( 'BFAG_URL', plugin_dir_url( __FILE__ ) );
define( 'BFAG_VERSION', '1.0.0' );
define( 'BFAG_DB_VERSION', '1.0.0' );

// Autoloader — no Composer required at runtime. Only this plugin's namespace;
// the Pro add-on loads its own classes.
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'BlueFairy\\ApiGateway\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = __DIR__ . '/includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

// Activation and deactivation hooks MUST be at file top level (not inside any hook).
register_activation_hook( __FILE__, [ BlueFairy\ApiGateway\Installer::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ BlueFairy\ApiGateway\Installer::class, 'deactivate' ] );

// The five-minute schedule is registered at file scope, not in Plugin::boot():
// the activation hook fires after plugins_loaded, so a filter added there is
// not yet in place when Installer::activate() schedules the crons.
add_filter( 'cron_schedules', [ BlueFairy\ApiGateway\Installer::class, 'cron_schedules' ] ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- 300 s, declared in Installer::cron_schedules().

/**
 * Accessor used by the Pro add-on to confirm the base plugin is really loaded.
 *
 * BFAG_VERSION alone is not proof: a constant can be defined by anything. Pro
 * checks for this function AND the constant, as SEO Toolkit Pro checks
 * bfst_plugin() and BFST_VERSION.
 */
function bfag_plugin(): string {
	return BFAG_VERSION;
}

// Boot after all plugins have loaded. Plugin::boot() fires `bfag_loaded`
// before it binds any subsystem, which is where the Pro add-on hooks in.
add_action( 'plugins_loaded', [ BlueFairy\ApiGateway\Plugin::class, 'boot' ], 10 );
