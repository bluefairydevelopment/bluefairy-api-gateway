<?php
/**
 * Installer/uninstall stubs: the metadata and cache-suspension functions the
 * bootstrap does not model.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions, Squiz.Commenting

if ( ! function_exists( 'delete_metadata' ) ) {
	/**
	 * As core with $delete_all: removes the key from every object of the
	 * type (here: every user in $GLOBALS['bfag_test_user_meta']); without
	 * it, from the one object. Returns whether anything was removed.
	 */
	function delete_metadata( string $meta_type, int $object_id, string $meta_key, mixed $meta_value = '', bool $delete_all = false ): bool {
		if ( 'user' !== $meta_type ) {
			return false;
		}
		$removed = false;
		foreach ( array_keys( $GLOBALS['bfag_test_user_meta'] ?? [] ) as $user_id ) {
			if ( ( $delete_all || $user_id === $object_id ) && isset( $GLOBALS['bfag_test_user_meta'][ $user_id ][ $meta_key ] ) ) {
				unset( $GLOBALS['bfag_test_user_meta'][ $user_id ][ $meta_key ] );
				$removed = true;
			}
		}
		return $removed;
	}
}

if ( ! function_exists( 'wp_suspend_cache_addition' ) ) {
	/**
	 * Records every call in $GLOBALS['bfag_test_suspended'] and returns the
	 * previous state, as core does.
	 */
	function wp_suspend_cache_addition( ?bool $suspend = null ): bool {
		static $state = false;
		if ( null !== $suspend ) {
			$GLOBALS['bfag_test_suspended'][] = $suspend;
			$state                            = $suspend;
		}
		return $state;
	}
}
