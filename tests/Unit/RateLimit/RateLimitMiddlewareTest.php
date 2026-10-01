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

final class RateLimitMiddlewareTest extends TestCase {

	private \WP_REST_Server $server;

	/**
	 * Every bfag_rate_limit_breached firing this test saw, as [ subject, rule id, route, ip ].
	 * Recorded by a listener rather than did_action(), whose counter the bootstrap does not reset.
	 *
	 * @var list<array{string, int, string, string}>
	 */
	private array $breaches = [];

	protected function setUp(): void {
		parent::setUp();
		$this->server   = new \WP_REST_Server();
		$this->breaches = [];
		add_action(
			'bfag_rate_limit_breached',
			function ( string $subject, array $rule, \WP_REST_Request $request, string $ip ): void {
				$this->breaches[] = [ $subject, (int) $rule['id'], $request->get_route(), $ip ];
			},
			10,
			4
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
		$this->assertSame( '/wp/v2/posts', $inserts[0]['data']['route'] );
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

		$this->wpdb->queue( 'get_var', '61' );
		$middleware = $this->middleware( [], [ 'debug_logging' => true ] );
		Logger::use_settings( new Settings() );
		ini_set( 'error_log', '/dev/null' );
		$this->check( $middleware );
		$this->assertCount( 1, Logger::lines() );
		$this->assertStringContainsString( 'rate limit breached {"subject":"203.0.113.10","rule":1,"route":"\/wp\/v2\/posts","max":60,"window":60}', Logger::lines()[0] );
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
}
