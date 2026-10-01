<?php
/**
 * Plugin settings, merged over defaults at read time.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The single `bfag_settings` option, read once per request.
 *
 * Every reader goes through get() so the defaults live in exactly one place.
 * sanitize() is the only way values enter the option: the settings page, the
 * REST layer and the installer all call it, so an out-of-range value cannot be
 * stored no matter which path wrote it.
 */
final class Settings {

	public const OPTION = 'bfag_settings';

	/**
	 * Defaults. Keys are the whole vocabulary of the option: sanitize() drops
	 * anything not listed here.
	 *
	 * @var array<string, mixed>
	 */
	public const DEFAULTS = [
		'cache_enabled'             => true,
		'default_cache_ttl'         => 60,
		'rate_limit_enabled'        => true,
		'rate_limit_exempt_editors' => true,
		'trusted_proxies'           => [],
		'forwarded_header'          => 'X-Forwarded-For',
		'max_payload_bytes'         => 65535,
		'retention_days'            => 30,
		'failure_threshold'         => 5,
		'notification_email'        => '',
		'debug_logging'             => false,
		'disable_cron_notice'       => false,
		'delete_data_on_uninstall'  => false,
	];

	/**
	 * The headers `forwarded_header` may name — the ONE header a trusted
	 * proxy is believed to write the client address into. The first is the
	 * default; `sanitize()` rejects anything else.
	 *
	 * @var list<string>
	 */
	public const FORWARDED_HEADERS = [ 'X-Forwarded-For', 'CF-Connecting-IP', 'X-Real-IP', 'True-Client-IP' ];

	/**
	 * Bounds for the integer settings. Values are clamped, not rejected, so a
	 * typo cannot leave the option unsaved.
	 *
	 * @var array<string, array{int, int}>
	 */
	private const BOUNDS = [
		'default_cache_ttl' => [ 1, 86400 ],
		'max_payload_bytes' => [ 1024, 16777215 ],
		'retention_days'    => [ 1, 365 ],
		'failure_threshold' => [ 1, 100 ],
	];

	/**
	 * Per-request cache of the merged option.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $cache = null;

	/**
	 * All settings, merged over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, [] );
			$this->cache = array_merge( self::DEFAULTS, is_array( $stored ) ? $stored : [] );
		}
		return $this->cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 */
	public function get( string $key ): mixed {
		$all = $this->all();
		return $all[ $key ] ?? ( self::DEFAULTS[ $key ] ?? null );
	}

	/**
	 * Replace the stored option with a sanitised copy of $values.
	 *
	 * @param array<string, mixed> $values Raw values, typically from a form.
	 * @return array<string, mixed> What was stored.
	 */
	public function update( array $values ): array {
		$clean = $this->sanitize( $values );
		update_option( self::OPTION, $clean, false );
		$this->cache = null;
		return $clean;
	}

	/**
	 * Forget the per-request cache (after an external update_option).
	 */
	public function refresh(): void {
		$this->cache = null;
	}

	/**
	 * Coerce raw input into a complete, typed, bounded settings array.
	 *
	 * Unknown keys are dropped. Booleans accept checkbox-style values
	 * ('1', 'on', 'true', true). Trusted proxies must be valid IPv4/IPv6
	 * addresses or CIDR ranges; anything else is discarded rather than stored.
	 *
	 * @param array<string, mixed> $raw Raw input.
	 * @return array<string, mixed>
	 */
	public function sanitize( array $raw ): array {
		$clean = [];
		foreach ( self::DEFAULTS as $key => $default ) {
			if ( ! array_key_exists( $key, $raw ) ) {
				// A missing checkbox is an unticked checkbox; a missing field keeps its default.
				$clean[ $key ] = is_bool( $default ) ? false : $default;
				continue;
			}
			$value = $raw[ $key ];

			if ( is_bool( $default ) ) {
				$clean[ $key ] = self::to_bool( $value );
			} elseif ( is_int( $default ) ) {
				[ $min, $max ] = self::BOUNDS[ $key ] ?? [ 0, PHP_INT_MAX ];
				$clean[ $key ] = max( $min, min( $max, (int) $value ) );
			} elseif ( 'trusted_proxies' === $key ) {
				$clean[ $key ] = self::sanitize_cidr_list( $value );
			} elseif ( 'forwarded_header' === $key ) {
				$clean[ $key ] = self::sanitize_forwarded_header( $value );
			} elseif ( 'notification_email' === $key ) {
				$email         = sanitize_email( is_string( $value ) ? $value : '' );
				$clean[ $key ] = is_email( $email ) ? $email : '';
			} else {
				$clean[ $key ] = sanitize_text_field( is_string( $value ) ? $value : '' );
			}
		}
		return $clean;
	}

	/**
	 * Checkbox-tolerant boolean coercion.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function to_bool( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) ) {
			return 0 !== $value;
		}
		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), [ '1', 'on', 'true', 'yes' ], true );
		}
		return false;
	}

	/**
	 * One of FORWARDED_HEADERS, in its canonical spelling; anything else is
	 * the default. A header the admin did not choose is never read.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_forwarded_header( mixed $value ): string {
		if ( is_string( $value ) ) {
			foreach ( self::FORWARDED_HEADERS as $header ) {
				if ( 0 === strcasecmp( trim( $value ), $header ) ) {
					return $header;
				}
			}
		}
		return self::FORWARDED_HEADERS[0];
	}

	/**
	 * Accept a newline/comma separated string or an array; keep only valid
	 * IPs and CIDRs.
	 *
	 * @param mixed $value Raw value.
	 * @return list<string>
	 */
	public static function sanitize_cidr_list( mixed $value ): array {
		if ( is_string( $value ) ) {
			$parts = preg_split( '/[\s,]+/', $value );
			$value = false === $parts ? [] : $parts;
		}
		if ( ! is_array( $value ) ) {
			return [];
		}
		$out = [];
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}
			$item = trim( $item );
			if ( '' === $item ) {
				continue;
			}
			if ( Support\IpUtils::is_valid_cidr_or_ip( $item ) ) {
				$out[] = $item;
			}
		}
		return array_values( array_unique( $out ) );
	}
}
