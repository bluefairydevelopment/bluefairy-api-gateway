<?php
/**
 * Capability registration and the route → scope map.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway;

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
	public const SCOPES = [ 'read', 'cache:purge', 'webhook:read', 'webhook:write' ];

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
	 * The scope token a route requires. Unknown routes require `read`, which
	 * is the least an API key can hold — so a key never silently gains access
	 * to a route this map has not considered.
	 *
	 * @param string $route REST route, with or without the leading slash.
	 */
	public static function required_scope( string $route ): string {
		$route = '/' . ltrim( $route, '/' );
		return match ( true ) {
			str_starts_with( $route, '/bfag/v1/cache/purge' ) => 'cache:purge',
			str_starts_with( $route, '/bfag/v1/webhooks' )    => self::is_read_method() ? 'webhook:read' : 'webhook:write',
			default                                            => 'read',
		};
	}

	/**
	 * Does a space-separated permission string satisfy a required scope?
	 * `webhook:write` implies `webhook:read`; nothing implies `cache:purge`.
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
	 * GET/HEAD/OPTIONS are reads.
	 */
	private static function is_read_method(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared against a fixed list only.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		return in_array( $method, [ 'GET', 'HEAD', 'OPTIONS' ], true );
	}
}
