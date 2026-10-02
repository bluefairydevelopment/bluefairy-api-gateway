<?php
/**
 * Authenticated encryption for short-lived secrets kept in transients.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seals a secret before it goes into a transient, so the store holding it —
 * the options table, or with a persistent object cache a Redis or Memcached
 * server that may be shared — holds ciphertext only. Used for the one-time
 * display of a webhook signing secret the plugin generated
 * (`bfag_new_secret_<user_id>`, WPMAP-140): the shared object cache never
 * holds a webhook secret (WPMAP-117), and an unsealed transient would have
 * put one there for up to NEW_SECRET_TTL seconds.
 *
 * XSalsa20-Poly1305 (`sodium_crypto_secretbox`: PHP's sodium extension, or
 * WordPress's bundled sodium_compat, which core loads when the extension is
 * missing). The key is HMAC-SHA256 of `bfag-sealed|<context>` under
 * `wp_salt( 'auth' )`, so a value opens only under the same context (the
 * transient's own name) and only on a site with the same salts — normally
 * constants in wp-config.php, outside both the database and the object
 * cache. On a site whose salts live in the options table instead, sealing
 * still keeps the secret out of a shared object cache server but not away
 * from someone who can read the database (who can read the webhook's stored
 * secret anyway).
 *
 * Anything that does not open — another format, a tampered or truncated
 * value, another context, salts rotated since — is null, which the caller
 * treats as "nothing to show". Never use this for a value that cannot be
 * minted again: rotating the salts loses it.
 *
 * The Pro add-on keeps its own copy (prefix `bfagp1:`, its own key context)
 * and does not depend on this one.
 */
final class SealedValue {

	/**
	 * Prefix of a sealed value (format version 1).
	 */
	public const PREFIX = 'bfag1:';

	/**
	 * Seal $plain for $context.
	 *
	 * @param string $plain   The secret.
	 * @param string $context What the value is bound to (at least the transient name).
	 * @return string PREFIX . base64( nonce . ciphertext ).
	 */
	public static function seal( string $plain, string $context ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key( $context ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding of ciphertext.
	}

	/**
	 * Open a value sealed for $context.
	 *
	 * @param mixed  $sealed  What the transient returned.
	 * @param string $context The context it was sealed for.
	 * @return string|null The secret, or null when $sealed is not a value sealed for $context on this site.
	 */
	public static function open( mixed $sealed, string $context ): ?string {
		if ( ! is_string( $sealed ) || ! str_starts_with( $sealed, self::PREFIX ) ) {
			return null;
		}
		$raw = base64_decode( substr( $sealed, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- transport encoding of ciphertext.
		if ( false === $raw || strlen( $raw ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return null;
		}
		try {
			$plain = sodium_crypto_secretbox_open(
				substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				self::key( $context )
			);
		} catch ( \SodiumException ) {
			return null;
		}
		return is_string( $plain ) ? $plain : null;
	}

	/**
	 * The 32-byte key for $context.
	 *
	 * @param string $context The binding.
	 */
	private static function key( string $context ): string {
		return hash_hmac( 'sha256', 'bfag-sealed|' . $context, wp_salt( 'auth' ), true );
	}
}
