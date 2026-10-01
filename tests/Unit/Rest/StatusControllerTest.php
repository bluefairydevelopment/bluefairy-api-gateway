<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Rest;

use BlueFairy\ApiGateway\Capabilities;
use BlueFairy\ApiGateway\Rest\StatusController;
use BlueFairy\ApiGateway\Tests\TestCase;
use BlueFairy\ApiGateway\Webhook\WebhookRepository;

final class StatusControllerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['bfag_test_rest_routes'] = [];
	}

	private function controller(): StatusController {
		return new StatusController( new WebhookRepository() );
	}

	public function test_the_route_is_registered_as_a_get_with_a_capability_permission_callback(): void {
		$controller = $this->controller();
		$controller->register_routes();

		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/bfag/v1/status', $routes );
		$route = $routes['/bfag/v1/status'];
		$this->assertSame( 'GET', $route['methods'] );
		$this->assertSame( [ $controller, 'get_status' ], $route['callback'] );
		$this->assertSame( [ $controller, 'permission' ], $route['permission_callback'] );
		$this->assertArrayHasKey( 'args', $route );

		$request = new \WP_REST_Request( 'GET', '/bfag/v1/status' );
		$this->assertFalse( call_user_func( $route['permission_callback'], $request ), 'no capability, no status' );
		bfag_test_grant( Capabilities::MANAGE );
		$this->assertTrue( call_user_func( $route['permission_callback'], $request ) );
	}

	/**
	 * N3: a `read` key passes; a `write` key does not (write does not imply read).
	 */
	public function test_permission_accepts_a_read_key_scope_without_a_user(): void {
		$permission = [ $this->controller(), 'permission' ];
		$request    = new \WP_REST_Request( 'GET', '/bfag/v1/status' );
		$this->assertFalse( is_user_logged_in(), 'precondition: anonymous' );

		add_filter( 'bfag_request_key_scope', static fn () => 'read', 10, 2 );
		$this->assertTrue( $permission( $request ), 'a key holding read' );

		unset( $GLOBALS['bfag_test_hooks']['bfag_request_key_scope'] );
		add_filter( 'bfag_request_key_scope', static fn () => 'write cache:purge', 10, 2 );
		$this->assertFalse( $permission( $request ), 'neither write nor cache:purge implies read' );
	}

	/**
	 * R2-01: a scope forged in the query string, the body or the parsed
	 * params — no key, no capability — never reads the status.
	 */
	public function test_permission_refuses_a_scope_forged_in_request_params(): void {
		$permission = [ $this->controller(), 'permission' ];
		$this->assertFalse( is_user_logged_in(), 'precondition: anonymous' );

		$request = new \WP_REST_Request( 'GET', '/bfag/v1/status' );
		$request->set_query_params( [ 'bfag_api_key_scope' => 'read' ] );
		$this->assertSame( 'read', $request->get_param( 'bfag_api_key_scope' ), 'control: the query param is visible' );
		$this->assertFalse( $permission( $request ), 'query-forged scope' );

		$request = new \WP_REST_Request( 'GET', '/bfag/v1/status' );
		$request->set_body_params( [ 'bfag_api_key_scope' => 'read' ] );
		$this->assertFalse( $permission( $request ), 'body-forged scope' );

		$request = new \WP_REST_Request( 'GET', '/bfag/v1/status' );
		$request->set_param( 'bfag_api_key_scope', 'read' );
		$this->assertFalse( $permission( $request ), 'set_param-forged scope' );
	}

	/**
	 * P11: a monitor polls this route, so the 24-hour counts come from the
	 * KPI transient and the only live query is the webhook count, cached a
	 * minute. No aggregate runs per poll.
	 */
	public function test_get_status_reports_version_kpis_counts_and_pro(): void {
		set_transient(
			'bfag_kpis',
			[
				'cache_hit_rate'     => 0.5,
				'cache_hits'         => 10,
				'cache_misses'       => 5,
				'webhook_deliveries' => [ 'success' => 4, 'failed' => 1, 'pending' => 0, 'retrying' => 0 ],
			],
			360
		);
		$this->wpdb->queue( 'get_var', 2 ); // webhooks count.

		$response = $this->controller()->get_status( new \WP_REST_Request( 'GET', '/bfag/v1/status' ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( BFAG_VERSION, $data['version'] );
		$this->assertSame( 0.5, $data['kpis']['cache_hit_rate'] );
		$this->assertSame( 2, $data['counts']['webhooks'] );
		$this->assertSame( [ 'hits' => 10, 'misses' => 5 ], $data['counts']['cache_24h'] );
		$this->assertSame( [ 'success' => 4, 'failed' => 1, 'pending' => 0, 'retrying' => 0 ], $data['counts']['deliveries_24h'] );
		$this->assertSame( 1, $data['counts']['deliveries_24h']['failed'] );
		$this->assertFalse( $data['pro'] );
		$this->assertSame( [], $this->wpdb->queries_matching( 'bfag_cache_stats' ), 'no aggregate per poll' );
		$this->assertSame( [], $this->wpdb->queries_matching( 'bfag_webhook_deliveries' ) );
		$this->assertCount( 1, $this->wpdb->calls, 'the one live query is the webhook count' );

		// The next poll within a minute reads the count the controller itself cached; a minute later it asks again.
		$this->assertSame( 2, wp_cache_get( StatusController::COUNT_KEY, 'bfag' ), 'the controller wrote the count to the object cache' );
		$this->assertSame( bfag_test_now() + 60, $GLOBALS['bfag_test_cache_expires']['bfag'][ StatusController::COUNT_KEY ], 'for a minute' );
		$this->wpdb->calls = [];
		$this->wpdb->queue( 'get_var', 3 );
		$this->assertSame( 2, $this->controller()->get_status( new \WP_REST_Request( 'GET', '/bfag/v1/status' ) )->get_data()['counts']['webhooks'] );
		$this->assertSame( [], $this->wpdb->calls, 'cached' );
		bfag_test_set_time( bfag_test_now() + 61 );
		$this->assertSame( 3, $this->controller()->get_status( new \WP_REST_Request( 'GET', '/bfag/v1/status' ) )->get_data()['counts']['webhooks'] );
		$this->assertCount( 1, $this->wpdb->calls, 'expired: counted again' );
	}

	public function test_kpis_are_null_when_cold_and_pro_follows_the_filter(): void {
		add_filter( 'bfag_is_pro', '__return_true' );
		$data = $this->controller()->get_status( new \WP_REST_Request( 'GET', '/bfag/v1/status' ) )->get_data();
		$this->assertNull( $data['kpis'] );
		$this->assertNull( $data['counts']['cache_24h'], 'cold: no KPI run yet, and no aggregate run to fill in for it' );
		$this->assertNull( $data['counts']['deliveries_24h'] );
		$this->assertSame( 0, $data['counts']['webhooks'] );
		$this->assertTrue( $data['pro'] );
	}
}
