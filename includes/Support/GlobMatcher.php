<?php
/**
 * Route glob → anchored regex.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Support;

/**
 * Rules match REST routes by glob, never by raw regex (review finding SEC4:
 * an admin-supplied regex is a ReDoS vector evaluated on every request).
 *
 *   `*`  matches any run of characters EXCEPT `/`
 *   `**` matches anything, including `/`
 *   `?`  matches one character except `/`
 *
 * Globs are matched against the REST route with its leading slash removed,
 * e.g. `wc/v3/products/*` matches `/wc/v3/products/12` but not
 * `/wc/v3/products/12/variations`. Every other character is literal.
 */
final class GlobMatcher {

	/**
	 * Compiled patterns, keyed by glob, for the life of the request.
	 *
	 * @var array<string, string>
	 */
	private static array $compiled = [];

	/**
	 * Compile a glob to an anchored, delimiter-safe PCRE pattern.
	 *
	 * @param string $glob Route glob.
	 */
	public static function compile( string $glob ): string {
		if ( isset( self::$compiled[ $glob ] ) ) {
			return self::$compiled[ $glob ];
		}
		$glob    = ltrim( trim( $glob ), '/' );
		$pattern = '';
		$length  = strlen( $glob );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $glob[ $i ];
			if ( '*' === $char ) {
				if ( $i + 1 < $length && '*' === $glob[ $i + 1 ] ) {
					$pattern .= '.*';
					++$i;
				} else {
					$pattern .= '[^/]*';
				}
			} elseif ( '?' === $char ) {
				$pattern .= '[^/]';
			} else {
				$pattern .= preg_quote( $char, '~' );
			}
		}
		self::$compiled[ $glob ] = '~^' . $pattern . '$~';
		return self::$compiled[ $glob ];
	}

	/**
	 * Does $route match $glob?
	 *
	 * @param string $glob  Route glob.
	 * @param string $route REST route.
	 */
	public static function matches( string $glob, string $route ): bool {
		$route = ltrim( $route, '/' );
		return 1 === preg_match( self::compile( $glob ), $route );
	}

	/**
	 * A glob is valid when it is non-empty, printable, and contains nothing
	 * a REST route could not contain. Admin save handlers call this before
	 * storing.
	 *
	 * @param string $glob Candidate glob.
	 */
	public static function is_valid( string $glob ): bool {
		$glob = trim( $glob );
		if ( '' === $glob || strlen( $glob ) > 500 ) {
			return false;
		}
		return 1 === preg_match( '#^[A-Za-z0-9_\-./*?%:@=&+~]+$#', $glob );
	}
}
