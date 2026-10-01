<?php
/**
 * IP address helpers.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Support;

/**
 * Pure functions over IPv4/IPv6 addresses and CIDR ranges. No WordPress calls.
 */
final class IpUtils {

	/**
	 * True for a syntactically valid IPv4/IPv6 address.
	 *
	 * @param string $ip Candidate.
	 */
	public static function is_valid_ip( string $ip ): bool {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP );
	}

	/**
	 * True for a valid IP, or a valid CIDR such as 10.0.0.0/8 or 2001:db8::/32.
	 *
	 * @param string $value Candidate.
	 */
	public static function is_valid_cidr_or_ip( string $value ): bool {
		if ( ! str_contains( $value, '/' ) ) {
			return self::is_valid_ip( $value );
		}
		[ $ip, $bits ] = explode( '/', $value, 2 );
		if ( ! self::is_valid_ip( $ip ) || ! ctype_digit( $bits ) ) {
			return false;
		}
		$max = str_contains( $ip, ':' ) ? 128 : 32;
		return (int) $bits >= 0 && (int) $bits <= $max;
	}

	/**
	 * Does $ip fall inside $cidr? A bare IP in $cidr matches only itself.
	 * Mixed families never match.
	 *
	 * @param string $ip   Address to test.
	 * @param string $cidr Range or bare address.
	 */
	public static function in_range( string $ip, string $cidr ): bool {
		if ( ! self::is_valid_ip( $ip ) || ! self::is_valid_cidr_or_ip( $cidr ) ) {
			return false;
		}
		if ( ! str_contains( $cidr, '/' ) ) {
			return inet_pton( $ip ) === inet_pton( $cidr );
		}
		[ $subnet, $bits ] = explode( '/', $cidr, 2 );
		$ip_bin            = inet_pton( $ip );
		$subnet_bin        = inet_pton( $subnet );
		if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false;
		}
		$bits  = (int) $bits;
		$bytes = intdiv( $bits, 8 );
		$rest  = $bits % 8;
		if ( $bytes > 0 && substr( $ip_bin, 0, $bytes ) !== substr( $subnet_bin, 0, $bytes ) ) {
			return false;
		}
		if ( 0 === $rest ) {
			return true;
		}
		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
		return ( ord( $ip_bin[ $bytes ] ) & $mask ) === ( ord( $subnet_bin[ $bytes ] ) & $mask );
	}

	/**
	 * Is $ip inside any of $ranges?
	 *
	 * @param string   $ip     Address to test.
	 * @param string[] $ranges IPs or CIDRs.
	 */
	public static function in_any_range( string $ip, array $ranges ): bool {
		foreach ( $ranges as $range ) {
			if ( self::in_range( $ip, $range ) ) {
				return true;
			}
		}
		return false;
	}
}
