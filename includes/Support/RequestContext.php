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
 * trusted proxies (Settings `trusted_proxies`), in which case ONE header —
 * the one the admin chose in Settings `forwarded_header` — is read. Trusting
 * X-Forwarded-For unconditionally would let any visitor pick their own
 * rate-limit bucket — and, with the Pro add-on, get someone else banned.
 * Reading several headers in a fixed order is the same hole one step back: a
 * proxy that only manages X-Forwarded-For passes a visitor's own X-Real-IP
 * or CF-Connecting-IP straight through, and a higher-ranked header would
 * outrank the address the proxy actually wrote. So only the chosen header
 * counts; the others are ignored however they are set.
 */
final class RequestContext {

	/**
	 * The one header that is a comma-separated chain rather than a single address.
	 */
	private const CHAIN_HEADER = 'X-Forwarded-For';

	/**
	 * Resolved once per request.
	 *
	 * @var string|null
	 */
	private ?string $client_ip = null;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Plugin settings (trusted_proxies, forwarded_header).
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
			$header    = $this->forwarded_header();
			$value     = $this->server( 'HTTP_' . strtoupper( str_replace( '-', '_', $header ) ) );
			$candidate = self::CHAIN_HEADER === $header
				? $this->first_untrusted( $value, $proxies )
				: ( IpUtils::is_valid_ip( $value ) ? $value : null );
			if ( null !== $candidate ) {
				$ip = $candidate;
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
	 * The header the admin chose. A stored value outside the allowed list
	 * (one that bypassed Settings::sanitize()) reads as the default.
	 */
	private function forwarded_header(): string {
		$configured = $this->settings->get( 'forwarded_header' );
		return is_string( $configured ) && in_array( $configured, Settings::FORWARDED_HEADERS, true )
			? $configured
			: Settings::FORWARDED_HEADERS[0];
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
