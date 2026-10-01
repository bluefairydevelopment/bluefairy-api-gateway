<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Rest;

use BlueFairy\ApiGateway\Cache\CacheStatsRepository;
use BlueFairy\ApiGateway\Capabilities;
use BlueFairy\ApiGateway\Rest\StatusController;
use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Tests\TestCase;
use BlueFairy\ApiGateway\Webhook\DeliveryRepository;
use BlueFairy\ApiGateway\Webhook\WebhookRepository;

final class StatusControllerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['bfag_test_rest_routes'] = [];
	}

	private function controller(): StatusController {
		return new StatusController( new CacheStatsRepository(), new DeliveryRepository( new Settings() ), new WebhookRepository() );
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

		$this->assertFalse( call_user_func( $route['permission_callback'] ), 'no capability, no status' );
		bfag_test_grant( Capabilities::MANAGE );
		$this->assertTrue( call_user_func( $route['permission_callback'] ) );
	}

	public function test_get_status_reports_version_kpis_counts_and_pro(): void {
		set_transient( 'bfag_kpis', [ 'cache_hit_rate' => 0.5 ], 360 );
		$this->wpdb->queue( 'get_var', 2 ); // webhooks count.
		$this->wpdb->queue(
			'get_row',
			[
				'hits'   => 10,
				'misses' => 5,
			]
		);
		$this->wpdb->queue(
			'get_results',
			[
				[
					'status' => 'failed',
					'n'      => 1,
				],
			]
		);

		$response = $this->controller()->get_status( new \WP_REST_Request( 'GET', '/bfag/v1/status' ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( BFAG_VERSION, $data['version'] );
		$this->assertSame( [ 'cache_hit_rate' => 0.5 ], $data['kpis'] );
		$this->assertSame( 2, $data['counts']['webhooks'] );
		$this->assertSame(
			[
				'hits'   => 10,
				'misses' => 5,
			],
			$data['counts']['cache_24h']
		);
		$this->assertSame( 1, $data['counts']['deliveries_24h']['failed'] );
		$this->assertFalse( $data['pro'] );
		$this->assertSame( 1, preg_match( "/bucket_start >= '([^']+)'/", $this->wpdb->queries_matching( 'bfag_cache_stats' )[0], $m ) );
		$this->assertEqualsWithDelta( time() - DAY_IN_SECONDS, strtotime( $m[1] . ' UTC' ), 5, 'the window is the last 24 hours' );
	}

	public function test_kpis_are_null_when_cold_and_pro_follows_the_filter(): void {
		add_filter( 'bfag_is_pro', '__return_true' );
		$data = $this->controller()->get_status( new \WP_REST_Request( 'GET', '/bfag/v1/status' ) )->get_data();
		$this->assertNull( $data['kpis'] );
		$this->assertTrue( $data['pro'] );
	}
}
