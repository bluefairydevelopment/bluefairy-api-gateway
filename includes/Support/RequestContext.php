<?php
/**
 * Facts about the current HTTP request that several subsystems need.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BlueFairy\ApiGateway\Settings;

/**
 * Client IP resolution and request classification.
 *
 * The client IP is REMOTE_ADDR unless REMOTE_ADDR is one of the admin's
 * trusted proxies (Settings `trusted_proxies`), in which case the first
 * forwarded address that is NOT itself a trusted proxy is used. Trusting
 * X-Forwarded-For unconditionally would let any visitor pick their own
 * rate-limit bucket — and, with the Pro add-on, get someone else banned.
 */
final class RequestContext {

	/**
	 * Headers consulted, in order, when REMOTE_ADDR is a trusted proxy.
	 */
	private const FORWARD_HEADERS = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' ];

	/**
	 * Resolved once per request.
	 *
	 * @var string|null
	 */
	private ?string $client_ip = null;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Plugin settings (trusted_proxies).
	 */
	public function __construct( private readonly Settings $settings ) {}

	/**
	 * Best-effort client IP, '' when none can be determined.
	 */
	public function client_ip(): string {
		if ( null !== $this->client_ip ) {
			return $this->client_ip;
		}
		$remote = $this->server( 'REMOTE_ADDR' );
		$ip     = IpUtils::is_valid_ip( $remote ) ? $remote : '';

		$proxies = $this->settings->get( 'trusted_proxies' );
		$proxies = is_array( $proxies ) ? $proxies : [];
		if ( '' !== $ip && [] !== $proxies && IpUtils::in_any_range( $ip, $proxies ) ) {
			foreach ( self::FORWARD_HEADERS as $header ) {
				$candidate = $this->first_untrusted( $this->server( $header ), $proxies );
				if ( null !== $candidate ) {
					$ip = $candidate;
					break;
				}
			}
		}
		$this->client_ip = $ip;
		return $ip;
	}

	/**
	 * Forget the resolved IP (tests, or after settings change mid-request).
	 */
	public function reset(): void {
		$this->client_ip = null;
	}

	/**
	 * A REST route with the leading slash removed, as the glob matcher expects.
	 *
	 * @param string $route Raw route.
	 */
	public static function normalize_route( string $route ): string {
		return ltrim( trim( $route ), '/' );
	}

	/**
	 * Right-most address in a comma-separated forwarded list that is not a
	 * trusted proxy, walking from the proxy end. Returns null when every entry
	 * is a proxy or invalid.
	 *
	 * @param string   $header_value Comma-separated forwarded addresses.
	 * @param string[] $proxies      Trusted proxy ranges.
	 */
	private function first_untrusted( string $header_value, array $proxies ): ?string {
		if ( '' === $header_value ) {
			return null;
		}
		$parts = array_reverse( array_map( 'trim', explode( ',', $header_value ) ) );
		foreach ( $parts as $part ) {
			if ( ! IpUtils::is_valid_ip( $part ) ) {
				return null;
			}
			if ( ! IpUtils::in_any_range( $part, $proxies ) ) {
				return $part;
			}
		}
		return null;
	}

	/**
	 * A $_SERVER string, unslashed and trimmed, '' when absent.
	 *
	 * @param string $key $_SERVER key.
	 */
	private function server( string $key ): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by IpUtils before use.
		$value = isset( $_SERVER[ $key ] ) ? wp_unslash( $_SERVER[ $key ] ) : '';
		return is_string( $value ) ? trim( $value ) : '';
	}
}
