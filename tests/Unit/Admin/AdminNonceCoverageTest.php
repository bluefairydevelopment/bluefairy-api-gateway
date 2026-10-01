<?php
/**
 * Review finding SEC8: every admin/AJAX handler verifies a nonce and the
 * capability; every REST route has a real permission callback.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Admin;

use BlueFairy\ApiGateway\Tests\TestCase;

/**
 * Tokenises every file under includes/Admin and includes/Rest. The scanner is
 * itself tested against planted specimens (a handler without a nonce, a route
 * with `__return_true`) so a green here is a green from an instrument that has
 * been seen to go red.
 */
final class AdminNonceCoverageTest extends TestCase {

	private const NONCE_FUNCTIONS = [ 'check_admin_referer', 'check_ajax_referer', 'wp_verify_nonce' ];
	private const CAP_FUNCTIONS   = [ 'current_user_can', 'current_user_can_manage' ];

	/**
	 * @return list<string>
	 */
	private static function files(): array {
		$files = [];
		foreach ( [ 'Admin', 'Rest' ] as $dir ) {
			foreach ( glob( BFAG_DIR . 'includes/' . $dir . '/*.php' ) ?: [] as $file ) {
				$files[] = $file;
			}
		}
		sort( $files );
		return $files;
	}

	/**
	 * Methods named handle_* / ajax_* and the function names called inside each.
	 *
	 * @param list<array{0:int,1:string,2:int}|string> $tokens token_get_all() output.
	 * @return array<string, list<string>> method name => called function names.
	 */
	private static function handler_calls( array $tokens ): array {
		$out   = [];
		$count = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) || T_FUNCTION !== $tokens[ $i ][0] ) {
				continue;
			}
			// Name: next T_STRING.
			$j = $i + 1;
			while ( $j < $count && ( ! is_array( $tokens[ $j ] ) || T_STRING !== $tokens[ $j ][0] ) ) {
				if ( '(' === $tokens[ $j ] ) {
					break; // closure
				}
				$j++;
			}
			if ( $j >= $count || ! is_array( $tokens[ $j ] ) ) {
				continue;
			}
			$name = $tokens[ $j ][1];
			if ( ! str_starts_with( $name, 'handle_' ) && ! str_starts_with( $name, 'ajax_' ) ) {
				continue;
			}
			// Body: from the first `{` after the name to its matching `}`.
			$depth = 0;
			$calls = [];
			$k     = $j;
			while ( $k < $count ) {
				$tok = $tokens[ $k ];
				if ( '{' === $tok || ( is_array( $tok ) && in_array( $tok[0], [ T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ], true ) ) ) {
					$depth++;
				} elseif ( '}' === $tok ) {
					$depth--;
					if ( 0 === $depth ) {
						break;
					}
				} elseif ( $depth > 0 && is_array( $tok ) && T_STRING === $tok[0] ) {
					// A T_STRING followed by `(` is a call (function or method).
					$n = $k + 1;
					while ( $n < $count && is_array( $tokens[ $n ] ) && T_WHITESPACE === $tokens[ $n ][0] ) {
						$n++;
					}
					if ( $n < $count && '(' === $tokens[ $n ] ) {
						$calls[] = $tok[1];
					}
				}
				$k++;
			}
			$out[ $name ] = $calls;
		}
		return $out;
	}

	/**
	 * The `permission_callback` value tokens of every register_rest_route() call.
	 *
	 * @param list<array{0:int,1:string,2:int}|string> $tokens token_get_all() output.
	 * @return list<string|null> One entry per route: the callback's source text, or null when absent.
	 */
	private static function route_permissions( array $tokens ): array {
		$out   = [];
		$count = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) || T_STRING !== $tokens[ $i ][0] || 'register_rest_route' !== $tokens[ $i ][1] ) {
				continue;
			}
			// Collect the call's argument tokens up to the matching `)`.
			$depth = 0;
			$k     = $i + 1;
			$args  = [];
			while ( $k < $count ) {
				$tok = $tokens[ $k ];
				if ( '(' === $tok || '[' === $tok ) {
					$depth++;
				} elseif ( ')' === $tok || ']' === $tok ) {
					$depth--;
					if ( 0 === $depth ) {
						break;
					}
				}
				if ( $depth > 0 ) {
					$args[] = $tok;
				}
				$k++;
			}
			$permission = null;
			$n          = count( $args );
			for ( $a = 0; $a < $n; $a++ ) {
				$tok = $args[ $a ];
				if ( is_array( $tok ) && T_CONSTANT_ENCAPSED_STRING === $tok[0] && 'permission_callback' === trim( $tok[1], '"\'' ) ) {
					// Skip whitespace and `=>`, then take tokens up to the next `,` at this depth.
					$b = $a + 1;
					while ( $b < $n && is_array( $args[ $b ] ) && in_array( $args[ $b ][0], [ T_WHITESPACE, T_DOUBLE_ARROW ], true ) ) {
						$b++;
					}
					$text = '';
					$d    = 0;
					while ( $b < $n ) {
						$t = $args[ $b ];
						if ( '[' === $t || '(' === $t ) {
							$d++;
						} elseif ( ']' === $t || ')' === $t ) {
							if ( 0 === $d ) {
								break; // The enclosing array closed: a last element without a trailing comma.
							}
							$d--;
						} elseif ( ',' === $t && 0 === $d ) {
							break;
						}
						$text .= is_array( $t ) ? $t[1] : $t;
						$b++;
					}
					$permission = trim( $text );
				}
			}
			$out[] = $permission;
		}
		return $out;
	}

	/**
	 * Which handlers in $source lack a nonce check, a capability check, or both.
	 *
	 * @param string $source PHP source.
	 * @return array<string, list<string>> handler => list of 'nonce' / 'capability' it lacks.
	 */
	private static function violations( string $source ): array {
		$out = [];
		foreach ( self::handler_calls( token_get_all( $source ) ) as $method => $calls ) {
			$missing = [];
			if ( [] === array_intersect( $calls, self::NONCE_FUNCTIONS ) ) {
				$missing[] = 'nonce';
			}
			if ( [] === array_intersect( $calls, self::CAP_FUNCTIONS ) ) {
				$missing[] = 'capability';
			}
			if ( [] !== $missing ) {
				$out[ $method ] = $missing;
			}
		}
		return $out;
	}

	public function test_the_scanner_catches_a_handler_without_a_nonce_or_capability(): void {
		$specimen = '<?php class X { public function handle_bad() { $x = sanitize_key( $_POST["a"] ); return $x; } public function ajax_bad() { current_user_can( "x" ); wp_send_json_success(); } public function handle_good() { check_admin_referer( "bfag_x" ); Capabilities::current_user_can_manage(); } public function ajax_good() { check_ajax_referer( "bfag_ajax_x", "nonce" ); current_user_can( "x" ); } public function helper() {} }';
		$this->assertSame(
			[
				'handle_bad' => [ 'nonce', 'capability' ],
				'ajax_bad'   => [ 'nonce' ],
			],
			self::violations( $specimen ),
			'the planted specimens must be reported, and only they'
		);
	}

	public function test_the_scanner_catches_a_route_without_a_real_permission_callback(): void {
		$specimen = '<?php register_rest_route( "a/v1", "/x", [ "methods" => "GET", "callback" => "f", "permission_callback" => "__return_true" ] ); register_rest_route( "a/v1", "/y", [ "methods" => "GET", "callback" => "f" ] ); register_rest_route( "a/v1", "/z", [ "callback" => [ $this, "f" ], "permission_callback" => [ $this, "permission" ], "args" => [ "t" => [ "sanitize_callback" => "g" ] ] ] );';
		$this->assertSame( [ "'__return_true'", null, "[ \$this, 'permission' ]" ], array_map( static fn( ?string $p ): ?string => null === $p ? null : str_replace( '"', "'", $p ), self::route_permissions( token_get_all( $specimen ) ) ) );
	}

	public function test_every_handler_in_admin_and_rest_verifies_a_nonce_and_the_capability(): void {
		$files    = self::files();
		$handlers = 0;
		$this->assertNotEmpty( $files, 'the search space must not be empty' );
		foreach ( $files as $file ) {
			$source = (string) file_get_contents( $file );
			$found  = self::handler_calls( token_get_all( $source ) );
			$handlers += count( $found );
			$this->assertSame( [], self::violations( $source ), basename( $file ) . ' has a handle_*/ajax_* method without a nonce or capability check' );
		}
		$this->assertGreaterThanOrEqual( 6, $handlers, 'AdminMenu::handle_actions, four ajax_* handlers and Page::handle_actions must all have been examined' );
	}

	public function test_every_rest_route_has_a_permission_callback_that_is_not_return_true(): void {
		$routes = 0;
		foreach ( self::files() as $file ) {
			foreach ( self::route_permissions( token_get_all( (string) file_get_contents( $file ) ) ) as $permission ) {
				$routes++;
				$this->assertNotNull( $permission, basename( $file ) . ' registers a route without permission_callback' );
				$this->assertStringNotContainsString( '__return_true', $permission, basename( $file ) . ' registers a route open to everyone' );
			}
		}
		$this->assertSame( 2, $routes, 'the two contract routes (status, cache/purge) must have been examined' );
	}
}
