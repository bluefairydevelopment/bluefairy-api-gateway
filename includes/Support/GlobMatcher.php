<?php
/**
 * Route glob → anchored regex.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rules match REST routes by glob, never by raw regex (review finding SEC4:
 * an admin-supplied regex is a ReDoS vector evaluated on every request).
 *
 *   `*`   matches any run of characters EXCEPT `/`
 *   `**`  matches anything, including `/`
 *   `?`   matches one character except `/`
 *   `/*`  at the END of a glob is optional: the glob also matches the
 *         collection itself, e.g. `wc/v3/products/*` matches
 *         `/wc/v3/products` and `/wc/v3/products/12`, but not
 *         `/wc/v3/products/12/variations`
 *   `/**` at the END of a glob is optional too: `wp/v2/posts/**` matches
 *         `/wp/v2/posts`, `/wp/v2/posts/1` and `/wp/v2/posts/1/revisions`
 *
 * Mid-pattern wildcards are never optional: `wp/v2/*\/1` does not match
 * `/wp/v2/1`. Globs are matched against the REST route with its leading
 * slash removed. Every other character is literal.
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
		$key = $glob;
		if ( isset( self::$compiled[ $key ] ) ) {
			return self::$compiled[ $key ];
		}
		$glob   = ltrim( trim( $glob ), '/' );
		$suffix = '';
		if ( strlen( $glob ) > 3 && str_ends_with( $glob, '/**' ) ) {
			// A trailing `/**` also matches the collection itself.
			$suffix = '(?:/.*)?';
			$glob   = substr( $glob, 0, -3 );
		} elseif ( strlen( $glob ) > 2 && str_ends_with( $glob, '/*' ) ) {
			// A trailing `/*` matches the collection and its direct children.
			$suffix = '(?:/[^/]*)?';
			$glob   = substr( $glob, 0, -2 );
		}
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
		// `i`: WP_REST_Server matches routes case-insensitively, so a glob must too.
		self::$compiled[ $key ] = '~^' . $pattern . $suffix . '$~i';
		return self::$compiled[ $key ];
	}

	/**
	 * How specific a glob is: the number of literal characters it carries,
	 * i.e. everything that is not `*` or `?` (the leading slash, which the
	 * matcher ignores, is not counted). `wp/v2/posts/**` scores 12, `**`
	 * scores 0. When several rules match a route the highest score wins.
	 *
	 * @param string $glob Route glob.
	 */
	public static function specificity( string $glob ): int {
		return strlen( str_replace( [ '*', '?' ], '', ltrim( trim( $glob ), '/' ) ) );
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
