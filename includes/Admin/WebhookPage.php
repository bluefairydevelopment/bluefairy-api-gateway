<?php
/**
 * The Webhooks tab.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BlueFairy\ApiGateway\Webhook\DeliveryRepository;
use BlueFairy\ApiGateway\Webhook\EventRegistry;
use BlueFairy\ApiGateway\Webhook\WebhookDispatcher;
use BlueFairy\ApiGateway\Webhook\WebhookRepository;

/**
 * Webhook list, add/edit form, Send test, and the delivery log sub-view
 * (`?tab=webhooks&view=deliveries[&webhook_id=]`).
 *
 * The secret is never rendered: the form field is blank and blank means
 * "keep". The event must exist in the registry — the repository stores any
 * sanitised key, so the check belongs here, in front of the only form that
 * can create one.
 */
final class WebhookPage extends Page {

	public const TAB = 'webhooks';

	public const DELIVERY_LIMIT = 50;

	/**
	 * Constructor.
	 *
	 * @param WebhookRepository  $webhooks   Webhooks.
	 * @param DeliveryRepository $deliveries Delivery log.
	 * @param WebhookDispatcher  $dispatcher Sends test payloads.
	 * @param EventRegistry      $registry   Known events.
	 * @param ProUpsell          $upsell     Pro state and copy.
	 */
	public function __construct(
		private readonly WebhookRepository $webhooks,
		private readonly DeliveryRepository $deliveries,
		private readonly WebhookDispatcher $dispatcher,
		private readonly EventRegistry $registry,
		private readonly ProUpsell $upsell
	) {}

	/**
	 * Tab id.
	 */
	public function tab(): string {
		return self::TAB;
	}

	/**
	 * Actions this tab handles.
	 *
	 * @return list<string>
	 */
	public function actions(): array {
		return [ 'save_webhook', 'delete_webhook', 'toggle_webhook', 'test_webhook' ];
	}

	/**
	 * Print the tab.
	 */
	public function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only: which sub-view and which row.
		$view       = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		$webhook_id = isset( $_GET['webhook_id'] ) ? absint( wp_unslash( $_GET['webhook_id'] ) ) : 0;
		$edit_id    = isset( $_GET['action'], $_GET['id'] ) && 'edit' === sanitize_key( wp_unslash( $_GET['action'] ) ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		// phpcs:enable

		if ( 'deliveries' === $view ) {
			$this->render_deliveries( $webhook_id );
			return;
		}

		$rows   = $this->webhooks->all();
		$count  = $this->webhooks->count();
		$limit  = $this->webhooks->limit();
		$status = [];
		foreach ( $rows as $row ) {
			$status[ (int) $row['id'] ] = $this->deliveries->last_status( (int) $row['id'] );
		}

		$this->view(
			'webhooks',
			[
				'webhooks'       => $rows,
				'count'          => $count,
				'limit'          => $limit,
				'last_status'    => $status,
				'events'         => $this->registry->all(),
				'page'           => $this,
				'deliveries_url' => $this->url( [ 'view' => 'deliveries' ] ),
			]
		);

		$editing = $edit_id > 0 ? $this->webhooks->find( $edit_id ) : null;
		if ( null === $editing && $count >= $limit ) {
			echo '<p class="bfag-limit-line">' . esc_html( $this->upsell->limit_line( sprintf( /* translators: %d: number of webhooks */ _n( '%d webhook', '%d webhooks', $limit, 'bluefairy-api-gateway' ), $limit ) ) ) . '</p>';
			return;
		}

		$webhook = $editing ?? [
			'id'          => 0,
			'label'       => '',
			'url'         => '',
			'event'       => '',
			'headers'     => '',
			'preset'      => '',
			'enabled'     => 1,
			'max_retries' => 3,
		];
		// The secret never reaches the view, even when editing.
		unset( $webhook['secret'] );

		/**
		 * Preset choices for the webhook form (`slug => label`). Empty in
		 * the free plugin; when non-empty the form shows a Preset select.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $presets Default [].
		 */
		$presets = apply_filters( 'bfag_webhook_presets', [] );

		$this->view(
			'webhook-form',
			[
				'webhook'    => $webhook,
				'events'     => $this->registry->all(),
				'presets'    => is_array( $presets ) ? $presets : [],
				'headers'    => $this->headers_to_lines( $this->webhooks->headers_of( $webhook ) ),
				'is_pro'     => $this->upsell->is_pro(),
				'form_url'   => $this->url(),
				'cancel_url' => $this->url(),
			]
		);
	}

	/**
	 * The delivery log.
	 *
	 * @param int $webhook_id Filter to one webhook, 0 for all.
	 */
	private function render_deliveries( int $webhook_id ): void {
		$filters = $webhook_id > 0 ? [ 'webhook_id' => $webhook_id ] : [];
		$labels  = [];
		foreach ( $this->webhooks->all() as $row ) {
			$labels[ (int) $row['id'] ] = (string) $row['label'];
		}
		$this->view(
			'deliveries',
			[
				'deliveries' => $this->deliveries->recent( self::DELIVERY_LIMIT, $filters ),
				'labels'     => $labels,
				'webhook_id' => $webhook_id,
				'back_url'   => $this->url(),
				'page'       => $this,
			]
		);
	}

	/**
	 * Dispatch a verified action.
	 *
	 * @param string               $action Action name.
	 * @param array<string, mixed> $fields Sanitised fields.
	 * @param array<string, mixed> $raw    Unslashed POST, for `bfag_webhook_sanitize`.
	 */
	protected function run_action( string $action, array $fields, array $raw ): void {
		$id = (int) ( $fields['id'] ?? 0 );

		switch ( $action ) {
			case 'save_webhook':
				$this->save( $id, $fields, $raw );
				// Unreachable: save() redirects.
			case 'delete_webhook':
				if ( $id <= 0 || null === $this->webhooks->find( $id ) ) {
					$this->redirect( 'webhook_not_found' );
				}
				$this->redirect( $this->webhooks->delete( $id ) ? 'webhook_deleted' : 'error' );
				// Unreachable.
			case 'toggle_webhook':
				if ( $id <= 0 || null === $this->webhooks->find( $id ) ) {
					$this->redirect( 'webhook_not_found' );
				}
				$this->redirect( $this->webhooks->set_enabled( $id, self::flag( $fields, 'enabled' ) ) ? 'webhook_toggled' : 'error' );
				// Unreachable.
			case 'test_webhook':
				$this->send_test( $id );
				// Unreachable: send_test() redirects.
			default:
				$this->redirect( 'error' );
		}
	}

	/**
	 * Create or update a webhook from the form, then redirect.
	 *
	 * @param int                  $id     Webhook id, 0 to create.
	 * @param array<string, mixed> $fields Sanitised fields.
	 * @param array<string, mixed> $raw    Unslashed POST.
	 */
	private function save( int $id, array $fields, array $raw ): never {
		// Same character class as WebhookRepository: sanitize_key() would strip the dots of `post.published`.
		$event = preg_replace( '/[^a-z0-9_.\-]/', '', strtolower( (string) ( $fields['event'] ?? '' ) ) ) ?? '';
		if ( '' === $event || ! $this->registry->exists( $event ) ) {
			$this->redirect( 'webhook_event_unknown' );
		}

		$data = [
			'label'   => (string) ( $fields['label'] ?? '' ),
			'url'     => esc_url_raw( (string) ( $fields['url'] ?? '' ) ),
			'event'   => $event,
			'headers' => $this->lines_to_headers( (string) ( $fields['headers'] ?? '' ) ),
			'preset'  => sanitize_key( (string) ( $fields['preset'] ?? '' ) ),
			'enabled' => self::flag( $fields, 'enabled' ),
		];
		if ( $this->upsell->is_pro() && array_key_exists( 'max_retries', $fields ) ) {
			$data['max_retries'] = (int) $fields['max_retries'];
		}

		$secret = trim( (string) ( $fields['secret'] ?? '' ) );
		if ( self::flag( $fields, 'regenerate_secret' ) ) {
			$data['secret'] = bin2hex( random_bytes( 32 ) );
		} elseif ( '' !== $secret ) {
			$data['secret'] = $secret;
		}
		// Blank and not regenerating: no `secret` key, so update() keeps the stored one and create() generates one.

		/**
		 * Sanitise add-on fields before a webhook is stored.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $data The columns about to be stored.
		 * @param array<string, mixed> $raw  The whole unslashed POST body.
		 */
		$filtered = apply_filters( 'bfag_webhook_sanitize', $data, $raw );
		$data     = is_array( $filtered ) ? $filtered : $data;

		if ( $id > 0 ) {
			if ( null === $this->webhooks->find( $id ) ) {
				$this->redirect( 'webhook_not_found' );
			}
			$this->redirect( $this->webhooks->update( $id, $data ) ? 'webhook_saved' : 'webhook_invalid' );
		}

		if ( $this->webhooks->count() >= $this->webhooks->limit() ) {
			$this->redirect( 'webhook_limit' );
		}
		$created = $this->webhooks->create( $data );
		$this->redirect( false === $created ? 'webhook_invalid' : 'webhook_saved' );
	}

	/**
	 * Deliver a `test` payload to one webhook, then redirect with the outcome.
	 *
	 * @param int $id Webhook id.
	 */
	private function send_test( int $id ): never {
		$webhook = $id > 0 ? $this->webhooks->find( $id ) : null;
		if ( null === $webhook ) {
			$this->redirect( 'webhook_not_found' );
		}
		$result = $this->dispatcher->dispatch(
			$webhook,
			'test',
			[
				'event'       => 'test',
				'site'        => home_url(),
				'occurred_at' => gmdate( 'c' ),
				'object'      => [
					'webhook_id' => (int) $webhook['id'],
					'label'      => (string) $webhook['label'],
				],
			]
		);
		$this->redirect( $result['success'] ? 'webhook_test_ok' : 'webhook_test_failed' );
	}

	/**
	 * `Name: value` lines → header map. Malformed lines are skipped; the
	 * dispatcher's sanitiser has the final say on names and values.
	 *
	 * @param string $lines Textarea content.
	 * @return array<string, string>
	 */
	public function lines_to_headers( string $lines ): array {
		$headers = [];
		$split   = preg_split( '/\R/', $lines );
		foreach ( false === $split ? [] : $split as $line ) {
			$line = trim( $line );
			if ( '' === $line || ! str_contains( $line, ':' ) ) {
				continue;
			}
			[ $name, $value ] = explode( ':', $line, 2 );
			$name             = trim( $name );
			$value            = trim( $value );
			if ( '' === $name || 1 !== preg_match( '/^[A-Za-z0-9-]+$/', $name ) ) {
				continue;
			}
			$headers[ $name ] = $value;
		}
		return $headers;
	}

	/**
	 * Header map → `Name: value` lines for the textarea.
	 *
	 * @param array<string, string> $headers Header map.
	 */
	public function headers_to_lines( array $headers ): string {
		$lines = [];
		foreach ( $headers as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}
		return implode( "\n", $lines );
	}
}
