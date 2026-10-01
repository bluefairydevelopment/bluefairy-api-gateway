<?php
/**
 * Connects WordPress hooks to webhook deliveries, lazily and once per request.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Webhook;

use BlueFairy\ApiGateway\Plugin;

/**
 * A passive registry (review finding A3): a WordPress action is attached only
 * for an event that has an enabled webhook, so a site with no webhooks pays
 * nothing on `save_post`. Handling only accumulates — a bulk import of 500
 * posts collects 500 payloads, deduped per object, and a single `shutdown`
 * flush delivers them after the response has been sent.
 *
 * `Plugin::$dispatching` (review finding A5) is set for the whole flush: a
 * `save_post` fired by a receiver writing back during delivery is ignored
 * rather than queued, and the flag is always restored in `finally`.
 */
final class EventBus {

	/**
	 * Deliveries collected this request, keyed by event + object id.
	 *
	 * @var array<string, array{0:string, 1:array<string, mixed>}>
	 */
	private array $pending = [];

	/**
	 * Constructor.
	 *
	 * @param WebhookRepository $webhooks   Subscriptions.
	 * @param WebhookDispatcher $dispatcher Sender.
	 * @param EventRegistry     $registry   Event catalogue.
	 */
	public function __construct(
		private readonly WebhookRepository $webhooks,
		private readonly WebhookDispatcher $dispatcher,
		private readonly EventRegistry $registry
	) {}

	/**
	 * Attach one WordPress action per event that has an enabled webhook, and
	 * the shutdown flush.
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
	}

	/**
	 * Queue an event for delivery at flush time.
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
	}

	/**
	 * Deliver everything queued, under the re-entrancy guard.
	 */
	public function flush(): void {
		if ( [] === $this->pending ) {
			return;
		}
		Plugin::$dispatching = true;
		try {
			$items         = array_values( $this->pending );
			$this->pending = [];
			foreach ( $items as [ $event, $payload ] ) {
				foreach ( $this->webhooks->for_event( $event ) as $webhook ) {
					$this->dispatcher->dispatch( $webhook, $event, $payload );
				}
			}
		} finally {
			Plugin::$dispatching = false;
			$this->pending       = [];
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
