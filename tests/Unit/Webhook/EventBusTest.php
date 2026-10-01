<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Webhook;

use BlueFairy\ApiGateway\Plugin;
use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Tests\TestCase;
use BlueFairy\ApiGateway\Webhook\DeliveryRepository;
use BlueFairy\ApiGateway\Webhook\EventBus;
use BlueFairy\ApiGateway\Webhook\EventRegistry;
use BlueFairy\ApiGateway\Webhook\WebhookDispatcher;
use BlueFairy\ApiGateway\Webhook\WebhookRepository;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class EventBusTest extends TestCase {

	/**
	 * Mid-test reset: bfag_test_reset() swaps $GLOBALS['wpdb'], so re-bind ours.
	 */
	private function reset(): void {
		bfag_test_reset();
		$this->wpdb = $GLOBALS['wpdb'];
	}

	private function bus(): EventBus {
		$settings = new Settings();
		$deliveries = new DeliveryRepository( $settings );
		return new EventBus( new WebhookRepository(), new WebhookDispatcher( $deliveries, $settings ), new EventRegistry(), $deliveries );
	}

	/**
	 * Seed the object cache the repository reads, so all_enabled() needs no SQL.
	 *
	 * @param list<array<string, mixed>> $webhooks Partial rows.
	 */
	private function seed( array $webhooks ): void {
		$rows = [];
		foreach ( $webhooks as $i => $w ) {
			$rows[] = array_merge(
				[
					'id'          => $i + 1,
					'label'       => 'W' . ( $i + 1 ),
					'url'         => 'https://hooks.example.com/' . ( $i + 1 ),
					'event'       => 'post.published',
					'secret'      => 's',
					'headers'     => '{}',
					'preset'      => '',
					'enabled'     => 1,
					'max_retries' => 3,
				],
				$w
			);
		}
		wp_cache_set( WebhookRepository::CACHE_KEY, $rows, WebhookRepository::CACHE_GROUP );
	}

	private function publish( int $id, string $old = 'draft' ): void {
		$post = bfag_test_add_post( [ 'ID' => $id, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'P' . $id ] );
		do_action( 'transition_post_status', 'publish', $old, $post );
	}

	public function test_register_hooks_attaches_only_events_with_an_enabled_webhook(): void {
		$this->seed( [] );
		$this->bus()->register_hooks();
		$this->assertFalse( has_action( 'transition_post_status' ), 'no webhooks: no WP action' );
		$this->assertFalse( has_action( 'post_updated' ) );
		$this->assertNotFalse( has_action( 'shutdown' ) );

		$this->reset();
		$this->seed( [ [ 'event' => 'post.published' ], [ 'event' => 'post.published' ] ] );
		$this->bus()->register_hooks();
		$this->assertNotFalse( has_action( 'transition_post_status' ) );
		$this->assertFalse( has_action( 'post_updated' ), 'an event nobody subscribes to is not hooked' );
		$this->assertCount( 1, $GLOBALS['bfag_test_hooks']['transition_post_status'][10], 'one action per event, not per webhook' );
	}

	public function test_register_hooks_ignores_unknown_and_disabled_events(): void {
		$this->seed( [ [ 'event' => 'nope.unknown' ], [ 'event' => 'post.updated', 'enabled' => 0 ] ] );
		// A disabled row should never be in the enabled cache; the repository filters at SQL level. Simulate the real list.
		wp_cache_set( WebhookRepository::CACHE_KEY, [ [ 'id' => 1, 'event' => 'nope.unknown', 'enabled' => 1, 'url' => 'https://x.example.com', 'secret' => '', 'headers' => '{}' ] ], WebhookRepository::CACHE_GROUP );
		$this->bus()->register_hooks();
		$this->assertSame( [ 'shutdown', EventBus::HOOK ], array_keys( $GLOBALS['bfag_test_hooks'] ), 'only the flush and the pending cron, no event hook' );
	}

	public function test_handle_accumulates_and_nothing_is_sent_until_flush(): void {
		$this->seed( [ [ 'event' => 'post.published' ] ] );
		$bus = $this->bus();
		$bus->register_hooks();

		$this->publish( 10 );
		$this->assertCount( 1, $bus->pending() );
		$this->assertSame( 'post.published', $bus->pending()[0][0] );
		$this->assertSame( 10, $bus->pending()[0][1]['object']['id'] );
		$this->assertCount( 0, $GLOBALS['bfag_test_http_calls'], 'nothing sent on handle' );
		$this->assertCount( 0, $this->wpdb->calls_to( 'insert' ) );

		$bus->flush();
		$this->assertCount( 1, $GLOBALS['bfag_test_http_calls'] );
		$this->assertCount( 1, $this->wpdb->calls_to( 'insert' ) );
		$this->assertSame( 'post.published', $GLOBALS['bfag_test_http_calls'][0]['args']['headers']['X-BF-Event'] );
		$this->assertSame( [], $bus->pending() );

		$bus->flush();
		$this->assertCount( 1, $GLOBALS['bfag_test_http_calls'], 'a second flush with nothing pending sends nothing' );
	}

	public function test_handle_drops_events_whose_payload_is_null(): void {
		$this->seed( [ [ 'event' => 'post.published' ] ] );
		$bus = $this->bus();
		$bus->register_hooks();
		$this->publish( 10, 'publish' );
		$this->assertSame( [], $bus->pending(), 'publish→publish is not a publish' );
		$this->publish( 10, 'draft' );
		$this->assertCount( 1, $bus->pending() );
	}

	public function test_identical_events_for_one_object_dedupe_to_one_delivery(): void {
		$this->seed( [ [ 'event' => 'post.published' ] ] );
		$bus = $this->bus();
		$bus->register_hooks();
		$this->publish( 10 );
		$this->publish( 10 );
		$this->assertCount( 1, $bus->pending() );
		$this->publish( 11 );
		$this->assertCount( 2, $bus->pending(), 'a different object is a different delivery' );
		$bus->flush();
		$this->assertCount( 2, $this->wpdb->calls_to( 'insert' ) );
	}

	public function test_flush_delivers_to_every_webhook_subscribed_to_the_event(): void {
		$this->seed( [ [ 'event' => 'post.published' ], [ 'event' => 'post.published' ], [ 'event' => 'post.updated' ] ] );
		$bus = $this->bus();
		$bus->register_hooks();
		$this->publish( 10 );
		$bus->flush();
		$inserts = $this->wpdb->calls_to( 'insert' );
		$this->assertCount( 2, $inserts );
		$this->assertSame( [ 1, 2 ], array_column( array_column( $inserts, 'data' ), 'webhook_id' ) );
		$this->assertSame( [ 'https://hooks.example.com/1', 'https://hooks.example.com/2' ], array_column( $GLOBALS['bfag_test_http_calls'], 'url' ) );
	}

	public function test_reentrant_hook_during_delivery_creates_no_second_delivery(): void {
		$this->seed( [ [ 'event' => 'post.published' ] ] );
		$bus = $this->bus();
		$bus->register_hooks();

		$observed = [];
		add_filter(
			'bfag_webhook_request_args',
			function ( array $args ) use ( $bus, &$observed ): array {
				// A receiver writing back to WordPress mid-delivery: the publish hook fires again.
				$this->publish( 99 );
				$observed['guard']   = Plugin::$dispatching;
				$observed['pending'] = count( $bus->pending() );
				return $args;
			}
		);

		$this->publish( 10 );
		$bus->flush();

		$this->assertSame( [ 'guard' => true, 'pending' => 0 ], $observed, 'the re-entrant event was dropped, not queued' );
		$this->assertCount( 1, $this->wpdb->calls_to( 'insert' ), 'exactly one delivery row' );
		$this->assertCount( 1, $GLOBALS['bfag_test_http_calls'] );
		$this->assertSame( [], $bus->pending() );
		$this->assertFalse( Plugin::$dispatching );

		$bus->flush();
		$this->assertCount( 1, $this->wpdb->calls_to( 'insert' ), 'still one after a second flush' );

		// With the guard down the same hook IS queued — the control that shows the assertion above can fail.
		$this->publish( 100 );
		$this->assertCount( 1, $bus->pending() );
	}

	public function test_handle_short_circuits_while_dispatching(): void {
		$bus  = $this->bus();
		$post = bfag_test_add_post( [ 'ID' => 3, 'post_type' => 'post', 'post_status' => 'publish' ] );
		Plugin::$dispatching = true;
		$bus->handle( 'post.published', [ 'publish', 'draft', $post ] );
		$this->assertSame( [], $bus->pending() );
		Plugin::$dispatching = false;
		$bus->handle( 'post.published', [ 'publish', 'draft', $post ] );
		$this->assertCount( 1, $bus->pending() );
	}

	public function test_flush_restores_the_guard_and_clears_pending_when_a_dispatch_throws(): void {
		$this->seed( [ [ 'event' => 'post.published' ] ] );
		$bus = $this->bus();
		$bus->register_hooks();
		add_filter( 'bfag_webhook_request_args', static function (): array {
			throw new \RuntimeException( 'transport exploded' );
		} );
		$this->publish( 10 );
		try {
			$bus->flush();
			self::fail( 'exception expected' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'transport exploded', $e->getMessage() );
		}
		$this->assertFalse( Plugin::$dispatching );
		$this->assertSame( [], $bus->pending() );
	}

	public function test_shutdown_flushes(): void {
		$this->seed( [ [ 'event' => 'post.published' ] ] );
		$bus = $this->bus();
		$bus->register_hooks();
		$this->publish( 10 );
		do_action( 'shutdown' );
		$this->assertCount( 1, $GLOBALS['bfag_test_http_calls'] );
		$this->assertSame( [], $bus->pending() );
	}

	// ------------------------------------------------------------------
	// P6: the flush limit, the pending cron, F9 background flushing
	// ------------------------------------------------------------------

	/**
	 * One subscribed webhook, 25 published posts: 20 are sent on flush, 5
	 * are written as pending rows due now, and exactly one single event is
	 * scheduled however many times flush() runs.
	 */
	public function test_flush_sends_twenty_and_queues_the_rest_for_one_single_cron_event(): void {
		$this->assertSame( 20, EventBus::FLUSH_LIMIT );
		$this->seed( [ [ 'event' => 'post.published' ] ] );
		$bus = $this->bus();
		$bus->register_hooks();
		for ( $id = 1; $id <= 25; $id++ ) {
			$this->publish( $id );
		}
		$this->assertCount( 25, $bus->pending() );
		$this->assertSame( [], $GLOBALS['bfag_test_single_events'], 'nothing scheduled before the flush' );

		$bus->flush();

		$this->assertCount( 20, $GLOBALS['bfag_test_http_calls'], 'twenty sent now' );
		$inserts = $this->wpdb->calls_to( 'insert' );
		$this->assertCount( 25, $inserts, 'twenty in-flight rows plus five queued rows' );
		$queued = array_slice( $inserts, 20 );
		foreach ( $queued as $i => $insert ) {
			$this->assertSame( 'pending', $insert['data']['status'], "queued row {$i}" );
			$this->assertSame( 'out', $insert['data']['direction'] );
			$this->assertSame( 1, $insert['data']['attempt'] );
			$this->assertSame( 1, $insert['data']['webhook_id'] );
			$this->assertSame( 'post.published', $insert['data']['event'] );
			$this->assertSame( 21 + $i, json_decode( (string) $insert['data']['payload'], true )['object']['id'] ?? null, 'the stored payload is the event payload' );
			$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string) $insert['data']['next_retry_at'], 'a GMT datetime, not null (strtotime( null . " UTC" ) would be "now")' );
			$this->assertEqualsWithDelta( time(), strtotime( $insert['data']['next_retry_at'] . ' UTC' ), 5, 'due now' );
		}
		foreach ( array_slice( $inserts, 0, 20 ) as $insert ) {
			$this->assertNull( $insert['data']['next_retry_at'], 'an in-flight row carries no due time' );
		}
		$this->assertCount( 1, $GLOBALS['bfag_test_single_events'] );
		$this->assertSame( 'bfag_cron_flush_pending', $GLOBALS['bfag_test_single_events'][0]['hook'] );
		$this->assertEqualsWithDelta( time(), $GLOBALS['bfag_test_single_events'][0]['timestamp'], 5 );
		$this->assertContains( EventBus::HOOK, \BlueFairy\ApiGateway\Installer::SINGLE_EVENTS, 'so deactivation and uninstall clear it' );

		// A second over-limit flush while the event is still due does not schedule a second one.
		for ( $id = 26; $id <= 47; $id++ ) {
			$this->publish( $id );
		}
		$bus->flush();
		$this->assertCount( 1, $GLOBALS['bfag_test_single_events'], 'deduped on wp_next_scheduled()' );
		$this->assertCount( 40, $GLOBALS['bfag_test_http_calls'] );
	}

	public function test_a_flush_within_the_limit_queues_nothing_and_schedules_nothing(): void {
		$this->seed( [ [ 'event' => 'post.published' ] ] );
		$bus = $this->bus();
		$bus->register_hooks();
		for ( $id = 1; $id <= 20; $id++ ) {
			$this->publish( $id );
		}
		$bus->flush();
		$this->assertCount( 20, $GLOBALS['bfag_test_http_calls'] );
		$this->assertCount( 20, $this->wpdb->calls_to( 'insert' ) );
		$this->assertSame( [], $GLOBALS['bfag_test_single_events'] );
	}

	/**
	 * The cron handler sends the queued rows through the dispatcher — the
	 * stored payload, as attempt 1 — and re-arms itself only when its batch
	 * was full.
	 */
	public function test_the_cron_handler_sends_pending_rows_and_rearms_when_the_batch_was_full(): void {
		$this->seed( [ [ 'event' => 'post.published' ] ] );
		$bus = $this->bus();
		$bus->register_hooks();
		$this->assertNotFalse( has_action( EventBus::HOOK ), 'bound to the single event' );
		$rows = [];
		for ( $id = 101; $id <= 105; $id++ ) {
			$rows[] = [ 'id' => $id, 'webhook_id' => 1, 'direction' => 'out', 'event' => 'post.published', 'payload' => '{"object":{"id":' . $id . '}}', 'truncated' => '0', 'status' => 'pending', 'attempt' => '1', 'next_retry_at' => '2026-01-01 00:00:00' ];
		}
		$this->wpdb->queue( 'get_results', $rows );
		$this->wpdb->always( 'get_row', $this->seeded_row() );
		$this->wpdb->always( 'update', 1 );

		do_action( EventBus::HOOK );

		$this->assertCount( 5, $GLOBALS['bfag_test_http_calls'] );
		$this->assertSame( '{"object":{"id":103}}', $GLOBALS['bfag_test_http_calls'][2]['args']['body'], 'the stored bytes' );
		$this->assertSame( '103', $GLOBALS['bfag_test_http_calls'][2]['args']['headers']['X-BF-Delivery'] );
		$select = $this->wpdb->calls_to( 'get_results' )[0]['sql'];
		$this->assertStringContainsString( "WHERE status = 'pending' AND direction = 'out' AND next_retry_at IS NOT NULL AND next_retry_at <= '", $select );
		$this->assertStringEndsWith( 'ORDER BY id ASC LIMIT 20', $select );
		$this->assertCount( 0, $this->wpdb->calls_to( 'insert' ), 'rows are reused, never re-inserted' );
		$this->assertSame( [], $GLOBALS['bfag_test_single_events'], 'five of twenty: nothing left, no re-arm' );
		$this->assertFalse( Plugin::$dispatching );

		// A full batch re-arms the event for the remainder.
		$this->reset();
		$this->seed( [ [ 'event' => 'post.published' ] ] );
		$full = [];
		for ( $id = 1; $id <= 20; $id++ ) {
			$full[] = [ 'id' => $id, 'webhook_id' => 1, 'direction' => 'out', 'event' => 'post.published', 'payload' => '{}', 'truncated' => '0', 'status' => 'pending', 'attempt' => '1' ];
		}
		$this->wpdb->queue( 'get_results', $full );
		$this->wpdb->always( 'get_row', $this->seeded_row() );
		$this->bus()->flush_pending();
		$this->assertCount( 20, $GLOBALS['bfag_test_http_calls'] );
		$this->assertCount( 1, $GLOBALS['bfag_test_single_events'], 're-armed' );

		// Nothing pending: no dispatch, no re-arm.
		$this->reset();
		$this->bus()->flush_pending();
		$this->assertSame( [], $GLOBALS['bfag_test_http_calls'] );
		$this->assertSame( [], $GLOBALS['bfag_test_single_events'] );
	}

	/**
	 * F9: under WP-CLI there is no response to wait for, so the 21st
	 * handle() flushes the queue instead of holding it in memory.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_under_wp_cli_the_twenty_first_handle_flushes(): void {
		define( 'WP_CLI', true );
		$this->seed( [ [ 'event' => 'post.published' ] ] );
		$bus = $this->bus();
		$bus->register_hooks();
		for ( $id = 1; $id <= 20; $id++ ) {
			$this->publish( $id );
		}
		$this->assertCount( 20, $bus->pending(), 'twenty are held' );
		$this->assertCount( 0, $GLOBALS['bfag_test_http_calls'] );

		$this->publish( 21 );

		$this->assertSame( [], $bus->pending(), 'the 21st flushed' );
		$this->assertCount( 20, $GLOBALS['bfag_test_http_calls'], 'twenty sent now' );
		$this->assertCount( 21, $this->wpdb->calls_to( 'insert' ), 'the 21st is queued for the cron' );
		$this->assertCount( 1, $GLOBALS['bfag_test_single_events'] );
	}

	public function test_a_cron_process_flushes_at_the_limit_but_a_web_request_holds_everything_for_shutdown(): void {
		$this->seed( [ [ 'event' => 'post.published' ] ] );
		$bus = $this->bus();
		$bus->register_hooks();
		for ( $id = 1; $id <= 21; $id++ ) {
			$this->publish( $id );
		}
		$this->assertCount( 21, $bus->pending(), 'a web request waits for shutdown' );
		$this->assertCount( 0, $GLOBALS['bfag_test_http_calls'] );

		$GLOBALS['bfag_test_doing_cron'] = true;
		$this->publish( 22 );
		$this->assertSame( [], $bus->pending(), 'under WP-Cron the queue past the limit is flushed at once' );
		$this->assertCount( 20, $GLOBALS['bfag_test_http_calls'] );
	}

	/**
	 * The response is handed to the web server before anything is sent,
	 * where PHP-FPM allows it. Under this CLI test runner the default is
	 * off; the filter switches it on.
	 */
	public function test_flush_finishes_the_response_first_unless_cli_or_the_filter_says_not_to(): void {
		$GLOBALS['bfag_test_finished'] = [];
		$this->seed( [ [ 'event' => 'post.published' ] ] );
		$bus = $this->bus();
		$bus->register_hooks();
		$this->publish( 1 );
		$bus->flush();
		$this->assertSame( [], $GLOBALS['bfag_test_finished'], 'PHP_SAPI is cli here: never finished' );

		add_filter( 'bfag_webhook_finish_response', '__return_true' );
		$this->publish( 2 );
		$bus->flush();
		$this->assertSame( [ 1 ], $GLOBALS['bfag_test_finished'], 'finished once, when one request (the first flush) had been sent and the second not yet' );
		$this->assertCount( 2, $GLOBALS['bfag_test_http_calls'] );

		$bus->flush();
		$this->assertCount( 1, $GLOBALS['bfag_test_finished'], 'an empty flush finishes nothing' );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function seeded_row(): array {
		return [ 'id' => 1, 'label' => 'W1', 'url' => 'https://hooks.example.com/1', 'event' => 'post.published', 'secret' => 's', 'headers' => '{}', 'preset' => '', 'enabled' => '1', 'max_retries' => '3' ];
	}

	/**
	 * F16: invalidate() was dead (the repository invalidates on every write) and is gone.
	 */
	public function test_invalidate_is_no_longer_part_of_the_api(): void {
		$this->assertFalse( method_exists( $this->bus(), 'invalidate' ) );
		$this->assertTrue( method_exists( $this->bus(), 'flush' ), 'control' );
	}
}
