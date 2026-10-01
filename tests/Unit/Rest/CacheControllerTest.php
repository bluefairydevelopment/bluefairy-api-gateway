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
	 * N3: an API key (Pro sets `bfag_api_key_scope` on the request) whose
	 * scope satisfies `cache:purge` passes the permission callback even
	 * with no user behind it; a `read` key does not; no key and no
	 * capability does not; the capability alone does.
	 */
	public function test_permission_accepts_a_key_scope_that_satisfies_the_route_without_a_user(): void {
		$permission = [ $this->controller(), 'permission' ];
		$this->assertFalse( is_user_logged_in(), 'precondition: anonymous' );

		$request = $this->purge_request();
		$request->set_param( 'bfag_api_key_scope', 'cache:purge' );
		$this->assertTrue( $permission( $request ), 'scope cache:purge, no user: allowed' );

		$request = $this->purge_request();
		$request->set_param( 'bfag_api_key_scope', 'read write webhook:write' );
		$this->assertFalse( $permission( $request ), 'every other scope: refused' );

		$request = $this->purge_request();
		$request->set_param( 'bfag_api_key_scope', 42 );
		$this->assertFalse( $permission( $request ), 'a non-string scope param is ignored' );

		$this->assertFalse( $permission( $this->purge_request() ), 'no param, no capability: refused' );

		bfag_test_grant( Capabilities::MANAGE );
		$this->assertTrue( $permission( $this->purge_request() ), 'the capability alone: allowed' );
		$request = $this->purge_request();
		$request->set_param( 'bfag_api_key_scope', 'read' );
		$this->assertTrue( $permission( $request ), 'the capability wins whatever the key scope' );
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
