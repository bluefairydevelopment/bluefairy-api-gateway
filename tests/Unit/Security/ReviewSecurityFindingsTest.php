<?php
/**
 * Security review (round 1) — regression tests for the cured findings.
 *
 * Each finding came with an executable confirmation that asserted the defect;
 * these are those tests with the "defect present" assertions inverted, kept so
 * the defect cannot return. Every test keeps a control that shows the
 * assertion can fail.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Security;

use BlueFairy\ApiGateway\Admin\AdminMenu;
use BlueFairy\ApiGateway\Admin\ProUpsell;
use BlueFairy\ApiGateway\Admin\SettingsPage;
use BlueFairy\ApiGateway\Admin\WebhookPage;
use BlueFairy\ApiGateway\Cache\CacheKeyGenerator;
use BlueFairy\ApiGateway\Cache\CacheManager;
use BlueFairy\ApiGateway\Admin\KpiCron;
use BlueFairy\ApiGateway\Cache\CachePurger;
use BlueFairy\ApiGateway\Cache\CacheRuleRepository;
use BlueFairy\ApiGateway\Cache\CacheStatsRepository;
use BlueFairy\ApiGateway\Cache\CacheStore;
use BlueFairy\ApiGateway\Capabilities;
use BlueFairy\ApiGateway\RateLimit\HitCounter;
use BlueFairy\ApiGateway\RateLimit\RateLimitMiddleware;
use BlueFairy\ApiGateway\RateLimit\RateLimitRuleRepository;
use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Support\RequestContext;
use BlueFairy\ApiGateway\Tests\SqliteWpdb;
use BlueFairy\ApiGateway\Tests\TestCase;
use BlueFairy\ApiGateway\Webhook\DeliveryRepository;
use BlueFairy\ApiGateway\Webhook\EventRegistry;
use BlueFairy\ApiGateway\Webhook\WebhookDispatcher;
use BlueFairy\ApiGateway\Webhook\WebhookRepository;

final class ReviewSecurityFindingsTest extends TestCase {

	private const SECRET = 'deadbeefcafe0123456789abcdef0123456789abcdef0123456789abcdef0123';

	/**
	 * Every bfag_rate_limit_breached firing, as [ subject, rule id ].
	 *
	 * @var list<array{string, int}>
	 */
	private array $breaches = [];

	protected function setUp(): void {
		parent::setUp();
		$this->breaches = [];
		add_action(
			'bfag_rate_limit_breached',
			function ( string $subject, array $rule ): void {
				$this->breaches[] = [ $subject, (int) $rule['id'] ];
			},
			10,
			2
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
				'label'           => 'Global per-IP limit',
				'subject_type'    => 'ip',
				'route_glob'      => '**',
				'max_requests'    => 60,
				'window_seconds'  => 60,
				'penalty_seconds' => 0,
				'enabled'         => 1,
			],
			$overrides
		);
	}

	/**
	 * Middleware over the given rules (seeded through the object cache), on a
	 * $wpdb that really counts hits in SQLite.
	 *
	 * @param list<array<string, mixed>> $rules Enabled rules.
	 */
	private function middleware( array $rules ): RateLimitMiddleware {
		$GLOBALS['wpdb'] = new SqliteWpdb();
		wp_cache_set( RateLimitRuleRepository::CACHE_KEY, $rules, RateLimitRuleRepository::CACHE_GROUP );
		$settings = new Settings();
		return new RateLimitMiddleware( new RateLimitRuleRepository(), new HitCounter(), $settings, new RequestContext( $settings ) );
	}

	private function check( RateLimitMiddleware $middleware, string $route ): mixed {
		return $middleware->check( null, new \WP_REST_Server(), $this->request( 'GET', $route ) );
	}

	/**
	 * Distinct route_hash values in the SQLite hit log.
	 */
	private function distinct_hashes(): int {
		return (int) $GLOBALS['wpdb']->pdo()->query( 'SELECT COUNT(DISTINCT route_hash) FROM ' . $GLOBALS['wpdb']->prefix . 'bfag_rate_limit_hits' )->fetchColumn();
	}

	private function cache_manager(): CacheManager {
		$store = new CacheStore();
		return new CacheManager( $store, new CacheKeyGenerator( $store ), new CacheRuleRepository(), new CacheStatsRepository(), new Settings() );
	}

	private function webhook_page(): WebhookPage {
		$settings = new Settings();
		$delivs   = new DeliveryRepository( $settings );
		return new WebhookPage( new WebhookRepository(), $delivs, new WebhookDispatcher( $delivs, $settings ), new EventRegistry(), new ProUpsell() );
	}

	private function admin_menu(): AdminMenu {
		$settings   = new Settings();
		$deliveries = new DeliveryRepository( $settings );
		$stats      = new CacheStatsRepository();
		$hits       = new HitCounter();
		return new AdminMenu( $settings, new CachePurger( new CacheStore(), new CacheRuleRepository() ), new CacheRuleRepository(), new RateLimitRuleRepository(), new WebhookRepository(), $deliveries, new WebhookDispatcher( $deliveries, $settings ), new EventRegistry(), new KpiCron( $stats, $deliveries, $hits ) );
	}

	/**
	 * @param array<string, mixed> $over Column overrides.
	 * @return array<string, mixed>
	 */
	private function webhook_row( array $over = [] ): array {
		return array_merge(
			[
				'id'          => 1,
				'label'       => 'CRM',
				'url'         => 'https://hooks.example.net/in',
				'event'       => 'post.published',
				'secret'      => self::SECRET,
				'headers'     => '{"X-Custom":"a"}',
				'preset'      => '',
				'enabled'     => 1,
				'max_retries' => 3,
				'created_at'  => '2026-01-01 00:00:00',
			],
			$over
		);
	}

	/**
	 * @param array<string, mixed> $over Column overrides.
	 * @return array<string, mixed>
	 */
	private function delivery_row( array $over = [] ): array {
		return array_merge(
			[
				'id'            => 10,
				'webhook_id'    => 1,
				'direction'     => 'out',
				'event'         => 'post.published',
				'payload'       => '{"event":"post.published"}',
				'truncated'     => 0,
				'status'        => 'failed',
				'http_status'   => 500,
				'response_body' => '',
				'attempt'       => 1,
				'next_retry_at' => null,
				'delivered_at'  => null,
				'created_at'    => '2026-01-01 00:00:00',
			],
			$over
		);
	}

	private function redirect_of( callable $fn ): string {
		$exit = bfag_test_catch_exit( $fn );
		$this->assertNotNull( $exit, 'the handler must end in a redirect' );
		$this->assertSame( 'redirect', $exit->kind );
		return (string) $exit->payload;
	}

	// ------------------------------------------------------------------
	// SEC-R1-01: the budget is per (subject, rule), not per exact route string.
	// ------------------------------------------------------------------

	public function test_a_case_variant_of_the_route_shares_the_budget_and_breaches_the_mitigator_sees(): void {
		$middleware = $this->middleware( [ $this->rule() ] );

		for ( $i = 1; $i <= 60; $i++ ) {
			$this->assertNull( $this->check( $middleware, '/wp/v2/posts' ), "request {$i} within budget" );
		}
		$limited = $this->check( $middleware, '/wp/v2/posts' );
		$this->assertInstanceOf( \WP_REST_Response::class, $limited, 'control: the 61st request on the same route IS limited' );
		$this->assertSame( 429, $limited->get_status() );
		$this->assertCount( 1, $this->breaches );

		// Core matches routes with the `i` flag, so these are the same endpoint and must share the budget.
		foreach ( [ '/wp/v2/Posts', '/WP/v2/posts', '/wp/V2/POSTS' ] as $variant ) {
			$response = $this->check( $middleware, $variant );
			$this->assertInstanceOf( \WP_REST_Response::class, $response, "{$variant} is limited too" );
			$this->assertSame( 429, $response->get_status(), $variant );
		}
		$this->assertCount( 1, $this->breaches, 'R2-07: the mitigator is told once, on the new breach; marker refusals fire nothing' );
		$this->assertSame( 1, $this->distinct_hashes(), 'one rule = one bucket, whatever the spelling' );

		$routes = $GLOBALS['wpdb']->pdo()->query( 'SELECT DISTINCT route FROM ' . $GLOBALS['wpdb']->prefix . 'bfag_rate_limit_hits' )->fetchAll( \PDO::FETCH_COLUMN );
		$this->assertSame( [ 'wp/v2/posts' ], $routes, 'the route column keeps the normalised real route for the admin' );
	}

	public function test_two_different_routes_under_one_rule_share_the_bucket(): void {
		$middleware = $this->middleware( [ $this->rule( [ 'max_requests' => 5 ] ) ] );
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->assertNull( $this->check( $middleware, '/wp/v2/posts/' . $i ) );
		}
		$response = $this->check( $middleware, '/wp/v2/pages' );
		$this->assertInstanceOf( \WP_REST_Response::class, $response, 'a crawler of distinct URLs has one budget under a ** rule' );
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 1, $this->distinct_hashes() );
		$this->assertSame( HitCounter::route_hash( RateLimitMiddleware::bucket( 1 ) ), $GLOBALS['wpdb']->pdo()->query( 'SELECT route_hash FROM ' . $GLOBALS['wpdb']->prefix . 'bfag_rate_limit_hits LIMIT 1' )->fetchColumn(), 'route_hash is of the rule key' );
	}

	public function test_a_second_rule_gets_its_own_bucket(): void {
		$middleware = $this->middleware(
			[
				$this->rule(
					[
						'id'           => 1,
						'route_glob'   => 'wp/v2/posts/**',
						'max_requests' => 2,
					]
				),
				$this->rule(
					[
						'id'           => 2,
						'route_glob'   => '**',
						'max_requests' => 100,
					]
				),
			]
		);
		$this->assertNull( $this->check( $middleware, '/wp/v2/posts' ) );
		$this->assertNull( $this->check( $middleware, '/wp/v2/posts/1' ) );
		$limited = $this->check( $middleware, '/wp/v2/posts/2' );
		$this->assertInstanceOf( \WP_REST_Response::class, $limited );
		$this->assertSame( '2', $limited->get_headers()['X-BF-RateLimit-Limit'], 'the posts rule is the one breached' );
		$this->assertSame( [ [ '203.0.113.10', 1 ] ], $this->breaches );

		$this->assertNull( $this->check( $middleware, '/wp/v2/pages' ), 'the ** rule has its own, untouched budget' );
		$this->assertSame( 2, $this->distinct_hashes(), 'two rules, two buckets' );
		$this->assertSame( 'rule:1', RateLimitMiddleware::bucket( 1 ) );
	}

	// ------------------------------------------------------------------
	// SEC-R1-02: route prefix checks follow core's case-insensitive matching.
	// ------------------------------------------------------------------

	public function test_required_scope_is_case_insensitive_like_core_route_matching(): void {
		// Control: the canonical spelling yields the narrow scope.
		$this->assertSame( 'webhook:read', Capabilities::required_scope( '/bfag/v1/webhooks', 'GET' ) );
		$this->assertSame( 'cache:purge', Capabilities::required_scope( '/bfag/v1/cache/purge', 'POST' ) );
		$this->assertSame( 'read', Capabilities::required_scope( '/wp/v2/posts', 'GET' ), 'control: an unrelated route is still the generic scope' );

		// Core's matcher (class-wp-rest-server.php, preg_match( '@^' . $route . '$@i', $path )) accepts these.
		$this->assertSame( 1, preg_match( '@^/bfag/v1/webhooks$@i', '/BFAG/v1/webhooks' ) );
		$this->assertSame( 1, preg_match( '@^/bfag/v1/cache/purge$@i', '/bfag/V1/cache/purge' ) );

		$this->assertSame( 'webhook:read', Capabilities::required_scope( '/BFAG/v1/webhooks', 'GET' ) );
		$this->assertSame( 'cache:purge', Capabilities::required_scope( '/bfag/V1/cache/purge', 'POST' ) );
		$this->assertSame( 'webhook:write', Capabilities::required_scope( '/BFAG/v1/webhooks/5', 'DELETE' ) );
		$this->assertSame( 'read', Capabilities::required_scope( '/Bfag/V1/status', 'POST' ), 'inside the namespace, whatever the case' );
	}

	public function test_the_status_route_is_exempt_from_the_limiter_in_any_case(): void {
		wp_cache_set( RateLimitRuleRepository::CACHE_KEY, [ $this->rule() ], RateLimitRuleRepository::CACHE_GROUP );
		$settings   = new Settings();
		$middleware = new RateLimitMiddleware( new RateLimitRuleRepository(), new HitCounter(), $settings, new RequestContext( $settings ) );
		$this->wpdb->always( 'get_var', '999' );

		$this->assertNull( $this->check( $middleware, '/BFAG/v1/status' ) );
		$this->assertNull( $this->check( $middleware, '/bfag/V1/Status/detail' ) );
		$this->assertSame( [], $this->wpdb->calls, 'exempt routes are not even counted' );

		$lookalike = $this->check( $middleware, '/BFAG/v1/statuses' );
		$this->assertInstanceOf( \WP_REST_Response::class, $lookalike, 'control: a lookalike is still limited' );
		$this->assertSame( 429, $lookalike->get_status() );
	}

	public function test_the_plugins_own_namespace_is_never_cached_in_any_case(): void {
		$manager = $this->cache_manager();
		$this->assertTrue( $manager->is_cacheable_request( $this->request( 'GET', '/wp/v2/posts' ) ), 'control: an ordinary route is cacheable' );
		$this->assertFalse( $manager->is_cacheable_request( $this->request( 'GET', '/bfag/v1/status' ) ), 'control: canonical spelling excluded' );
		$this->assertFalse( $manager->is_cacheable_request( $this->request( 'GET', '/Bfag/v1/status' ) ) );
		$this->assertFalse( $manager->is_cacheable_request( $this->request( 'GET', 'BFAG/V1/webhooks' ) ) );
	}

	// ------------------------------------------------------------------
	// SEC-R1-03: one admin-chosen forwarded header, read only from a trusted proxy.
	// ------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $settings Settings overrides.
	 */
	private function context( array $settings ): RequestContext {
		update_option( 'bfag_settings', $settings );
		return new RequestContext( new Settings() );
	}

	public function test_with_x_forwarded_for_chosen_a_client_supplied_x_real_ip_or_cf_header_is_ignored(): void {
		$_SERVER['REMOTE_ADDR']          = '10.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.5'; // written by the proxy: the real client.

		$this->assertSame( '203.0.113.5', $this->context( [ 'trusted_proxies' => [ '10.0.0.1' ] ] )->client_ip(), 'control: the proxy-written XFF wins' );

		$_SERVER['HTTP_X_REAL_IP']        = '198.51.100.9'; // client-supplied, passed through by a proxy that only manages XFF.
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.8';
		$this->assertSame( '203.0.113.5', $this->context( [ 'trusted_proxies' => [ '10.0.0.1' ] ] )->client_ip(), 'only the chosen header is read' );
		$this->assertSame( 'X-Forwarded-For', Settings::DEFAULTS['forwarded_header'], 'and X-Forwarded-For is the default choice' );
	}

	public function test_with_cf_connecting_ip_chosen_x_forwarded_for_is_ignored(): void {
		$_SERVER['REMOTE_ADDR']           = '10.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '198.51.100.9';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.5';
		$settings                         = [
			'trusted_proxies'  => [ '10.0.0.0/8' ],
			'forwarded_header' => 'CF-Connecting-IP',
		];
		$this->assertSame( '203.0.113.5', $this->context( $settings )->client_ip() );

		unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
		$this->assertSame( '10.0.0.1', $this->context( $settings )->client_ip(), 'without the chosen header XFF is not consulted' );

		$_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';
		$this->assertSame( '10.0.0.1', $this->context( $settings )->client_ip(), 'an invalid value falls back to REMOTE_ADDR' );

		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.5';
		$this->assertSame( '10.0.0.1', $this->context( [ 'forwarded_header' => 'CF-Connecting-IP' ] )->client_ip(), 'control: no trusted proxies, no header is read at all' );
	}

	public function test_x_real_ip_and_true_client_ip_can_be_chosen(): void {
		$_SERVER['REMOTE_ADDR']           = '10.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '198.51.100.9';
		$_SERVER['HTTP_X_REAL_IP']        = '203.0.113.6';
		$_SERVER['HTTP_TRUE_CLIENT_IP']   = '203.0.113.7';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.8';
		$proxies                          = [ '10.0.0.0/8' ];
		$this->assertSame( '203.0.113.6', $this->context( [ 'trusted_proxies' => $proxies, 'forwarded_header' => 'X-Real-IP' ] )->client_ip() );
		$this->assertSame( '203.0.113.7', $this->context( [ 'trusted_proxies' => $proxies, 'forwarded_header' => 'True-Client-IP' ] )->client_ip() );
		$this->assertSame( '203.0.113.8', $this->context( [ 'trusted_proxies' => $proxies, 'forwarded_header' => 'CF-Connecting-IP' ] )->client_ip() );
		$this->assertSame( '198.51.100.9', $this->context( [ 'trusted_proxies' => $proxies, 'forwarded_header' => 'X-Forwarded-For' ] )->client_ip() );
	}

	public function test_an_invalid_forwarded_header_setting_falls_back_to_x_forwarded_for(): void {
		$settings = new Settings();
		$this->assertSame( 'X-Forwarded-For', $settings->sanitize( [ 'forwarded_header' => 'X-Evil' ] )['forwarded_header'] );
		$this->assertSame( 'X-Forwarded-For', $settings->sanitize( [ 'forwarded_header' => [ 'CF-Connecting-IP' ] ] )['forwarded_header'] );
		$this->assertSame( 'X-Forwarded-For', $settings->sanitize( [] )['forwarded_header'] );
		$this->assertSame( 'CF-Connecting-IP', $settings->sanitize( [ 'forwarded_header' => 'cf-connecting-ip' ] )['forwarded_header'], 'control: a known header is kept, in its canonical spelling' );
		$this->assertSame( [ 'X-Forwarded-For', 'CF-Connecting-IP', 'X-Real-IP', 'True-Client-IP' ], Settings::FORWARDED_HEADERS );

		// A value that bypassed sanitize() (a direct option write) is treated as the default at read time.
		$_SERVER['REMOTE_ADDR']           = '10.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '203.0.113.5';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.9';
		$this->assertSame( '203.0.113.5', $this->context( [ 'trusted_proxies' => [ '10.0.0.1' ], 'forwarded_header' => 'X-Evil' ] )->client_ip() );
	}

	public function test_the_settings_page_offers_the_header_as_a_select_under_trusted_proxies(): void {
		bfag_test_grant( Capabilities::MANAGE );
		update_option( 'bfag_settings', [ 'forwarded_header' => 'CF-Connecting-IP' ] );
		$page   = new SettingsPage( new Settings() );
		$fields = $page->groups()['ratelimit']['fields'];
		$this->assertSame( [ 'rate_limit_enabled', 'rate_limit_exempt_editors', 'trusted_proxies', 'forwarded_header' ], array_keys( $fields ) );
		$this->assertSame( 'select', $fields['forwarded_header']['type'] );
		$this->assertSame( 'The header your proxy writes the real client address into. Cloudflare: CF-Connecting-IP. Only this header is read, and only when the request comes from a trusted proxy — so a visitor cannot pick their own address.', $fields['forwarded_header']['description'] );

		$html = bfag_test_render( [ $page, 'render' ] );
		$this->assertStringContainsString( '<select id="bfag-setting-forwarded-header" name="bfag[forwarded_header]">', $html );
		foreach ( Settings::FORWARDED_HEADERS as $header ) {
			$this->assertStringContainsString( '<option value="' . $header . '"', $html, $header );
		}
		$this->assertMatchesRegularExpression( '/<option value="CF-Connecting-IP"\s+selected="selected">/', $html, 'the stored choice is selected' );
		$this->assertDoesNotMatchRegularExpression( '/<option value="X-Real-IP"\s+selected/', $html, 'control: the others are not' );
		$this->assertStringContainsString( 'so a visitor cannot pick their own address', $html );
		$this->assertTrue( strpos( $html, 'name="bfag[trusted_proxies]"' ) < strpos( $html, 'name="bfag[forwarded_header]"' ), 'under Trusted proxies' );
	}

	// ------------------------------------------------------------------
	// SEC-R1-10: no-cache / max-age=0 / s-maxage=0 are not stored.
	// ------------------------------------------------------------------

	public function test_no_cache_and_zero_max_age_responses_are_not_cacheable(): void {
		$manager = $this->cache_manager();

		$this->assertTrue( $manager->is_cacheable_response( new \WP_REST_Response( [ 'x' => 1 ], 200, [ 'Cache-Control' => 'public, max-age=60' ] ) ), 'control: a positive max-age is stored' );
		$this->assertTrue( $manager->is_cacheable_response( new \WP_REST_Response( [ 'x' => 1 ], 200, [] ) ), 'control: no header at all is stored' );
		$this->assertFalse( $manager->is_cacheable_response( new \WP_REST_Response( [ 'x' => 1 ], 200, [ 'Cache-Control' => 'no-store' ] ) ), 'control: no-store is refused' );

		foreach ( [ 'no-cache, must-revalidate, max-age=0', 'no-cache', 'No-Cache', 'public, max-age=0', 'max-age=0', 's-maxage=0, public', 'max-age="0"' ] as $value ) {
			$this->assertFalse( $manager->is_cacheable_response( new \WP_REST_Response( [ 'x' => 1 ], 200, [ 'cache-control' => $value ] ) ), $value );
		}
	}

	// ------------------------------------------------------------------
	// SEC-R1-09 (base half): only outgoing deliveries can be resent under the site's HMAC.
	// ------------------------------------------------------------------

	public function test_redeliver_refuses_an_inbound_row_and_resends_an_outbound_one(): void {
		$settings   = new Settings();
		$dispatcher = new WebhookDispatcher( new DeliveryRepository( $settings ), $settings );
		$webhook    = $this->webhook_row();
		$row        = [
			'id'         => 11,
			'webhook_id' => 1,
			'event'      => 'post.updated',
			'payload'    => '{"object":{"id":5}}',
			'truncated'  => '0',
			'status'     => 'failed',
			'attempt'    => '1',
		];

		$this->wpdb->queue( 'get_row', array_merge( $row, [ 'direction' => 'in' ] ) );
		$result = $dispatcher->redeliver( 11, $webhook );
		$this->assertSame( [ 'delivery_id' => 11, 'success' => false, 'http_status' => null ], $result );
		$this->assertCount( 0, $GLOBALS['bfag_test_http_calls'], 'an unverified sender\'s payload is never signed and sent' );
		$this->assertCount( 0, $this->wpdb->calls_to( 'update' ) );

		$this->wpdb->queue( 'get_row', array_merge( $row, [ 'direction' => 'out' ] ) );
		bfag_test_queue_http( 200 );
		$result = $dispatcher->redeliver( 11, $webhook );
		$this->assertTrue( $result['success'], 'control: an outbound row is resent' );
		$this->assertCount( 1, $GLOBALS['bfag_test_http_calls'] );

		$this->wpdb->queue( 'get_row', $row );
		$this->assertFalse( $dispatcher->redeliver( 11, $webhook )['success'], 'a row with no direction is refused too' );
		$this->assertCount( 1, $GLOBALS['bfag_test_http_calls'] );
	}

	// ------------------------------------------------------------------
	// U-02: preset credentials never reach the Extra headers textarea, and survive a base-form edit.
	// ------------------------------------------------------------------

	public function test_non_header_keys_are_not_rendered_but_are_preserved_through_a_base_form_edit(): void {
		bfag_test_grant( Capabilities::MANAGE );
		$page = $this->webhook_page();
		$this->assertSame( 'X-Custom: a', $page->headers_to_lines( [ 'X-Custom' => 'a', 'preset_config' => '{"api_key":"SECRET123"}', 'Bad Name!' => 'x' ] ) );
		$this->assertSame( "X-Custom: a\nX-Other: b", $page->headers_to_lines( [ 'X-Custom' => 'a', 'X-Other' => 'b' ] ), 'control: valid names all render' );

		$row  = $this->webhook_row( [ 'headers' => '{"X-Custom":"a","preset_config":"{\"api_key\":\"SECRET123\"}"}' ] );
		$_GET = [
			'action' => 'edit',
			'id'     => '1',
		];
		$this->wpdb->queue( 'get_results', [ $row ] );
		$this->wpdb->queue( 'get_var', 1 );
		$this->wpdb->queue( 'get_var', null );
		$this->wpdb->queue( 'get_row', $row );
		$html = bfag_test_render( [ $page, 'render' ] );
		$this->assertStringContainsString( 'X-Custom: a', $html );
		$this->assertStringNotContainsString( 'SECRET123', $html );
		$this->assertStringNotContainsString( 'preset_config', $html );

		bfag_test_admin_request(
			'save_webhook',
			[
				'label'   => 'CRM',
				'url'     => 'https://hooks.example.net/in',
				'event'   => 'post.published',
				'headers' => 'X-Custom: b',
			],
			[ 'id' => '1' ]
		);
		$this->wpdb->queue( 'get_row', $row );
		$location = $this->redirect_of( [ $page, 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=webhook_saved', $location );
		$this->assertSame(
			[
				'X-Custom'      => 'b',
				'preset_config' => '{"api_key":"SECRET123"}',
			],
			json_decode( (string) $this->wpdb->calls_to( 'update' )[0]['data']['headers'], true ),
			'the edited header is stored and the hidden key kept'
		);

		bfag_test_admin_request(
			'save_webhook',
			[
				'label'   => 'CRM',
				'url'     => 'https://hooks.example.net/in',
				'event'   => 'post.published',
				'headers' => 'preset_config: {"api_key":"FORGED"}',
			],
			[ 'id' => '1' ]
		);
		$this->wpdb->queue( 'get_row', $row );
		$this->redirect_of( [ $page, 'handle_actions' ] );
		$this->assertSame(
			[
				'preset_config' => '{"api_key":"SECRET123"}',
			],
			json_decode( (string) $this->wpdb->calls_to( 'update' )[1]['data']['headers'], true ),
			'control: the textarea cannot overwrite a hidden key, and a removed header is removed'
		);
	}

	// ------------------------------------------------------------------
	// U-03 + U-07: a generated secret is shown once; regenerate shows the new one.
	// ------------------------------------------------------------------

	public function test_a_generated_secret_is_shown_once_on_the_webhooks_tab(): void {
		bfag_test_grant( Capabilities::MANAGE );
		$GLOBALS['bfag_test_user_id'] = 5;
		$page                         = $this->webhook_page();
		bfag_test_admin_request(
			'save_webhook',
			[
				'label'  => 'CRM',
				'url'    => 'https://hooks.example.net/in',
				'event'  => 'post.published',
				'secret' => '',
			]
		);
		$this->wpdb->queue( 'get_var', 0 );
		$this->wpdb->queue( 'get_var', 0 );
		$location = $this->redirect_of( [ $page, 'handle_actions' ] );

		$this->assertStringEndsWith( '&bfag_notice=webhook_secret_shown', $location );
		$stored = (string) $this->wpdb->calls_to( 'insert' )[0]['data']['secret'];
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $stored );
		$this->assertSame( $stored, get_transient( 'bfag_new_secret_5' ), 'the secret that was stored is the one held for display' );
		$this->assertSame( 120, $GLOBALS['bfag_test_transients']['bfag_new_secret_5']['expires'] - time() );

		$this->wpdb->queue( 'get_results', [ $this->webhook_row( [ 'secret' => $stored ] ) ] );
		$this->wpdb->queue( 'get_var', 1 );
		$this->wpdb->queue( 'get_var', null );
		$html = bfag_test_render( [ $page, 'render' ] );
		$this->assertStringContainsString( 'Copy this signing secret into your receiver now — it is not shown again.', $html );
		$this->assertStringContainsString( '<code data-bfag-copy-target>' . $stored . '</code>', $html );
		$this->assertStringContainsString( 'data-bfag-copy="' . $stored . '"', $html );
		$this->assertFalse( get_transient( 'bfag_new_secret_5' ), 'deleted on display' );

		$this->wpdb->queue( 'get_results', [ $this->webhook_row( [ 'secret' => $stored ] ) ] );
		$this->wpdb->queue( 'get_var', 1 );
		$this->wpdb->queue( 'get_var', null );
		$again = bfag_test_render( [ $page, 'render' ] );
		$this->assertStringNotContainsString( $stored, $again, 'a second render does not show it' );
		$this->assertStringNotContainsString( 'Copy this signing secret', $again );

		$GLOBALS['bfag_test_user_id'] = 6;
		set_transient( 'bfag_new_secret_5', 'someone-elses', 120 );
		$this->wpdb->queue( 'get_results', [] );
		$this->wpdb->queue( 'get_var', 0 );
		$this->assertStringNotContainsString( 'someone-elses', bfag_test_render( [ $page, 'render' ] ), 'scoped to the user who saved' );

		$menu = $this->admin_menu();
		$this->assertSame( 'success', $menu->notices()['webhook_secret_shown'][0] );
	}

	public function test_regenerate_shows_the_new_secret_and_a_supplied_secret_shows_nothing(): void {
		bfag_test_grant( Capabilities::MANAGE );
		$GLOBALS['bfag_test_user_id'] = 5;
		$page                         = $this->webhook_page();
		bfag_test_admin_request(
			'save_webhook',
			[
				'label'             => 'CRM',
				'url'               => 'https://hooks.example.net/in',
				'event'             => 'post.published',
				'regenerate_secret' => '1',
			],
			[ 'id' => '1' ]
		);
		$this->wpdb->queue( 'get_row', $this->webhook_row() );
		$location = $this->redirect_of( [ $page, 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=webhook_secret_shown', $location );
		$new = (string) $this->wpdb->calls_to( 'update' )[0]['data']['secret'];
		$this->assertNotSame( self::SECRET, $new );
		$this->assertSame( $new, get_transient( 'bfag_new_secret_5' ) );
		delete_transient( 'bfag_new_secret_5' );

		bfag_test_admin_request(
			'save_webhook',
			[
				'label'  => 'CRM',
				'url'    => 'https://hooks.example.net/in',
				'event'  => 'post.published',
				'secret' => 'my-own-secret',
			]
		);
		$this->wpdb->queue( 'get_var', 0 );
		$this->wpdb->queue( 'get_var', 0 );
		$location = $this->redirect_of( [ $page, 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=webhook_saved', $location, 'a supplied secret is not echoed back' );
		$this->assertFalse( get_transient( 'bfag_new_secret_5' ) );

		bfag_test_admin_request(
			'save_webhook',
			[
				'label'  => 'CRM',
				'url'    => 'https://hooks.example.net/in',
				'event'  => 'post.published',
				'secret' => '',
			],
			[ 'id' => '1' ]
		);
		$this->wpdb->queue( 'get_row', $this->webhook_row() );
		$location = $this->redirect_of( [ $page, 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=webhook_saved', $location, 'blank on edit keeps the secret and shows nothing' );
		$this->assertFalse( get_transient( 'bfag_new_secret_5' ) );
	}

	public function test_the_form_help_says_the_secret_is_shown_once(): void {
		bfag_test_grant( Capabilities::MANAGE );
		$page = $this->webhook_page();
		$this->wpdb->queue( 'get_results', [] );
		$this->wpdb->queue( 'get_var', 0 );
		$html = bfag_test_render( [ $page, 'render' ] );
		$this->assertStringContainsString( 'Leave blank and a secret is generated and shown to you once after saving.', $html );
		$this->assertStringNotContainsString( 'copy it from the receiver side', $html );

		$_GET = [
			'action' => 'edit',
			'id'     => '1',
		];
		$this->wpdb->queue( 'get_results', [ $this->webhook_row() ] );
		$this->wpdb->queue( 'get_var', 1 );
		$this->wpdb->queue( 'get_var', null );
		$this->wpdb->queue( 'get_row', $this->webhook_row() );
		$html = bfag_test_render( [ $page, 'render' ] );
		$this->assertStringContainsString( 'Replaces the secret — deliveries fail at the receiver until it is updated. The new secret is shown once after saving.', $html );
	}

	// ------------------------------------------------------------------
	// U-04: the delivery log shows the response, and the test-send notice carries the status.
	// ------------------------------------------------------------------

	public function test_the_delivery_log_shows_the_response_body_escaped(): void {
		bfag_test_grant( Capabilities::MANAGE );
		$_GET = [ 'view' => 'deliveries' ];
		$this->wpdb->queue( 'get_results', [ $this->webhook_row() ] );
		$this->wpdb->queue(
			'get_results',
			[
				$this->delivery_row(
					[
						'id'            => 10,
						'response_body' => 'RESPBODY-PLANTED <script>alert(1)</script>',
					]
				),
				$this->delivery_row(
					[
						'id'            => 11,
						'http_status'   => null,
						'response_body' => 'Connection refused',
					]
				),
				$this->delivery_row(
					[
						'id'            => 12,
						'status'        => 'pending',
						'http_status'   => null,
						'response_body' => null,
					]
				),
			]
		);
		$html = bfag_test_render( [ $this->webhook_page(), 'render' ] );

		$this->assertStringContainsString( 'RESPBODY-PLANTED', $html );
		$this->assertStringNotContainsString( '<script>', $html, 'escaped' );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertMatchesRegularExpression( '/<summary>\s*Response to delivery #\d+\s*<\/summary>/', $html, 'NF-20: the summary names its row' );
		$this->assertStringContainsString( 'HTTP 500', $html );
		$this->assertStringContainsString( 'Transport error: Connection refused', $html );
		$this->assertSame( 2, substr_count( $html, '<details' ), 'finished rows only; a pending row has nothing to show' );
	}

	public function test_the_test_send_failure_notice_includes_the_http_status(): void {
		bfag_test_grant( Capabilities::MANAGE );
		$page = $this->webhook_page();

		bfag_test_admin_request( 'test_webhook', [], [ 'id' => '1' ] );
		$this->wpdb->queue( 'get_row', $this->webhook_row() );
		bfag_test_queue_http( 503, 'down' );
		$location = $this->redirect_of( [ $page, 'handle_actions' ] );
		$this->assertStringContainsString( 'bfag_notice=webhook_test_failed', $location );
		$this->assertStringContainsString( 'bfag_http=503', $location );

		bfag_test_admin_request( 'test_webhook', [], [ 'id' => '1' ] );
		$this->wpdb->queue( 'get_row', $this->webhook_row() );
		bfag_test_queue_http_error();
		$location = $this->redirect_of( [ $page, 'handle_actions' ] );
		$this->assertStringContainsString( 'bfag_http=none', $location );

		bfag_test_admin_request( 'test_webhook', [], [ 'id' => '1' ] );
		$this->wpdb->queue( 'get_row', $this->webhook_row() );
		bfag_test_queue_http( 204 );
		$location = $this->redirect_of( [ $page, 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=webhook_test_ok', $location, 'control: success carries no status' );

		$menu = $this->admin_menu();
		bfag_test_set_screen( 'settings_page_bfag-gateway' );
		$_GET = [
			'bfag_notice' => 'webhook_test_failed',
			'bfag_http'   => '503',
		];
		$this->assertStringContainsString( 'Test delivery failed (HTTP 503).', bfag_test_render( [ $menu, 'render_notices' ] ) );
		$_GET['bfag_http'] = 'none';
		$this->assertStringContainsString( 'Test delivery failed (no HTTP response: transport error).', bfag_test_render( [ $menu, 'render_notices' ] ) );
		$_GET = [ 'bfag_notice' => 'webhook_test_failed' ];
		$this->assertStringContainsString( '<p>Test delivery failed. The receiver&#039;s response is in the delivery log.</p>', bfag_test_render( [ $menu, 'render_notices' ] ), 'control: without a status, the plain text' );
	}
}
