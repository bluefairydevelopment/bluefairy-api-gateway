<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Rest;

use BlueFairy\ApiGateway\Cache\CachePurger;
use BlueFairy\ApiGateway\Cache\CacheRuleRepository;
use BlueFairy\ApiGateway\Cache\CacheStore;
use BlueFairy\ApiGateway\Capabilities;
use BlueFairy\ApiGateway\Rest\CacheController;
use BlueFairy\ApiGateway\Tests\TestCase;

final class CacheControllerTest extends TestCase {

	private CacheStore $store;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['bfag_test_rest_routes'] = [];
		$this->store                      = new CacheStore();
	}

	private function controller(): CacheController {
		return new CacheController( new CachePurger( $this->store, new CacheRuleRepository() ) );
	}

	/**
	 * Seed the enabled-rules cache with one rule carrying $tags, so the
	 * purger treats them as known (F11: the endpoint purges through it).
	 *
	 * @param string $tags Comma-separated tags.
	 */
	private function rule_carrying( string $tags ): void {
		wp_cache_set(
			CacheRuleRepository::CACHE_KEY,
			[
				[
					'id'         => 1,
					'route_glob' => '**',
					'tags'       => $tags,
					'enabled'    => 1,
				],
			],
			'bfag'
		);
	}

	/**
	 * @param array<string, mixed> $params Body params.
	 */
	private function purge_request( array $params = [] ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/bfag/v1/cache/purge' );
		$request->set_body_params( $params );
		return $request;
	}

	public function test_the_route_is_registered_as_a_post_with_a_capability_permission_callback_and_a_sanitised_tags_arg(): void {
		$controller = $this->controller();
		$controller->register_routes();

		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/bfag/v1/cache/purge', $routes );
		$route = $routes['/bfag/v1/cache/purge'];
		$this->assertSame( 'POST', $route['methods'] );
		$this->assertSame( [ $controller, 'purge' ], $route['callback'] );
		$this->assertSame( [ $controller, 'permission' ], $route['permission_callback'] );
		$this->assertSame( 'array', $route['args']['tags']['type'] );
		$this->assertSame( [ $controller, 'sanitize_tags' ], $route['args']['tags']['sanitize_callback'] );

		$this->assertFalse( call_user_func( $route['permission_callback'], $this->purge_request() ) );
		bfag_test_grant( Capabilities::MANAGE );
		$this->assertTrue( call_user_func( $route['permission_callback'], $this->purge_request() ) );
	}

	/**
	 * N3: an API key (the Pro middleware answers `bfag_request_key_scope`
	 * with the authenticated key's scopes) that holds `cache:purge` passes
	 * the permission callback even with no user behind it; a key holding
	 * only `read` does not; no key and no capability does not; the
	 * capability alone does.
	 */
	public function test_permission_accepts_a_key_scope_that_satisfies_the_route_without_a_user(): void {
		$permission = [ $this->controller(), 'permission' ];
		$this->assertFalse( is_user_logged_in(), 'precondition: anonymous' );

		$this->answer_key_scope( 'read cache:purge' );
		$this->assertTrue( $permission( $this->purge_request() ), 'key holds cache:purge, no user: allowed' );

		$this->answer_key_scope( 'read write webhook:write' );
		$this->assertFalse( $permission( $this->purge_request() ), 'every other scope: refused' );

		$this->answer_key_scope( 42 );
		$this->assertFalse( $permission( $this->purge_request() ), 'a non-string answer is no key' );

		$this->answer_key_scope( null );
		$this->assertFalse( $permission( $this->purge_request() ), 'no key, no capability: refused' );

		bfag_test_grant( Capabilities::MANAGE );
		$this->assertTrue( $permission( $this->purge_request() ), 'the capability alone: allowed' );
		$this->answer_key_scope( 'read' );
		$this->assertTrue( $permission( $this->purge_request() ), 'the capability wins whatever the key scope' );
	}

	/**
	 * R2-01: a scope planted in the query string, the body or the parsed
	 * params by a client with no key and no capability never purges.
	 */
	public function test_permission_refuses_a_scope_forged_in_request_params(): void {
		$permission = [ $this->controller(), 'permission' ];
		$this->assertFalse( is_user_logged_in(), 'precondition: anonymous' );

		$request = $this->purge_request( [ 'bfag_api_key_scope' => 'cache:purge' ] );
		$this->assertSame( 'cache:purge', $request->get_param( 'bfag_api_key_scope' ), 'control: the body param is visible' );
		$this->assertFalse( $permission( $request ), 'body-forged scope' );

		$request = $this->purge_request();
		$request->set_query_params( [ 'bfag_api_key_scope' => 'cache:purge' ] );
		$this->assertFalse( $permission( $request ), 'query-forged scope' );

		$request = $this->purge_request();
		$request->set_param( 'bfag_api_key_scope', 'cache:purge' );
		$this->assertFalse( $permission( $request ), 'set_param-forged scope' );
	}

	/**
	 * Answer `bfag_request_key_scope` as the Pro middleware would.
	 *
	 * @param mixed $scope The answer.
	 */
	private function answer_key_scope( mixed $scope ): void {
		unset( $GLOBALS['bfag_test_hooks']['bfag_request_key_scope'] );
		add_filter( 'bfag_request_key_scope', static fn () => $scope, 10, 2 );
	}

	public function test_without_tags_everything_is_flushed(): void {
		$purged = [];
		add_action(
			'bfag_cache_purged',
			static function ( string $scope, array $tags ) use ( &$purged ): void {
				$purged[] = [ $scope, $tags ];
			},
			10,
			2
		);
		$before   = $this->store->generation();
		$response = $this->controller()->purge( $this->purge_request() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			[
				'purged' => 'all',
				'tags'   => [],
			],
			$response->get_data()
		);
		$this->assertSame( $before + 1, $this->store->generation() );
		$this->assertSame( [ [ 'all', [] ] ], $purged );
	}

	public function test_with_tags_only_those_generations_are_bumped(): void {
		$this->rule_carrying( 'posts,products,x' );
		$purged = [];
		add_action(
			'bfag_cache_purged',
			static function ( string $scope, array $tags ) use ( &$purged ): void {
				$purged[] = [ $scope, $tags ];
			},
			10,
			2
		);
		$generation = $this->store->generation();
		$posts      = $this->store->tag_generation( 'posts' );
		$other      = $this->store->tag_generation( 'other' );

		$response = $this->controller()->purge( $this->purge_request( [ 'tags' => [ 'Posts', 'products', '', 'posts', '<x>', 'ghost' ] ] ) );

		$this->assertSame(
			[
				'purged' => 'tags',
				'tags'   => [ 'posts', 'products', 'x' ],
			],
			$response->get_data(),
			'F11: `ghost` — a tag no enabled rule carries — is dropped by the purger, as it is for every other caller'
		);
		$this->assertSame( $generation, $this->store->generation(), 'the global generation is untouched' );
		$this->assertSame( $posts + 1, $this->store->tag_generation( 'posts' ) );
		$this->assertSame( $other, $this->store->tag_generation( 'other' ) );
		$this->assertSame( 1, $this->store->tag_generation( 'ghost' ), 'unknown tag: not bumped' );
		$this->assertSame( [ [ 'tags', [ 'posts', 'products', 'x' ] ] ], $purged, 'bfag_cache_purged fires once, from the purger' );
	}

	/**
	 * D-AG-1 (found by the rig E2E suite): a purge naming only tags no
	 * enabled rule carries bumps nothing — it must answer "tags" with an
	 * empty list, never "all", or a deploy script believes everything was
	 * flushed while every entry stays warm.
	 */
	public function test_a_purge_of_only_unknown_tags_answers_tags_not_all_and_flushes_nothing(): void {
		$this->rule_carrying( 'products' );
		$generation = $this->store->generation();

		$response = $this->controller()->purge( $this->purge_request( [ 'tags' => [ 'nobody-has-this' ] ] ) );

		$this->assertSame(
			[
				'purged' => 'tags',
				'tags'   => [],
			],
			$response->get_data()
		);
		$this->assertSame( $generation, $this->store->generation(), 'nothing was flushed' );
		$this->assertSame( 1, $this->store->tag_generation( 'nobody-has-this' ) );
	}

	/**
	 * `sanitize_key()` would turn `post:42` into `post42`, a tag the Pro
	 * purger never bumps; the endpoint must keep the colon.
	 */
	public function test_purging_a_colon_tag_bumps_that_tag_not_its_colonless_twin(): void {
		$this->rule_carrying( 'post:42,type:product' );
		$response = $this->controller()->purge( $this->purge_request( [ 'tags' => [ 'post:42', 'Type:Product' ] ] ) );

		$this->assertSame( [ 'post:42', 'type:product' ], $response->get_data()['tags'] );
		$this->assertSame( 2, $this->store->tag_generation( 'post:42' ) );
		$this->assertSame( 2, $this->store->tag_generation( 'type:product' ) );
		$this->assertSame( 1, $this->store->tag_generation( 'post42' ), 'post42 is not post:42' );
		$this->assertArrayHasKey( 'post:42', get_option( CacheStore::TAG_GENERATIONS_OPTION ) );
		$this->assertArrayNotHasKey( 'post42', get_option( CacheStore::TAG_GENERATIONS_OPTION ) );
	}

	public function test_sanitize_tags_accepts_a_csv_string_and_rejects_junk(): void {
		$controller = $this->controller();
		$this->assertSame( [ 'a', 'b-c' ], $controller->sanitize_tags( 'A, b-c ,,' ) );
		$this->assertSame( [], $controller->sanitize_tags( 42 ) );
		$this->assertSame( [], $controller->sanitize_tags( null ) );
		$this->assertSame( [ 'ok' ], $controller->sanitize_tags( [ [ 'nested' ], 'ok' ] ) );
		$this->assertSame( [ 'post:42', 'tax:category' ], $controller->sanitize_tags( 'post:42, tax:category' ), 'the colon of a Pro tag survives' );
		$this->assertSame( [ str_repeat( 'a', 50 ) ], $controller->sanitize_tags( [ str_repeat( 'a', 60 ) ] ), 'capped at 50 like CacheStore' );
	}
}
