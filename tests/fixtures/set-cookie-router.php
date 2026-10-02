<?php
/**
 * U-22 / WPMAP-111: a router for `php -S` (the cli-server SAPI records
 * headers_list(); the CLI SAPI that runs PHPUnit never does).
 *
 * Drives the plugin's real CacheManager on the `rest_post_dispatch` chain in
 * core's order — the REST callback runs, then `rest_post_dispatch`, then
 * WP_REST_Server sends the headers — with a callback that calls PHP's
 * setcookie() when `?cookie=1`, as WooCommerce's cart session does. Prints
 * one JSON line: the X-BF-Cache label, the stored entry count and the
 * Set-Cookie lines PHP held when the filter ran.
 *
 * Used by tests/Unit/Cache/PhpSetCookieTest.php.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

// phpcs:ignoreFile

use BlueFairy\ApiGateway\Cache\CacheKeyGenerator;
use BlueFairy\ApiGateway\Cache\CacheManager;
use BlueFairy\ApiGateway\Cache\CacheRuleRepository;
use BlueFairy\ApiGateway\Cache\CacheStatsRepository;
use BlueFairy\ApiGateway\Cache\CacheStore;
use BlueFairy\ApiGateway\Settings;

$bfag_set_cookie = '1' === ( $_GET['cookie'] ?? '' ); // Read before bfag_test_reset() empties $_GET.

require dirname( __DIR__ ) . '/bootstrap.php';

bfag_test_reset();
update_option( 'bfag_settings', Settings::DEFAULTS );
$GLOBALS['wpdb']->always(
	'get_results',
	[
		[
			'id'          => 1,
			'label'       => 'All routes',
			'route_glob'  => '**',
			'ttl_seconds' => 60,
			'tags'        => '',
			'headers'     => null,
			'enabled'     => 1,
			'created_at'  => '',
		],
	]
);
$bfag_store = new CacheStore();
( new CacheManager( $bfag_store, new CacheKeyGenerator( $bfag_store ), new CacheRuleRepository(), new CacheStatsRepository(), new Settings() ) )->register_hooks();
$bfag_request = new WP_REST_Request( 'GET', '/wc/store/v1/cart' );

// The REST callback.
if ( $bfag_set_cookie ) {
	setcookie( 'wp_woocommerce_session_abc', 'guest-1-session', 0, '/' );
}
$bfag_response = new WP_REST_Response( [ 'cart' => 'guest-1 items' ], 200, [ 'Content-Type' => 'application/json' ] );

// Core: rest_post_dispatch, before any header is sent.
$bfag_out = apply_filters( 'rest_post_dispatch', $bfag_response, new WP_REST_Server(), $bfag_request );

header( 'Content-Type: application/json' );
echo json_encode(
	[
		'sapi'        => PHP_SAPI,
		'label'       => $bfag_out->get_headers()['X-BF-Cache'] ?? null,
		'stored'      => count( $GLOBALS['bfag_test_transients'] ),
		'set_cookies' => array_values( array_filter( headers_list(), static fn( string $h ): bool => 0 === stripos( $h, 'Set-Cookie' ) ) ),
	]
);
