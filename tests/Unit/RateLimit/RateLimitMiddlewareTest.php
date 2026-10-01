<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\RateLimit;

use BlueFairy\ApiGateway\Logger;
use BlueFairy\ApiGateway\RateLimit\HitCounter;
use BlueFairy\ApiGateway\RateLimit\RateLimitMiddleware;
use BlueFairy\ApiGateway\RateLimit\RateLimitRuleRepository;
use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Support\RequestContext;
use BlueFairy\ApiGateway\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class RateLimitMiddlewareTest extends TestCase {

	private \WP_REST_Server $server;

	/**
	 * Every bfag_rate_limit_breached firing this test saw, as [ subject, rule id, route, ip ].
	 * Recorded by a listener rather than did_action(), whose counter the bootstrap does not reset.
	 *
	 * @var list<array{string, int, string, string}>
	 */
	private array $breaches = [];

	/**
	 * The 5th argument of each firing: forwarded_without_proxy.
	 *
	 * @var list<bool>
	 */
	private array $breach_flags = [];

	protected function setUp(): void {
		parent::setUp();
		$this->server       = new \WP_REST_Server();
		$this->breaches     = [];
		$this->breach_flags = [];
		add_action(
			'bfag_rate_limit_breached',
			function ( string $subject, array $rule, \WP_REST_Request $request, string $ip, bool $forwarded_without_proxy ): void {
				$this->breaches[]     = [ $subject, (int) $rule['id'], $request->get_route(), $ip ];
				$this->breach_flags[] = $forwarded_without_proxy;
			},
			10,
			5
		);
	}

	/**
	 * A rule row as the repository returns it.
	 *
	 * @param array<string, mixed> $overrides Column overrides.
	 * @return array<string, mixed>
	 */
	private function rule( array $overrides = [] ): array {
		return array_merge(
			[
				'id'              => 1,
				'label'           => 'Global',
				'subject_type'    => 'ip',
				'route_glob'      => '**',
				'max_requests'    => 60,
				'window_seconds'  => 60,
				'penalty_seconds' => 0,
				'enabled'         => 1,
				'created_at'      => '2026-09-30 00:00:00',
			],
			$overrides
		);
	}

	/**
	 * Middleware over the given rules (seeded through the object cache, so no
	 * rule query reaches $wpdb and every DB call left is the hit counter's).
	 *
	 * @param list<array<string, mixed>> $rules    Enabled rules.
	 * @param array<string, mixed>       $settings Settings overrides.
	 */
	private function middleware( array $rules = [], array $settings = [] ): RateLimitMiddleware {
		wp_cache_set( 'rl_rules_enabled', [] === $rules ? [ $this->rule() ] : $rules, 'bfag' );
		update_option( 'bfag_settings', $settings );
		$config = new Settings();
		return new RateLimitMiddleware( new RateLimitRuleRepository(), new HitCounter(), $config, new RequestContext( $config ) );
	}

	private function check( RateLimitMiddleware $middleware, string $route = '/wp/v2/posts', mixed $result = null ): mixed {
		return $middleware->check( $result, $this->server, $this->request( 'GET', $route ) );
	}

	// ------------------------------------------------------------------
	// register_hooks()
	// ------------------------------------------------------------------

	public function test_register_hooks_attaches_check_to_rest_pre_dispatch_at_priority_5(): void {
		$middleware = $this->middleware();
		$this->assertFalse( has_filter( 'rest_pre_dispatch' ) );
		$middleware->register_hooks();
		$this->assertSame( 5, has_filter( 'rest_pre_dispatch', [ $middleware, 'check' ] ) );

		$this->wpdb->queue( 'get_var', '61' );
		$out = apply_filters( 'rest_pre_dispatch', null, $this->server, $this->request( 'GET', '/wp/v2/posts' ) );
		$this->assertInstanceOf( \WP_REST_Response::class, $out );
		$this->assertSame( 429, $out->get_status(), 'the filter receives all three arguments' );
	}

	// ------------------------------------------------------------------
	// check() — pass-through paths
	// ------------------------------------------------------------------

	public function test_a_request_under_the_limit_passes_through_unchanged_after_recording_the_hit(): void {
		$this->wpdb->queue( 'get_var', '60' );
		$this->assertNull( $this->check( $this->middleware() ) );
		$inserts = $this->wpdb->calls_to( 'insert' );
		$this->assertCount( 1, $inserts );
		$this->assertSame( '203.0.113.10', $inserts[0]['data']['subject'] );
		$this->assertSame( 'wp/v2/posts', $inserts[0]['data']['route'], 'the log shows the normalised real route' );
		$this->assertSame( HitCounter::route_hash( 'rule:1' ), $inserts[0]['data']['route_hash'], 'but the bucket is the rule' );
		$this->assertSame( [], $this->breaches );
	}

	public function test_a_non_null_result_is_returned_untouched_without_counting(): void {
		$prior = new \WP_REST_Response( [ 'from' => 'elsewhere' ], 200 );
		$this->wpdb->queue( 'get_var', '999' );
		$this->assertSame( $prior, $this->check( $this->middleware(), '/wp/v2/posts', $prior ) );
		$this->assertSame( [], $this->wpdb->calls, 'nothing is read or written' );
		$this->assertSame( [], $this->breaches );
	}

	public function test_rate_limit_enabled_false_disables_the_middleware(): void {
		$this->wpdb->queue( 'get_var', '999' );
		$this->assertNull( $this->check( $this->middleware( [], [ 'rate_limit_enabled' => false ] ) ) );
		$this->assertSame( [], $this->wpdb->calls );

		$this->wpdb->queue( 'get_var', '999' );
		$this->assertSame( 429, $this->check( $this->middleware( [], [ 'rate_limit_enabled' => true ] ) )->get_status(), 'the same request is limited when enabled' );
	}

	public function test_the_status_route_is_exempt_but_lookalikes_are_not(): void {
		$middleware = $this->middleware();
		$this->wpdb->always( 'get_var', '999' );
		$this->assertNull( $this->check( $middleware, '/bfag/v1/status' ) );
		$this->assertNull( $this->check( $middleware, 'bfag/v1/status' ) );
		$this->assertNull( $this->check( $middleware, '/bfag/v1/status/detail' ) );
		$this->assertSame( [], $this->wpdb->calls, 'exempt routes are not even counted' );

		$this->assertSame( 429, $this->check( $middleware, '/bfag/v1/statuses' )->get_status() );
		$this->assertSame( 429, $this->check( $middleware, '/bfag/v1/cache/purge' )->get_status() );
	}

	public function test_an_unknown_client_ip_skips_an_ip_rule(): void {
		$_SERVER['REMOTE_ADDR'] = 'not-an-ip';
		$this->wpdb->queue( 'get_var', '999' );
		$this->assertNull( $this->check( $this->middleware() ) );
		$this->assertCount( 0, $this->wpdb->calls_to( 'insert' ), 'an empty subject is never counted' );
	}

	// ------------------------------------------------------------------
	// check() — limiting
	// ------------------------------------------------------------------

	public function test_the_request_that_crosses_max_gets_a_429_with_retry_and_limit_headers(): void {
		$this->wpdb->queue( 'get_var', '31' );
		$response = $this->check( $this->middleware( [ $this->rule( [ 'max_requests' => 30, 'window_seconds' => 120 ] ) ] ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame(
			[
				'code'    => 'bfag_rate_limited',
				'message' => 'Too many requests. Please slow down and try again shortly.',
				'data'    => [ 'status' => 429 ],
			],
			$response->get_data()
		);
		$this->assertSame( '120', $response->get_headers()['Retry-After'] );
		$this->assertSame( '30', $response->get_headers()['X-BF-RateLimit-Limit'] );
		$this->assertSame( '120', $response->get_headers()['X-BF-RateLimit-Window'] );
		$this->assertCount( 1, $this->wpdb->calls_to( 'insert' ), 'the limited request is itself recorded' );
	}

	/**
	 * P4: after a breach the subject is refused from a marker for the rest
	 * of the window — no INSERT, no COUNT — and the breach action still
	 * fires per limited request. Another subject, and the same subject
	 * under another rule, are counted as before.
	 */
	public function test_a_subject_that_breached_is_refused_from_the_marker_without_touching_the_hits_table(): void {
		bfag_test_set_time( 1_700_000_000 );
		$rules = [ $this->rule( [ 'id' => 3, 'max_requests' => 5, 'window_seconds' => 60 ] ) ];
		$this->wpdb->queue( 'get_var', '6' );
		$middleware = $this->middleware( $rules );

		$this->assertSame( 429, $this->check( $middleware )->get_status() );
		$marker = 'bfag_limited_' . RateLimitMiddleware::limited_key( '203.0.113.10', 3, 5, 60 );
		$this->assertEqualsWithDelta( time() + 60, get_transient( $marker ), 5, 'the marker holds the end of the window (real clock, as hit_at is)' );
		$this->assertSame( 1_700_000_060, $GLOBALS['bfag_test_transients'][ $marker ]['expires'], 'and expires with it' );
		$this->assertSame( 'limited:' . md5( '203.0.113.10|rule:3|5|60' ), RateLimitMiddleware::limited_key( '203.0.113.10', 3, 5, 60 ), 'the key carries the limits, so an edited rule retires its markers' );

		$this->wpdb->calls = [];
		$this->wpdb->queue( 'get_var', '0' );
		$response = $this->check( $middleware );
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( '5', $response->get_headers()['X-BF-RateLimit-Limit'] );
		$this->assertSame( [], $this->wpdb->calls, 'no INSERT and no COUNT for a known-limited subject' );
		$this->assertCount( 1, $this->breaches, 'R2-07: the breach action fired for the new breach only; a request refused from the marker fires nothing' );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.99';
		$this->assertNull( $this->check( $this->middleware( $rules ) ), 'another subject (a fresh context: the IP is memoised per request) is counted; the queued count of 0 passes it' );
		$this->assertCount( 1, $this->wpdb->calls_to( 'insert' ) );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

		bfag_test_set_time( 1_700_000_061 );
		$this->wpdb->calls = [];
		$this->wpdb->queue( 'get_var', '1' );
		$this->assertNull( $this->check( $middleware ), 'the window passed: counted again and under the limit' );
		$this->assertCount( 1, $this->wpdb->calls_to( 'insert' ) );
	}

	public function test_the_marker_lives_in_the_object_cache_when_it_is_persistent(): void {
		wp_using_ext_object_cache( true );
		bfag_test_set_time( 1_700_000_000 );
		$middleware = $this->middleware( [ $this->rule( [ 'id' => 1, 'max_requests' => 0, 'window_seconds' => 30 ] ) ] );

		$this->assertSame( 429, $this->check( $middleware )->get_status(), 'max 0: the first hit breaches' );
		$key = RateLimitMiddleware::limited_key( '203.0.113.10', 1, 0, 30 );
		$this->assertEqualsWithDelta( time() + 30, wp_cache_get( $key, 'bfag' ), 5 );
		$this->assertSame( 1_700_000_030, $GLOBALS['bfag_test_cache_expires']['bfag'][ $key ] );
		$this->assertSame( [], $GLOBALS['bfag_test_transients'], 'no transient' );

		$this->wpdb->calls = [];
		$this->assertSame( 429, $this->check( $middleware )->get_status() );
		$this->assertSame( [], $this->wpdb->calls );
	}

	public function test_consume_receives_the_rules_window_and_max(): void {
		$this->wpdb->queue( 'get_var', '0' );
		$t0 = time();
		$this->check( $this->middleware( [ $this->rule( [ 'max_requests' => 30, 'window_seconds' => 900 ] ) ] ) );
		$sql = (string) $this->wpdb->calls_to( 'get_var' )[0]['sql'];
		$this->assertSame( 1, preg_match( "/hit_at > '([^']+)'/", $sql, $m ) );
		$since = (int) strtotime( $m[1] . ' UTC' );
		$this->assertGreaterThanOrEqual( $t0 - 900, $since );
		$this->assertLessThanOrEqual( time() - 900, $since );
	}

	public function test_blocked_filter_yields_403_before_any_rule_is_counted(): void {
		$seen = [];
		add_filter(
			'bfag_rate_limit_is_blocked',
			static function ( bool $blocked, string $ip, \WP_REST_Request $request ) use ( &$seen ): bool {
				$seen = [ $blocked, $ip, $request->get_route() ];
				return '203.0.113.10' === $ip;
			},
			10,
			3
		);
		$this->wpdb->queue( 'get_var', '0' );
		$response = $this->check( $this->middleware() );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'bfag_blocked', $response->get_data()['code'] );
		$this->assertSame( [ 'status' => 403 ], $response->get_data()['data'] );
		$this->assertSame( [ false, '203.0.113.10', '/wp/v2/posts' ], $seen );
		$this->assertSame( [], $this->wpdb->calls, 'a blocked client is not counted' );
		$this->assertSame( [], $this->breaches );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$this->wpdb->queue( 'get_var', '0' );
		$this->assertNull( $this->check( $this->middleware() ), 'another IP the filter does not block passes' );
	}

	public function test_a_blocked_filter_returning_a_truthy_non_bool_does_not_block(): void {
		add_filter( 'bfag_rate_limit_is_blocked', static fn(): string => 'yes' );
		$this->wpdb->queue( 'get_var', '0' );
		$this->assertNull( $this->check( $this->middleware() ) );
	}

	public function test_subject_filter_returning_null_skips_the_rule_and_a_string_is_counted(): void {
		$seen  = [];
		$rules = [ $this->rule( [ 'id' => 1, 'subject_type' => 'user', 'max_requests' => 5 ] ) ];
		add_filter(
			'bfag_rate_limit_subject',
			static function ( ?string $default, string $type, \WP_REST_Request $request, string $ip ) use ( &$seen ): ?string {
				$seen[] = [ $default, $type, $request->get_route(), $ip ];
				return $default;
			},
			10,
			4
		);

		$this->wpdb->queue( 'get_var', '999' );
		$this->assertNull( $this->check( $this->middleware( $rules ) ), 'default subject for a non-ip type is null, so the rule is skipped' );
		$this->assertCount( 0, $this->wpdb->calls_to( 'insert' ) );
		$this->assertSame( [ [ null, 'user', '/wp/v2/posts', '203.0.113.10' ] ], $seen );

		add_filter( 'bfag_rate_limit_subject', static fn( ?string $subject, string $type ): ?string => 'user' === $type ? 'user:5' : $subject, 20, 2 );
		$this->wpdb->queue( 'get_var', '6' );
		$response = $this->check( $this->middleware( $rules ) );
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'user:5', $this->wpdb->calls_to( 'insert' )[0]['data']['subject'] );
	}

	public function test_subject_filter_returning_empty_string_skips_an_ip_rule(): void {
		add_filter( 'bfag_rate_limit_subject', static fn(): string => '' );
		$this->wpdb->queue( 'get_var', '999' );
		$this->assertNull( $this->check( $this->middleware() ) );
		$this->assertCount( 0, $this->wpdb->calls_to( 'insert' ) );
	}

	public function test_breached_action_fires_once_with_subject_rule_request_and_ip_and_stops_at_the_first_breach(): void {
		$rules = [
			$this->rule( [ 'id' => 1, 'route_glob' => 'wp/v2/posts', 'max_requests' => 10 ] ),
			$this->rule( [ 'id' => 2, 'route_glob' => '**', 'max_requests' => 100 ] ),
		];
		$this->wpdb->queue( 'get_var', '11' );
		$this->wpdb->queue( 'get_var', '11' );
		$response = $this->check( $this->middleware( $rules ) );

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( '10', $response->get_headers()['X-BF-RateLimit-Limit'], 'the breached rule shapes the response' );
		$this->assertSame( [ [ '203.0.113.10', 1, '/wp/v2/posts', '203.0.113.10' ] ], $this->breaches, 'fired exactly once, with subject, rule, request and ip' );
		$this->assertCount( 1, $this->wpdb->calls_to( 'insert' ), 'the second rule is not consumed once the first breached' );
	}

	public function test_every_matching_rule_is_consumed_when_none_breaches(): void {
		$rules = [
			$this->rule( [ 'id' => 1, 'route_glob' => 'wp/v2/posts', 'max_requests' => 10 ] ),
			$this->rule( [ 'id' => 2, 'route_glob' => '**', 'max_requests' => 100, 'window_seconds' => 3600 ] ),
			$this->rule( [ 'id' => 3, 'route_glob' => 'wc/v3/**', 'max_requests' => 1 ] ),
		];
		$this->wpdb->queue( 'get_var', '10' );
		$this->wpdb->queue( 'get_var', '100' );
		$this->assertNull( $this->check( $this->middleware( $rules ) ) );
		$this->assertCount( 2, $this->wpdb->calls_to( 'insert' ), 'the non-matching wc rule is not consumed' );
		$this->assertSame( [], $this->breaches );
	}

	public function test_a_breach_is_logged_when_debug_logging_is_on(): void {
		$this->wpdb->queue( 'get_var', '61' );
		$this->check( $this->middleware() );
		$this->assertSame( [], Logger::lines(), 'silent without debug logging' );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.11'; // A fresh subject: the first breach left a marker, and a marker refusal logs nothing.
		$this->wpdb->queue( 'get_var', '61' );
		$middleware = $this->middleware( [], [ 'debug_logging' => true ] );
		Logger::use_settings( new Settings() );
		ini_set( 'error_log', '/dev/null' );
		$this->check( $middleware );
		$this->assertCount( 1, Logger::lines() );
		$this->assertStringContainsString( 'rate limit breached {"subject":"203.0.113.11","rule":1,"route":"wp\/v2\/posts","max":60,"window":60}', Logger::lines()[0] );
	}

	// ------------------------------------------------------------------
	// limited_response(), blocked_response()
	// ------------------------------------------------------------------

	public function test_limited_response_shape(): void {
		$response = $this->middleware()->limited_response( $this->rule( [ 'max_requests' => 7, 'window_seconds' => 42 ] ) );
		$this->assertSame( 429, $response->get_status() );
		$this->assertTrue( $response->is_error() );
		$this->assertSame( 'bfag_rate_limited', $response->get_data()['code'] );
		$this->assertSame(
			[
				'Retry-After'           => '42',
				'X-BF-RateLimit-Limit'  => '7',
				'X-BF-RateLimit-Window' => '42',
			],
			$response->get_headers()
		);
	}

	public function test_blocked_response_shape(): void {
		$response = $this->middleware()->blocked_response();
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'bfag_blocked', $response->get_data()['code'] );
		$this->assertSame( [ 'status' => 403 ], $response->get_data()['data'] );
		$this->assertNotSame( '', $response->get_data()['message'] );
		$this->assertArrayNotHasKey( 'Retry-After', $response->get_headers() );
	}

	// ------------------------------------------------------------------
	// N1: internal dispatches are not limited
	// ------------------------------------------------------------------

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_under_wp_cli_nothing_is_counted_or_limited(): void {
		define( 'WP_CLI', true );
		$this->wpdb->queue( 'get_var', '999' );
		$this->assertNull( $this->check( $this->middleware() ) );
		$this->assertSame( [], $this->wpdb->calls, 'no INSERT, no COUNT' );
		$this->assertSame( [], $this->breaches );
	}

	public function test_a_cron_request_is_not_limited(): void {
		$GLOBALS['bfag_test_doing_cron'] = true;
		$this->wpdb->queue( 'get_var', '999' );
		$this->assertNull( $this->check( $this->middleware() ) );
		$this->assertSame( [], $this->wpdb->calls );

		$GLOBALS['bfag_test_doing_cron'] = false;
		$this->wpdb->queue( 'get_var', '999' );
		$this->assertSame( 429, $this->check( $this->middleware() )->get_status(), 'control: the same request outside cron is limited' );
	}

	public function test_an_internal_rest_do_request_dispatch_is_not_limited_but_the_top_level_request_is(): void {
		$this->assertFalse( defined( 'REST_REQUEST' ), 'precondition: no REST_REQUEST in this process' );
		// admin-ajax, a dashboard widget or the front end calling rest_do_request(): the server is not serving an HTTP REST request.
		$GLOBALS['bfag_test_serving_rest'] = false;
		$this->wpdb->queue( 'get_var', '999' );
		$this->assertNull( $this->check( $this->middleware() ) );
		$this->assertSame( [], $this->wpdb->calls, 'not even counted: a penalty rule must never be able to ban 127.0.0.1' );
		$this->assertSame( [], $this->breaches );

		$GLOBALS['bfag_test_serving_rest'] = true;
		$this->wpdb->queue( 'get_var', '999' );
		$response = $this->check( $this->middleware() );
		$this->assertSame( 429, $response->get_status(), 'control: the top-level REST request is limited' );
		$this->assertCount( 1, $this->wpdb->calls_to( 'insert' ) );
		$this->assertCount( 1, $this->breaches );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_rest_request_constant_alone_marks_a_top_level_request(): void {
		define( 'REST_REQUEST', true );
		$GLOBALS['bfag_test_serving_rest'] = false;
		$this->wpdb->queue( 'get_var', '999' );
		$this->assertSame( 429, $this->check( $this->middleware() )->get_status(), 'only when both signals say "internal" is the request skipped' );
	}

	public function test_an_empty_client_ip_is_neither_counted_nor_offered_to_the_block_filter(): void {
		$asked = 0;
		add_filter(
			'bfag_rate_limit_is_blocked',
			static function ( bool $blocked ) use ( &$asked ): bool {
				++$asked;
				return $blocked;
			}
		);
		$_SERVER['REMOTE_ADDR'] = '';
		$this->wpdb->queue( 'get_var', '999' );
		$this->assertNull( $this->check( $this->middleware() ) );
		$this->assertSame( [], $this->wpdb->calls );
		$this->assertSame( 0, $asked );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
		$this->wpdb->queue( 'get_var', '999' );
		$this->assertSame( 429, $this->check( $this->middleware() )->get_status(), 'control' );
		$this->assertSame( 1, $asked );
	}

	// ------------------------------------------------------------------
	// U-01a: logged-in users who can edit posts are exempt by default
	// ------------------------------------------------------------------

	public function test_a_logged_in_user_who_can_edit_posts_is_exempt_by_default_and_anonymous_traffic_is_not(): void {
		$this->assertTrue( Settings::DEFAULTS['rate_limit_exempt_editors'] );
		wp_set_current_user( 7 );
		bfag_test_grant( 'edit_posts' );
		$this->wpdb->queue( 'get_var', '999' );
		$this->assertNull( $this->check( $this->middleware() ) );
		$this->assertCount( 0, $this->wpdb->calls_to( 'insert' ), 'the editor is not even counted' );

		wp_set_current_user( 0 );
		$GLOBALS['bfag_test_caps'] = [];
		$this->wpdb->queue( 'get_var', '999' );
		$this->assertSame( 429, $this->check( $this->middleware() )->get_status(), 'control: anonymous traffic is limited' );
	}

	public function test_a_logged_in_user_without_edit_posts_is_limited_and_the_exemption_can_be_switched_off(): void {
		wp_set_current_user( 8 );
		$this->wpdb->queue( 'get_var', '999' );
		$this->assertSame( 429, $this->check( $this->middleware() )->get_status(), 'a subscriber is limited' );

		bfag_test_grant( 'edit_posts' );
		$this->wpdb->queue( 'get_var', '999' );
		$this->assertSame( 429, $this->check( $this->middleware( [], [ 'rate_limit_exempt_editors' => false ] ) )->get_status(), 'exemption off: the editor is limited' );
		$this->wpdb->queue( 'get_var', '999' );
		$this->assertNull( $this->check( $this->middleware( [], [ 'rate_limit_exempt_editors' => true ] ) ), 'control: exemption on' );
	}

	/**
	 * R2-05: the exemption is decided before the ban list is asked, so an
	 * editor behind a banned shared address (an unconfigured proxy, a
	 * loopback SSR caller) keeps working; everyone else on it is refused.
	 */
	public function test_an_exempt_editor_passes_even_when_the_address_is_banned(): void {
		$asked = 0;
		add_filter(
			'bfag_rate_limit_is_blocked',
			static function () use ( &$asked ): bool {
				++$asked;
				return true;
			}
		);
		wp_set_current_user( 7 );
		bfag_test_grant( 'edit_posts' );
		$this->assertNull( $this->check( $this->middleware() ), 'the exemption wins over the ban' );
		$this->assertSame( 0, $asked, 'the ban list is not even consulted for an exempt editor' );
		$this->assertSame( [], $this->wpdb->calls, 'and no hits-table work' );

		$this->assertSame( 403, $this->check( $this->middleware( [], [ 'rate_limit_exempt_editors' => false ] ) )->get_status(), 'control: exemption off, the editor is banned' );
		$this->assertSame( 1, $asked );

		wp_set_current_user( 0 );
		$GLOBALS['bfag_test_caps'] = [];
		$this->assertSame( 403, $this->check( $this->middleware() )->get_status(), 'control: anonymous traffic on the address is banned' );
	}

	/**
	 * R2-05: the breach action's 5th argument tells the Pro mitigator the
	 * IP is a proxy's shared address — a forwarded-address header arrived
	 * but REMOTE_ADDR is not a trusted proxy — so it must not ban it.
	 */
	public function test_breached_action_reports_a_forwarded_header_without_a_trusted_proxy(): void {
		$this->wpdb->queue( 'get_var', '61' );
		$this->assertSame( 429, $this->check( $this->middleware() )->get_status() );
		$this->assertSame( [ false ], $this->breach_flags, 'no forwarded header: false' );

		$_SERVER['REMOTE_ADDR']          = '203.0.113.20';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9';
		$this->wpdb->queue( 'get_var', '61' );
		$this->assertSame( 429, $this->check( $this->middleware() )->get_status() );
		$this->assertSame( [ false, true ], $this->breach_flags, 'XFF present, REMOTE_ADDR not a trusted proxy: true' );
		$this->assertSame( '203.0.113.20', $this->breaches[1][3], 'the shared address is what breached' );

		$_SERVER['REMOTE_ADDR'] = '10.0.0.5';
		$this->wpdb->queue( 'get_var', '61' );
		$this->assertSame( 429, $this->check( $this->middleware( [], [ 'trusted_proxies' => [ '10.0.0.0/8' ] ] ) )->get_status() );
		$this->assertSame( [ false, true, false ], $this->breach_flags, 'XFF from a trusted proxy: false (the IP is the real client)' );
		$this->assertSame( '198.51.100.9', $this->breaches[2][3] );
	}

	/**
	 * R2-07: editing a rule's limits retires the markers it left, so a
	 * subject limited under the old ceiling is counted afresh at once.
	 */
	public function test_editing_the_rules_limits_retires_its_limited_marker(): void {
		bfag_test_set_time( 1_700_000_000 );
		$this->wpdb->queue( 'get_var', '6' );
		$this->assertSame( 429, $this->check( $this->middleware( [ $this->rule( [ 'id' => 3, 'max_requests' => 5, 'window_seconds' => 60 ] ) ] ) )->get_status() );
		$this->assertCount( 1, $this->breaches );

		$this->wpdb->calls = [];
		$this->assertSame( 429, $this->check( $this->middleware( [ $this->rule( [ 'id' => 3, 'max_requests' => 5, 'window_seconds' => 60 ] ) ] ) )->get_status() );
		$this->assertSame( [], $this->wpdb->calls, 'control: unchanged rule, refused from the marker' );
		$this->assertCount( 1, $this->breaches, 'control: a marker refusal fires no breach action' );

		$this->wpdb->calls = [];
		$this->wpdb->queue( 'get_var', '1' );
		$this->assertNull( $this->check( $this->middleware( [ $this->rule( [ 'id' => 3, 'max_requests' => 500, 'window_seconds' => 60 ] ) ] ) ), 'max raised: the old marker no longer applies, the request is counted and passes' );
		$this->assertCount( 1, $this->wpdb->calls_to( 'insert' ) );

		$this->wpdb->calls = [];
		$this->wpdb->queue( 'get_var', '1' );
		$this->assertNull( $this->check( $this->middleware( [ $this->rule( [ 'id' => 3, 'max_requests' => 5, 'window_seconds' => 120 ] ) ] ) ), 'window changed: same' );
		$this->assertCount( 1, $this->wpdb->calls_to( 'insert' ) );
	}
}
