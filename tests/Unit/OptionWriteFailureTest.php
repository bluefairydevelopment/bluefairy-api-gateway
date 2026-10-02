<?php
/**
 * F21 / WPMAP-110: a refused option write is reported, never "saved" or
 * "purged".
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit;

use BlueFairy\ApiGateway\Admin\AdminMenu;
use BlueFairy\ApiGateway\Admin\CachePage;
use BlueFairy\ApiGateway\Admin\KpiCron;
use BlueFairy\ApiGateway\Admin\ProUpsell;
use BlueFairy\ApiGateway\Admin\SettingsPage;
use BlueFairy\ApiGateway\Cache\CachePurger;
use BlueFairy\ApiGateway\Cache\CacheRuleRepository;
use BlueFairy\ApiGateway\Cache\CacheStatsRepository;
use BlueFairy\ApiGateway\Cache\CacheStore;
use BlueFairy\ApiGateway\Capabilities;
use BlueFairy\ApiGateway\Logger;
use BlueFairy\ApiGateway\RateLimit\HitCounter;
use BlueFairy\ApiGateway\RateLimit\RateLimitRuleRepository;
use BlueFairy\ApiGateway\Rest\CacheController;
use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Tests\TestCase;
use BlueFairy\ApiGateway\Webhook\DeliveryRepository;
use BlueFairy\ApiGateway\Webhook\EventRegistry;
use BlueFairy\ApiGateway\Webhook\WebhookDispatcher;
use BlueFairy\ApiGateway\Webhook\WebhookRepository;

/**
 * The harness refuses every write to an option named in
 * `$GLOBALS['bfag_test_option_write_fails']` (stores nothing, returns false)
 * and, as core, returns false for an unchanged value too — so each failure
 * case has an "unchanged" twin that must still read as success.
 */
final class OptionWriteFailureTest extends TestCase {

	private CacheStore $store;

	/**
	 * Every `bfag_cache_purged` seen, as [ scope, tags ].
	 *
	 * @var list<array{0:string, 1:array<mixed>}>
	 */
	private array $purges = [];

	protected function setUp(): void {
		parent::setUp();
		$this->store = new CacheStore();
		bfag_test_grant( Capabilities::MANAGE );
		update_option( 'bfag_settings', [ 'debug_logging' => true ] + Settings::DEFAULTS );
		Logger::use_settings( new Settings() );
		ini_set( 'error_log', '/dev/null' );
	}

	private function refuse( string ...$options ): void {
		$GLOBALS['bfag_test_option_write_fails'] = $options;
	}

	private function record_purges(): void {
		add_action(
			'bfag_cache_purged',
			function ( string $scope, array $tags ): void {
				$this->purges[] = [ $scope, $tags ];
			},
			10,
			2
		);
	}

	private function purger(): CachePurger {
		return new CachePurger( $this->store, new CacheRuleRepository() );
	}

	// ------------------------------------------------------------------
	// Settings
	// ------------------------------------------------------------------

	public function test_settings_update_reports_a_refused_write_and_logs_it(): void {
		$settings = new Settings();
		$this->assertTrue( $settings->last_update_succeeded(), 'nothing attempted yet' );

		$this->refuse( 'bfag_settings' );
		$settings->update( [ 'retention_days' => '9' ] + Settings::DEFAULTS );

		$this->assertFalse( $settings->last_update_succeeded() );
		$this->assertSame( 30, ( new Settings() )->get( 'retention_days' ), 'nothing stored' );
		$this->assertStringContainsString( 'settings not saved', implode( "\n", Logger::lines() ) );
	}

	/**
	 * Twin: saving the values already stored makes update_option() return
	 * false (core skips the write) — that is a success, not a failure.
	 */
	public function test_settings_update_of_unchanged_values_is_a_success(): void {
		$settings = new Settings();
		$settings->update( [ 'retention_days' => '9' ] + Settings::DEFAULTS );
		$this->assertTrue( $settings->last_update_succeeded() );
		$this->assertFalse( update_option( 'bfag_settings', get_option( 'bfag_settings' ) ), 'control: the harness returns false for an unchanged value' );

		$settings->update( [ 'retention_days' => '9' ] + Settings::DEFAULTS );
		$this->assertTrue( $settings->last_update_succeeded(), 'unchanged is not failed' );
		$this->assertSame( [], array_filter( Logger::lines(), static fn( string $l ): bool => str_contains( $l, 'not saved' ) ) );
	}

	public function test_the_settings_page_says_not_saved_and_does_not_fire_settings_saved(): void {
		$fired = 0;
		add_action(
			'bfag_settings_saved',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);
		$this->refuse( 'bfag_settings' );
		bfag_test_admin_request( 'save_settings', [ 'retention_days' => '9' ] );
		$exit = bfag_test_catch_exit( [ new SettingsPage( new Settings() ), 'handle_actions' ] );

		$this->assertSame( 'redirect', $exit->kind );
		$this->assertStringEndsWith( 'bfag_notice=settings_save_failed', (string) $exit->payload );
		$this->assertSame( 0, $fired );
		$notices = ( new AdminMenu( ...$this->menu_args() ) )->notices();
		$this->assertSame( 'error', $notices['settings_save_failed'][0] );

		// Control: the same save with the write accepted.
		$this->refuse();
		bfag_test_admin_request( 'save_settings', [ 'retention_days' => '9' ] );
		$exit = bfag_test_catch_exit( [ new SettingsPage( new Settings() ), 'handle_actions' ] );
		$this->assertStringEndsWith( 'bfag_notice=settings_saved', (string) $exit->payload );
		$this->assertSame( 1, $fired );
	}

	// ------------------------------------------------------------------
	// Cache store and purger
	// ------------------------------------------------------------------

	public function test_flush_all_reports_a_refused_generation_write_on_the_transient_backend(): void {
		$this->refuse( CacheStore::GENERATION_OPTION );
		$this->assertFalse( $this->store->flush_all() );
		$this->assertSame( 1, $this->store->generation(), 'not bumped' );
		$this->assertStringContainsString( 'cache generation not stored', implode( "\n", Logger::lines() ) );

		$this->refuse();
		$this->assertTrue( $this->store->flush_all(), 'control' );
		$this->assertSame( 2, $this->store->generation() );
	}

	/**
	 * With a persistent object cache that can flush a group, the responses
	 * are gone even when the generation write fails: that IS a purge.
	 * Without group support it is not.
	 */
	public function test_flush_all_counts_a_persistent_group_flush_as_a_purge(): void {
		wp_using_ext_object_cache( true );
		$this->refuse( CacheStore::GENERATION_OPTION );
		wp_cache_set( 'k', 'response', CacheStore::GROUP );

		$this->assertTrue( $this->store->flush_all() );
		$this->assertFalse( wp_cache_get( 'k', CacheStore::GROUP ) );

		$GLOBALS['bfag_test_cache_supports'] = [];
		$this->assertFalse( $this->store->flush_all(), 'no group flush and no generation: nothing purged' );
	}

	public function test_bump_tags_reports_a_refused_write_and_succeeds_when_nothing_changes(): void {
		$this->refuse( CacheStore::TAG_GENERATIONS_OPTION );
		$this->assertFalse( $this->store->bump_tags( [ 'posts' ] ) );
		$this->assertSame( 1, $this->store->tag_generation( 'posts' ) );
		$this->assertTrue( $this->store->bump_tags( [] ), 'nothing to write is not a failure' );

		$this->refuse();
		$this->assertTrue( $this->store->bump_tags( [ 'posts' ] ) );
		$this->assertSame( 2, $this->store->tag_generation( 'posts' ) );
	}

	public function test_the_purger_returns_false_and_fires_nothing_when_the_store_refuses(): void {
		$this->record_purges();
		$this->refuse( CacheStore::GENERATION_OPTION, CacheStore::TAG_GENERATIONS_OPTION );
		$this->assertFalse( $this->purger()->purge_all() );
		$this->assertFalse( $this->purger()->purge_tags( [ 'all' ] ) );
		$this->assertSame( [], $this->purges );

		$this->refuse();
		$this->assertTrue( $this->purger()->purge_all() );
		$this->assertSame( [ 'all' ], $this->purger()->purge_tags( [ 'all' ] ) );
		$this->assertSame( [ [ 'all', [] ], [ 'tags', [ 'all' ] ] ], $this->purges );
	}

	public function test_the_cache_tab_purge_says_not_purged(): void {
		$this->refuse( CacheStore::GENERATION_OPTION );
		bfag_test_admin_request( 'purge_cache' );
		$exit = bfag_test_catch_exit( [ new CachePage( new CacheRuleRepository(), $this->purger(), new ProUpsell(), new Settings() ), 'handle_actions' ] );
		$this->assertStringEndsWith( 'bfag_notice=cache_purge_failed', (string) $exit->payload );

		$this->refuse();
		bfag_test_admin_request( 'purge_cache' );
		$exit = bfag_test_catch_exit( [ new CachePage( new CacheRuleRepository(), $this->purger(), new ProUpsell(), new Settings() ), 'handle_actions' ] );
		$this->assertStringEndsWith( 'bfag_notice=cache_purged', (string) $exit->payload );
	}

	public function test_the_ajax_purge_answers_500(): void {
		$this->refuse( CacheStore::GENERATION_OPTION, CacheStore::TAG_GENERATIONS_OPTION );
		foreach ( [ [], [ 'tags' => [ 'all' ] ] ] as $fields ) {
			bfag_test_ajax_request( 'purge_cache', $fields );
			$exit = bfag_test_catch_exit( [ new AdminMenu( ...$this->menu_args() ), 'ajax_purge_cache' ] );
			$this->assertSame( [ 'json_error', 500 ], [ $exit->kind, $exit->status ], (string) wp_json_encode( $fields ) );
		}

		$this->refuse();
		bfag_test_ajax_request( 'purge_cache' );
		$exit = bfag_test_catch_exit( [ new AdminMenu( ...$this->menu_args() ), 'ajax_purge_cache' ] );
		$this->assertSame( 'json_success', $exit->kind );
	}

	public function test_the_rest_purge_answers_500_never_a_200_that_says_purged(): void {
		$this->refuse( CacheStore::GENERATION_OPTION, CacheStore::TAG_GENERATIONS_OPTION );
		foreach ( [ [], [ 'tags' => [ 'all' ] ] ] as $params ) {
			$request = new \WP_REST_Request( 'POST', '/bfag/v1/cache/purge' );
			$request->set_body_params( $params );
			$response = ( new CacheController( $this->purger() ) )->purge( $request );
			$this->assertSame( 500, $response->get_status() );
			$this->assertSame( 'bfag_purge_failed', $response->get_data()['code'] );
		}

		$this->refuse();
		$response = ( new CacheController( $this->purger() ) )->purge( new \WP_REST_Request( 'POST', '/bfag/v1/cache/purge' ) );
		$this->assertSame( 200, $response->get_status() );
	}

	// ------------------------------------------------------------------
	// Webhook event list (P7's option)
	// ------------------------------------------------------------------

	public function test_a_refused_event_list_write_drops_the_option_so_the_next_boot_rebuilds_it(): void {
		add_option( WebhookRepository::EVENTS_OPTION, [ 'post.updated' ], '', true );
		$this->refuse( WebhookRepository::EVENTS_OPTION );
		$this->wpdb->queue( 'get_col', [ 'post.published' ] );
		( new WebhookRepository() )->invalidate();

		$this->assertFalse( get_option( WebhookRepository::EVENTS_OPTION ), 'the stale list is gone' );
		$this->assertStringContainsString( 'webhook event list not stored', implode( "\n", Logger::lines() ) );

		$this->refuse();
		$this->wpdb->queue( 'get_results', [ [ 'id' => 1, 'event' => 'post.published', 'enabled' => 1 ] ] );
		$this->assertSame( [ 'post.published' ], ( new WebhookRepository() )->enabled_events(), 'rebuilt from the table' );
	}

	/**
	 * @return list<object>
	 */
	private function menu_args(): array {
		$settings   = new Settings();
		$deliveries = new DeliveryRepository( $settings );
		return [
			$settings,
			$this->purger(),
			new CacheRuleRepository(),
			new RateLimitRuleRepository(),
			new WebhookRepository(),
			$deliveries,
			new WebhookDispatcher( $deliveries, $settings ),
			new EventRegistry(),
			new KpiCron( new CacheStatsRepository(), $deliveries, new HitCounter() ),
		];
	}
}
