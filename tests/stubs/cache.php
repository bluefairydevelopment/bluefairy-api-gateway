<?php
/**
 * Cache-module stubs: the object-cache functions the bootstrap does not model.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions, Squiz.Commenting

if ( ! function_exists( 'wp_cache_decr' ) ) {
	/**
	 * As core's WP_Object_Cache::decr(): false when the key is absent,
	 * otherwise the new value, floored at zero.
	 */
	function wp_cache_decr( int|string $key, int $offset = 1, string $group = '' ): int|false {
		$current = wp_cache_get( $key, $group, false, $found );
		if ( ! $found ) {
			return false;
		}
		$GLOBALS['bfag_test_cache'][ $group ][ $key ] = max( 0, (int) $current - $offset );
		return $GLOBALS['bfag_test_cache'][ $group ][ $key ];
	}
}
