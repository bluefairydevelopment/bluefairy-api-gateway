<?php
/**
 * An option write whose outcome is known.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `update_option()` returns false both when the database refused the write
 * and when the value was already stored (core skips the write then), so its
 * result alone cannot be trusted either way (F21 / WPMAP-110). On false the
 * stored value is read back and compared in its stored form: the same value
 * means "unchanged", anything else means the write failed.
 */
trait WritesOptions {

	/**
	 * Store $value in $option and say whether the option now holds it.
	 *
	 * @param string $option   Option name.
	 * @param mixed  $value    Value to store.
	 * @param bool   $autoload Autoload flag passed to update_option().
	 */
	private function write_option( string $option, mixed $value, bool $autoload ): bool {
		if ( update_option( $option, $value, $autoload ) ) {
			return true;
		}
		$stored = get_option( $option, null );
		if ( is_scalar( $value ) || null === $value ) {
			return ( is_scalar( $stored ) || null === $stored ) && (string) $stored === (string) $value;
		}
		return maybe_serialize( $stored ) === maybe_serialize( $value );
	}
}
