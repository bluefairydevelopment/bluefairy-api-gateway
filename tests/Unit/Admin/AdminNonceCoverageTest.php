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
	 * Superglobals a handler may read. Reading one before the capability
	 * check is an ordering violation (T9) — except the routing lookups
	 * (`$_REQUEST['bfag_action']`, `$_GET['page']`), which decide whether a
	 * handler acts at all and are read, by design, before anything is
	 * verified.
	 *
	 * @var list<string>
	 */
	private const SUPERGLOBALS = [ '$_POST', '$_GET', '$_REQUEST', '$_SERVER', '$_COOKIE', '$_FILES' ];
	private const ROUTING_KEYS = [ 'bfag_action', 'page' ];

	/**
	 * `$this->` properties that reach a table; a call on one before the
	 * capability check is an ordering violation.
	 *
	 * @var list<string>
	 */
	private const REPOSITORY_PROPS = [ 'cache_rules', 'rl_rules', 'rules', 'webhooks', 'deliveries', 'dispatcher', 'store', 'purger', 'kpi_cron', 'hits', 'stats' ];

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
		return array_map( static fn( array $h ): array => $h['calls'], self::handlers( $tokens ) );
	}

	/**
	 * Per handler: the called function names, the token offset of the first
	 * capability check, and the token offset of the first superglobal read
	 * (routing lookups excepted) or repository call — null when none.
	 *
	 * @param list<array{0:int,1:string,2:int}|string> $tokens token_get_all() output.
	 * @return array<string, array{calls:list<string>, cap_at:?int, read_at:?int}>
	 */
	private static function handlers( array $tokens ): array {
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
			$depth   = 0;
			$calls   = [];
			$cap_at  = null;
			$read_at = null;
			$k       = $j;
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
					$n = self::skip_whitespace( $tokens, $k + 1 );
					if ( $n < $count && '(' === $tokens[ $n ] ) {
						$calls[] = $tok[1];
						if ( null === $cap_at && in_array( $tok[1], self::CAP_FUNCTIONS, true ) ) {
							$cap_at = $k;
						}
						// `$this->deliveries->find(`: a repository call.
						if ( null === $read_at && self::is_repository_call( $tokens, $k ) ) {
							$read_at = $k;
						}
					}
				} elseif ( $depth > 0 && is_array( $tok ) && T_VARIABLE === $tok[0] && in_array( $tok[1], self::SUPERGLOBALS, true ) ) {
					if ( null === $read_at && ! self::is_routing_lookup( $tokens, $k ) ) {
						$read_at = $k;
					}
				}
				$k++;
			}
			$out[ $name ] = [
				'calls'   => $calls,
				'cap_at'  => $cap_at,
				'read_at' => $read_at,
			];
		}
		return $out;
	}

	/**
	 * The index of the next non-whitespace token at or after $i.
	 *
	 * @param list<array{0:int,1:string,2:int}|string> $tokens Tokens.
	 * @param int                                       $i      Start index.
	 */
	private static function skip_whitespace( array $tokens, int $i ): int {
		$count = count( $tokens );
		while ( $i < $count && is_array( $tokens[ $i ] ) && T_WHITESPACE === $tokens[ $i ][0] ) {
			$i++;
		}
		return $i;
	}

	/**
	 * Is the T_STRING at $k the method of `$this-><repository>->method(`?
	 *
	 * @param list<array{0:int,1:string,2:int}|string> $tokens Tokens.
	 * @param int                                       $k      Index of the method name token.
	 */
	private static function is_repository_call( array $tokens, int $k ): bool {
		// $k-1: `->`, $k-2: the property, $k-3: `->`, $k-4: `$this`.
		return $k >= 4
			&& is_array( $tokens[ $k - 1 ] ) && T_OBJECT_OPERATOR === $tokens[ $k - 1 ][0]
			&& is_array( $tokens[ $k - 2 ] ) && T_STRING === $tokens[ $k - 2 ][0] && in_array( $tokens[ $k - 2 ][1], self::REPOSITORY_PROPS, true )
			&& is_array( $tokens[ $k - 3 ] ) && T_OBJECT_OPERATOR === $tokens[ $k - 3 ][0]
			&& is_array( $tokens[ $k - 4 ] ) && T_VARIABLE === $tokens[ $k - 4 ][0] && '$this' === $tokens[ $k - 4 ][1];
	}

	/**
	 * Is the superglobal at $k read with a routing key: `$_GET['page']`, `$_REQUEST['bfag_action']`?
	 *
	 * @param list<array{0:int,1:string,2:int}|string> $tokens Tokens.
	 * @param int                                       $k      Index of the superglobal token.
	 */
	private static function is_routing_lookup( array $tokens, int $k ): bool {
		$n = self::skip_whitespace( $tokens, $k + 1 );
		if ( ! isset( $tokens[ $n ] ) || '[' !== $tokens[ $n ] ) {
			return false;
		}
		$n = self::skip_whitespace( $tokens, $n + 1 );
		return isset( $tokens[ $n ] ) && is_array( $tokens[ $n ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $n ][0] && in_array( trim( $tokens[ $n ][1], '"\'' ), self::ROUTING_KEYS, true );
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
		foreach ( self::handlers( token_get_all( $source ) ) as $method => $handler ) {
			$calls   = $handler['calls'];
			$missing = [];
			if ( [] === array_intersect( $calls, self::NONCE_FUNCTIONS ) ) {
				$missing[] = 'nonce';
			}
			if ( [] === array_intersect( $calls, self::CAP_FUNCTIONS ) ) {
				$missing[] = 'capability';
			} elseif ( null !== $handler['read_at'] && $handler['read_at'] < $handler['cap_at'] ) {
				// T9: the capability check must come before the first superglobal read or repository call.
				$missing[] = 'order';
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

	/**
	 * T9: the scanner also catches a handler that reads a superglobal, or
	 * asks a repository, before it checks the capability — and lets the
	 * routing lookups through.
	 */
	public function test_the_scanner_catches_a_capability_check_that_comes_after_a_read(): void {
		$specimen = '<?php class X {'
			. ' public function ajax_reads_first() { check_ajax_referer( "bfag_ajax_x", "nonce" ); $id = absint( $_POST["id"] ); current_user_can( "x" ); }'
			. ' public function ajax_queries_first() { check_ajax_referer( "bfag_ajax_x", "nonce" ); $row = $this->deliveries->find( 1 ); current_user_can( "x" ); }'
			. ' public function ajax_routes_first() { $a = sanitize_key( $_REQUEST["bfag_action"] ); $p = $_GET["page"]; check_ajax_referer( "bfag_ajax_x", "nonce" ); current_user_can( "x" ); $id = absint( $_POST["id"] ); }'
			. ' public function ajax_in_order() { check_ajax_referer( "bfag_ajax_x", "nonce" ); current_user_can( "x" ); $id = absint( $_POST["id"] ); $this->deliveries->find( $id ); }'
			. ' }';
		$this->assertSame(
			[
				'ajax_reads_first'   => [ 'order' ],
				'ajax_queries_first' => [ 'order' ],
			],
			self::violations( $specimen ),
			'a read before the capability check is reported; routing lookups and in-order handlers are not'
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
		$this->assertGreaterThanOrEqual( 7, $handlers, 'AdminMenu::handle_actions, five ajax_* handlers and Page::handle_actions must all have been examined' );
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
