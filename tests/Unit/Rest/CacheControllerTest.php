<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Rest;

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

	/**
	 * @param array<string, mixed> $params Body params.
	 */
	private function purge_request( array $params = [] ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/bfag/v1/cache/purge' );
		$request->set_body_params( $params );
		return $request;
	}

	public function test_the_route_is_registered_as_a_post_with_a_capability_permission_callback_and_a_sanitised_tags_arg(): void {
		$controller = new CacheController( $this->store );
		$controller->register_routes();

		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/bfag/v1/cache/purge', $routes );
		$route = $routes['/bfag/v1/cache/purge'];
		$this->assertSame( 'POST', $route['methods'] );
		$this->assertSame( [ $controller, 'purge' ], $route['callback'] );
		$this->assertSame( [ $controller, 'permission' ], $route['permission_callback'] );
		$this->assertSame( 'array', $route['args']['tags']['type'] );
		$this->assertSame( [ $controller, 'sanitize_tags' ], $route['args']['tags']['sanitize_callback'] );

		$this->assertFalse( call_user_func( $route['permission_callback'] ) );
		bfag_test_grant( Capabilities::MANAGE );
		$this->assertTrue( call_user_func( $route['permission_callback'] ) );
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
		$response = ( new CacheController( $this->store ) )->purge( $this->purge_request() );

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

		$response = ( new CacheController( $this->store ) )->purge( $this->purge_request( [ 'tags' => [ 'Posts', 'products', '', 'posts', '<x>' ] ] ) );

		$this->assertSame(
			[
				'purged' => 'tags',
				'tags'   => [ 'posts', 'products', 'x' ],
			],
			$response->get_data()
		);
		$this->assertSame( $generation, $this->store->generation(), 'the global generation is untouched' );
		$this->assertSame( $posts + 1, $this->store->tag_generation( 'posts' ) );
		$this->assertSame( $other, $this->store->tag_generation( 'other' ) );
		$this->assertSame( [ [ 'tags', [ 'posts', 'products', 'x' ] ] ], $purged );
	}

	public function test_sanitize_tags_accepts_a_csv_string_and_rejects_junk(): void {
		$controller = new CacheController( $this->store );
		$this->assertSame( [ 'a', 'b-c' ], $controller->sanitize_tags( 'A, b-c ,,' ) );
		$this->assertSame( [], $controller->sanitize_tags( 42 ) );
		$this->assertSame( [], $controller->sanitize_tags( null ) );
		$this->assertSame( [ 'ok' ], $controller->sanitize_tags( [ [ 'nested' ], 'ok' ] ) );
	}
}
