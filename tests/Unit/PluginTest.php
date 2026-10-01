<?php
/**
 * T8: the boot sequence and the service registry.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit;

use BlueFairy\ApiGateway\Admin\KpiCron;
use BlueFairy\ApiGateway\Cache\CachePurger;
use BlueFairy\ApiGateway\Installer;
use BlueFairy\ApiGateway\Plugin;
use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Tests\TestCase;
use BlueFairy\ApiGateway\Webhook\WebhookDispatcher;

final class PluginTest extends TestCase {

	/**
	 * Every service id ARCHITECTURE.md documents.
	 *
	 * @var list<string>
	 */
	private const SERVICES = [ 'settings', 'request', 'cache.store', 'cache.rules', 'cache.stats', 'cache.manager', 'cache.purger', 'ratelimit.rules', 'ratelimit.hits', 'webhook.repository', 'webhook.deliveries', 'webhook.dispatcher', 'webhook.events', 'kpi.cron' ];

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb->always( 'get_var', '0' );
	}

	public function test_bfag_loaded_fires_before_any_subsystem_binds_and_bfag_booted_after(): void {
		$seen = null;
		add_action(
			'bfag_loaded',
			static function () use ( &$seen ): void {
				$seen = [
					'rest_pre_dispatch'  => has_filter( 'rest_pre_dispatch' ),
					'rest_post_dispatch' => has_filter( 'rest_post_dispatch' ),
					'rest_api_init'      => has_action( 'rest_api_init' ),
					'booted'             => did_action( 'bfag_booted' ),
					'settings'           => Plugin::service( 'settings' ) instanceof Settings,
				];
			}
		);

		Plugin::boot();

		$this->assertSame(
			[
				'rest_pre_dispatch'  => false,
				'rest_post_dispatch' => false,
				'rest_api_init'      => false,
				'booted'             => 0,
				'settings'           => true,
			],
			$seen,
			'a bfag_loaded listener sees no subsystem hook yet, but may read settings'
		);
		$this->assertSame( 1, did_action( 'bfag_loaded' ) );
		$this->assertSame( 1, did_action( 'bfag_booted' ) );
		$this->assertTrue( (bool) has_filter( 'rest_pre_dispatch' ), 'control: the subsystems are bound after boot' );
		$this->assertTrue( (bool) has_action( 'rest_api_init' ) );
		$this->assertSame( 20, has_action( 'wp_initialize_site', [ Installer::class, 'initialize_site' ] ) );
	}

	public function test_every_documented_service_is_set(): void {
		Plugin::boot();
		foreach ( self::SERVICES as $id ) {
			$this->assertIsObject( Plugin::service( $id ), $id );
		}
		$this->assertNull( Plugin::service( 'no.such' ) );
		$this->assertInstanceOf( CachePurger::class, Plugin::service( 'cache.purger' ) );
		$this->assertInstanceOf( KpiCron::class, Plugin::service( 'kpi.cron' ) );
		$this->assertInstanceOf( WebhookDispatcher::class, Plugin::service( 'webhook.dispatcher' ) );
		$this->assertSame( Plugin::service( 'webhook.repository' ), ( new \ReflectionProperty( WebhookDispatcher::class, 'webhooks' ) )->getValue( Plugin::service( 'webhook.dispatcher' ) ), 'F16: the dispatcher holds the shared repository' );
	}

	public function test_boot_twice_is_a_no_op(): void {
		Plugin::boot();
		$settings = Plugin::service( 'settings' );
		Plugin::boot();
		$this->assertSame( 1, did_action( 'bfag_loaded' ) );
		$this->assertSame( 1, did_action( 'bfag_booted' ) );
		$this->assertSame( $settings, Plugin::service( 'settings' ), 'the same instances' );
		$this->assertCount( 1, $GLOBALS['bfag_test_hooks']['rest_pre_dispatch'][5] ?? [], 'the limiter is bound once' );
	}

	public function test_reset_clears_the_registry_and_the_guard_so_boot_runs_again(): void {
		Plugin::boot();
		Plugin::$dispatching = true;
		Plugin::reset();
		$this->assertNull( Plugin::service( 'settings' ) );
		$this->assertFalse( Plugin::$dispatching );
		Plugin::boot();
		$this->assertSame( 2, did_action( 'bfag_loaded' ), 'booted again after reset' );
	}

	public function test_set_registers_an_instance_under_its_id(): void {
		$object = new \stdClass();
		Plugin::set( 'addon.thing', $object );
		$this->assertSame( $object, Plugin::service( 'addon.thing' ) );
	}
}
