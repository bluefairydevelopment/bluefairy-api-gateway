<?php
/**
 * Capability registration and the route → scope map.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One capability gates every admin page, AJAX handler and write endpoint.
 *
 * It is `bfag_manage_gateway`, granted to administrators on activation.
 *
 * required_scope() is the map an API-key holder is checked against by the Pro
 * add-on's scope enforcement. It lives in the base so the two plugins cannot
 * disagree about which route needs which scope.
 */
final class Capabilities {

	public const MANAGE = 'bfag_manage_gateway';

	/**
	 * Scope tokens, in the order the admin UI lists them.
	 *
	 * @var list<string>
	 */
	public const SCOPES = [ 'read', 'write', 'cache:purge', 'webhook:read', 'webhook:write' ];

	/**
	 * One-line description of each scope, keyed like SCOPES, for the Pro
	 * add-on's API-key form. A key acts as the user it is bound to, so
	 * `write` is as powerful as that user's REST access.
	 *
	 * @var array<string, string>
	 */
	public const SCOPE_LABELS = [
		'read'          => 'Read any route',
		'write'         => 'Create, update and delete through any REST route (acts as the key\'s user)',
		'cache:purge'   => 'Purge the response cache (POST /bfag/v1/cache/purge)',
		'webhook:read'  => 'List webhooks and their deliveries (GET /bfag/v1/webhooks…)',
		'webhook:write' => 'Create, update, delete and retry webhooks (/bfag/v1/webhooks…); implies webhook:read',
	];

	/**
	 * Grant the capability to the administrator role.
	 */
	public static function add_caps(): void {
		$role = get_role( 'administrator' );
		if ( $role instanceof \WP_Role ) {
			$role->add_cap( self::MANAGE );
		}
	}

	/**
	 * Revoke the capability from the administrator role.
	 */
	public static function remove_caps(): void {
		$role = get_role( 'administrator' );
		if ( $role instanceof \WP_Role ) {
			$role->remove_cap( self::MANAGE );
		}
	}

	/**
	 * Can the current user manage the gateway?
	 */
	public static function current_user_can_manage(): bool {
		return current_user_can( self::MANAGE );
	}

	/**
	 * The scope token a route requires. Inside `/bfag/v1`, the purge and
	 * webhook routes have their own scopes and anything else (e.g. `/status`)
	 * needs `read`. Outside `/bfag/v1` — every other REST route on the site —
	 * GET/HEAD/OPTIONS need `read` and any other method needs `write`, so a
	 * key holding only `read` can never `POST /wp/v2/posts` as its user.
	 *
	 * @param string      $route  REST route, with or without the leading slash.
	 * @param string|null $method HTTP method; null reads REQUEST_METHOD. Pass
	 *                            WP_REST_Request::get_method() so an
	 *                            X-HTTP-Method-Override is honoured.
	 */
	public static function required_scope( string $route, ?string $method = null ): string {
		// Lower-cased because WP_REST_Server matches routes case-insensitively: `/BFAG/v1/webhooks` reaches the webhooks controller.
		$route = '/' . ltrim( strtolower( $route ), '/' );
		return match ( true ) {
			str_starts_with( $route, '/bfag/v1/cache/purge' ) => 'cache:purge',
			str_starts_with( $route, '/bfag/v1/webhooks' )    => self::is_read_method( $method ) ? 'webhook:read' : 'webhook:write',
			str_starts_with( $route, '/bfag/v1/' )            => 'read',
			default                                            => self::is_read_method( $method ) ? 'read' : 'write',
		};
	}

	/**
	 * Does a space-separated permission string satisfy a required scope?
	 * `webhook:write` implies `webhook:read`; nothing else implies anything —
	 * in particular `write` does not imply `read`, and neither implies
	 * `cache:purge`.
	 *
	 * @param string $permissions Space-separated scope tokens the key holds.
	 * @param string $required    The scope the route needs.
	 */
	public static function scope_satisfied( string $permissions, string $required ): bool {
		$split = preg_split( '/\s+/', trim( $permissions ) );
		$held  = array_filter( false === $split ? [] : $split );
		if ( in_array( $required, $held, true ) ) {
			return true;
		}
		return 'webhook:read' === $required && in_array( 'webhook:write', $held, true );
	}

	/**
	 * The permission callback of the gateway's own routes. A user with the
	 * manage capability passes; so does a request the Pro add-on
	 * authenticated with an API key whose scope satisfies the route's
	 * required scope, even when that key is bound to no user — a
	 * `cache:purge` key bound to "Nobody" must be able to purge, and a
	 * `read` key must not (N3).
	 *
	 * @param \WP_REST_Request $request The request; the add-on sets
	 *                                  `bfag_api_key_scope` on it.
	 */
	public static function request_allowed( \WP_REST_Request $request ): bool {
		if ( self::current_user_can_manage() ) {
			return true;
		}
		$scope = $request->get_param( 'bfag_api_key_scope' );
		if ( ! is_string( $scope ) || '' === trim( $scope ) ) {
			return false;
		}
		return self::scope_satisfied( $scope, self::required_scope( $request->get_route(), $request->get_method() ) );
	}

	/**
	 * The translatable one-line description of a scope. SCOPE_LABELS stays
	 * the untranslated map the Pro add-on reads through `defined()`.
	 *
	 * @param string $scope A scope token.
	 */
	public static function scope_label( string $scope ): string {
		return match ( $scope ) {
			'read'          => __( 'Read any route', 'bluefairy-api-gateway' ),
			'write'         => __( 'Create, update and delete through any REST route (acts as the key\'s user)', 'bluefairy-api-gateway' ),
			'cache:purge'   => __( 'Purge the response cache (POST /bfag/v1/cache/purge)', 'bluefairy-api-gateway' ),
			'webhook:read'  => __( 'List webhooks and their deliveries (GET /bfag/v1/webhooks…)', 'bluefairy-api-gateway' ),
			'webhook:write' => __( 'Create, update, delete and retry webhooks (/bfag/v1/webhooks…); implies webhook:read', 'bluefairy-api-gateway' ),
			default         => self::SCOPE_LABELS[ $scope ] ?? $scope,
		};
	}

	/**
	 * GET/HEAD/OPTIONS are reads.
	 *
	 * @param string|null $method Explicit method, or null for REQUEST_METHOD.
	 */
	private static function is_read_method( ?string $method ): bool {
		if ( null === $method ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared against a fixed list only.
			$method = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) : 'GET';
		}
		$method = strtoupper( $method );
		return in_array( $method, [ 'GET', 'HEAD', 'OPTIONS' ], true );
	}
}
