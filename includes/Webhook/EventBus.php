<?php
/**
 * Connects WordPress hooks to webhook deliveries, lazily and once per request.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Webhook;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BlueFairy\ApiGateway\Logger;
use BlueFairy\ApiGateway\Plugin;

/**
 * A passive registry (review finding A3): a WordPress action is attached only
 * for an event that has an enabled webhook, so a site with no webhooks pays
 * nothing on `save_post`. Handling only accumulates — a bulk import of 500
 * posts collects 500 payloads, deduped per object, and a single `shutdown`
 * flush delivers them after the response has been sent.
 *
 * The flush sends at most FLUSH_LIMIT deliveries itself (review finding P6);
 * the rest become `pending` rows with `next_retry_at` = now, and one single
 * cron event (`bfag_cron_flush_pending`) sends them in batches of
 * FLUSH_LIMIT. Under WP-CLI or WP-Cron, where there is no response to wait
 * for, the queue is flushed as soon as it exceeds FLUSH_LIMIT (F9) so a long
 * import does not hold every payload in memory.
 *
 * `Plugin::$dispatching` (review finding A5) is set for the whole flush: a
 * `save_post` fired by a receiver writing back during delivery is ignored
 * rather than queued, and the flag is always restored in `finally`.
 */
final class EventBus {

	/**
	 * Most deliveries one flush sends synchronously; the rest are queued.
	 */
	public const FLUSH_LIMIT = 20;

	/**
	 * The single cron event that sends queued deliveries.
	 */
	public const HOOK = 'bfag_cron_flush_pending';

	/**
	 * Deliveries collected this request, keyed by event + object id.
	 *
	 * @var array<string, array{0:string, 1:array<string, mixed>}>
	 */
	private array $pending = [];

	/**
	 * Constructor.
	 *
	 * @param WebhookRepository  $webhooks   Subscriptions.
	 * @param WebhookDispatcher  $dispatcher Sender.
	 * @param EventRegistry      $registry   Event catalogue.
	 * @param DeliveryRepository $deliveries Delivery log, for the rows queued past the flush limit.
	 */
	public function __construct(
		private readonly WebhookRepository $webhooks,
		private readonly WebhookDispatcher $dispatcher,
		private readonly EventRegistry $registry,
		private readonly DeliveryRepository $deliveries
	) {}

	/**
	 * Attach one WordPress action per event that has an enabled webhook,
	 * the shutdown flush, and the pending-flush cron handler.
	 */
	public function register_hooks(): void {
		$events = $this->registry->all();
		foreach ( $this->webhooks->enabled_events() as $event ) {
			if ( ! isset( $events[ $event ] ) ) {
				continue;
			}
			$spec = $events[ $event ];
			add_action(
				$spec['hook'],
				function ( mixed ...$args ) use ( $event ): void {
					$this->handle( $event, $args );
				},
				10,
				$spec['accepted_args']
			);
		}
		add_action( 'shutdown', [ $this, 'flush' ], 1 );
		add_action( self::HOOK, [ $this, 'flush_pending' ] );
	}

	/**
	 * Queue an event for delivery at flush time. In a background process
	 * (WP-CLI, WP-Cron) a queue that has grown past FLUSH_LIMIT is flushed
	 * at once.
	 *
	 * @param string       $event     Event key.
	 * @param array<mixed> $hook_args The hook's arguments.
	 */
	public function handle( string $event, array $hook_args ): void {
		if ( Plugin::$dispatching ) {
			return;
		}
		$payload = $this->registry->payload( $event, $hook_args );
		if ( null === $payload ) {
			return;
		}
		$this->pending[ $this->key( $event, $payload ) ] = [ $event, $payload ];
		if ( count( $this->pending ) > self::FLUSH_LIMIT && $this->is_background() ) {
			$this->flush();
		}
	}

	/**
	 * Deliver everything queued, under the re-entrancy guard: the first
	 * FLUSH_LIMIT deliveries now, the rest as pending rows for the cron.
	 */
	public function flush(): void {
		if ( [] === $this->pending ) {
			return;
		}
		$this->finish_response();
		Plugin::$dispatching = true;
		try {
			$items         = array_values( $this->pending );
			$this->pending = [];
			$sent          = 0;
			$queued        = 0;
			foreach ( $items as [ $event, $payload ] ) {
				foreach ( $this->webhooks->for_event( $event ) as $webhook ) {
					if ( $sent < self::FLUSH_LIMIT ) {
						$this->dispatcher->dispatch( $webhook, $event, $payload );
						++$sent;
						continue;
					}
					$this->queue( $webhook, $event, $payload );
					++$queued;
				}
			}
			if ( $queued > 0 ) {
				$this->schedule_flush();
				Logger::log(
					'webhook deliveries queued past the flush limit',
					[
						'sent'   => $sent,
						'queued' => $queued,
					]
				);
			}
		} finally {
			Plugin::$dispatching = false;
			$this->pending       = [];
		}
	}

	/**
	 * Cron handler: send up to FLUSH_LIMIT queued deliveries; when the batch
	 * was full, schedule another run for whatever is left.
	 */
	public function flush_pending(): void {
		$rows = $this->deliveries->pending( self::FLUSH_LIMIT );
		if ( [] === $rows ) {
			return;
		}
		Plugin::$dispatching = true;
		try {
			foreach ( $rows as $row ) {
				$this->dispatcher->dispatch_pending( $row );
			}
		} finally {
			Plugin::$dispatching = false;
		}
		if ( count( $rows ) >= self::FLUSH_LIMIT ) {
			$this->schedule_flush();
		}
	}

	/**
	 * What is queued (tests).
	 *
	 * @return list<array{0:string, 1:array<string, mixed>}>
	 */
	public function pending(): array {
		return array_values( $this->pending );
	}

	/**
	 * Drop the object-cached list of enabled webhooks.
	 */
	public function invalidate(): void {
		wp_cache_delete( WebhookRepository::CACHE_KEY, WebhookRepository::CACHE_GROUP );
	}

	/**
	 * Write one delivery as a `pending` row due now, for the cron to send.
	 *
	 * @param array<string, mixed> $webhook The webhook row.
	 * @param string               $event   Event key.
	 * @param array<string, mixed> $payload The payload.
	 */
	private function queue( array $webhook, string $event, array $payload ): void {
		$body = wp_json_encode( $payload );
		$id   = $this->deliveries->create(
			[
				'webhook_id'    => (int) ( $webhook['id'] ?? 0 ),
				'direction'     => 'out',
				'event'         => $event,
				'payload'       => is_string( $body ) ? $body : '{}',
				'status'        => 'pending',
				'attempt'       => 1,
				'next_retry_at' => gmdate( 'Y-m-d H:i:s' ),
			]
		);
		if ( false === $id ) {
			Logger::log( 'webhook delivery could not be queued', [ 'event' => $event ] );
		}
	}

	/**
	 * Schedule the pending-flush event once: a second call while one is
	 * already due is a no-op.
	 */
	private function schedule_flush(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time(), self::HOOK );
		}
	}

	/**
	 * Is this a process with no HTTP response waiting on us?
	 */
	private function is_background(): bool {
		return ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) || wp_doing_cron();
	}

	/**
	 * Hand the finished response to the web server before sending anything,
	 * where PHP-FPM allows it, so the deliveries never add to the page's
	 * response time. Not from the command line, where there is no response.
	 */
	private function finish_response(): void {
		/**
		 * Whether to end the HTTP response (fastcgi_finish_request) before
		 * webhook deliveries are sent on shutdown. Default: whenever this
		 * is not a CLI process. A plugin that prints on `shutdown` after
		 * this one may return false here.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $finish Default true unless PHP_SAPI is 'cli'.
		 */
		$finish = apply_filters( 'bfag_webhook_finish_response', 'cli' !== PHP_SAPI );
		if ( true === $finish && function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
	}

	/**
	 * Dedupe key: the object's id when the payload has one, otherwise a hash
	 * of the object so two identical payloads still collapse to one.
	 *
	 * @param string               $event   Event key.
	 * @param array<string, mixed> $payload The payload.
	 */
	private function key( string $event, array $payload ): string {
		$object = $payload['object'] ?? null;
		if ( is_array( $object ) && isset( $object['id'] ) && is_scalar( $object['id'] ) ) {
			return $event . ':' . (string) $object['id'];
		}
		return $event . ':#' . md5( (string) wp_json_encode( $object ) );
	}
}
