<?php
/**
 * Option helpers the bootstrap does not model.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions, Squiz.Commenting, WordPress.PHP.DiscouragedPHPFunctions

if ( ! function_exists( 'maybe_serialize' ) ) {
	/**
	 * As core: arrays and objects are serialised; a string that already
	 * looks serialised is serialised again; anything else is returned as-is.
	 */
	function maybe_serialize( mixed $data ): mixed {
		if ( is_array( $data ) || is_object( $data ) ) {
			return serialize( $data );
		}
		if ( is_string( $data ) && false !== @unserialize( $data ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return serialize( $data );
		}
		return $data;
	}
}
