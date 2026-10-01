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
		return new EventBus( new WebhookRepository(), new WebhookDispatcher( new DeliveryRepository( $settings ), $settings ), new EventRegistry() );
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
		$this->assertSame( [ 'shutdown' ], array_keys( $GLOBALS['bfag_test_hooks'] ) );
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

	public function test_invalidate_clears_the_enabled_webhooks_cache(): void {
		$this->seed( [ [ 'event' => 'post.published' ] ] );
		$this->assertNotFalse( wp_cache_get( 'webhooks_enabled', 'bfag' ) );
		$this->bus()->invalidate();
		$this->assertFalse( wp_cache_get( 'webhooks_enabled', 'bfag' ) );
	}
}
