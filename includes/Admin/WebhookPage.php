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

use BlueFairy\ApiGateway\Support\SealedValue;
use BlueFairy\ApiGateway\Webhook\DeliveryRepository;
use BlueFairy\ApiGateway\Webhook\EventRegistry;
use BlueFairy\ApiGateway\Webhook\WebhookDispatcher;
use BlueFairy\ApiGateway\Webhook\WebhookRepository;

/**
 * Webhook list, add/edit form, Send test, and the delivery log sub-view
 * (`?tab=webhooks&view=deliveries[&webhook_id=]`).
 *
 * The stored secret is never rendered: the form field is blank and blank
 * means "keep". A secret the plugin generates (blank on create, or
 * "Generate a new secret") is shown exactly once, on the next render of the
 * tab, from a user-scoped transient that is deleted on display — otherwise
 * the admin has no way to configure the receiver. The event must exist in
 * the registry — the repository stores any sanitised key, so the check
 * belongs here, in front of the only form that can create one.
 */
final class WebhookPage extends Page {

	public const TAB = 'webhooks';

	public const DELIVERY_LIMIT = 50;

	/**
	 * Seconds a generated secret waits to be shown once. It is shown on the
	 * page the save redirects to; the value is sealed while it waits
	 * (Support\SealedValue, WPMAP-140), so neither the options table nor a
	 * shared object cache holds the secret in the clear.
	 */
	public const NEW_SECRET_TTL = 120;

	/**
	 * Header names that may be shown in, and read from, the Extra headers
	 * textarea. Anything else in the row's headers JSON (the Pro add-on's
	 * `preset_config`, which holds API keys) is neither rendered nor editable.
	 */
	private const HEADER_NAME = '/^[A-Za-z0-9-]+$/';

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
		$status = $this->deliveries->last_statuses( array_map( static fn( array $row ): int => (int) $row['id'], $rows ) );

		/**
		 * Preset choices for the webhook form (`slug => label`). Empty in
		 * the free plugin; when non-empty the form shows a Preset select
		 * and the list shows the label in place of the slug.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $presets Default [].
		 */
		$presets = apply_filters( 'bfag_webhook_presets', [] );
		$presets = is_array( $presets ) ? $presets : [];

		$this->view(
			'webhooks',
			[
				'webhooks'       => $rows,
				'count'          => $count,
				'limit'          => $limit,
				'last_status'    => $status,
				'registry'       => $this->registry,
				'page'           => $this,
				'deliveries_url' => $this->url( [ 'view' => 'deliveries' ] ),
				'new_secret'     => $this->take_new_secret(),
				'presets'        => $presets,
			]
		);

		$editing = $edit_id > 0 ? $this->webhooks->find( $edit_id ) : null;
		if ( null === $editing && $count >= $limit ) {
			echo '<p class="bfag-limit-line">' . esc_html( $this->upsell->limit_line( sprintf( /* translators: %d: number of webhooks */ _n( '%d webhook', '%d webhooks', $limit, 'bluefairy-api-gateway' ), $limit ) ) ) . '</p>';
			return;
		}

		/**
		 * Values the Add webhook form opens with. The Pro add-on sets
		 * `max_retries` to its "Retries per delivery" default.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $defaults Field defaults.
		 */
		$defaults       = (array) apply_filters(
			'bfag_webhook_form_defaults',
			[
				'id'          => 0,
				'label'       => '',
				'url'         => '',
				'event'       => '',
				'headers'     => '',
				'preset'      => '',
				'enabled'     => 1,
				'max_retries' => 3,
			]
		);
		$defaults['id'] = 0;
		$webhook        = $editing ?? $defaults;
		// The secret never reaches the view, even when editing.
		unset( $webhook['secret'] );
		// A failed save re-fills the form once with what was typed (U-11).
		$remembered = $this->take_form();
		if ( null !== $remembered ) {
			unset( $remembered['secret'], $remembered['regenerate_secret'] );
			$webhook = array_merge( $webhook, $remembered );
		}

		$this->view(
			'webhook-form',
			[
				'webhook'    => $webhook,
				'events'     => $this->registry->all(),
				'registry'   => $this->registry,
				'presets'    => $presets,
				'headers'    => is_string( $remembered['headers'] ?? null ) ? $remembered['headers'] : $this->headers_to_lines( $this->webhooks->headers_of( $webhook ) ),
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
			$this->fail( 'webhook_event_invalid', $id, $fields );
		}
		// The notice names the failing field (U-11): label and URL are checked here; the repository re-checks on write.
		$label = sanitize_text_field( (string) ( $fields['label'] ?? '' ) );
		if ( '' === $label || mb_strlen( $label ) > 200 ) {
			$this->fail( 'webhook_label_invalid', $id, $fields );
		}
		$preset = sanitize_key( (string) ( $fields['preset'] ?? '' ) );
		$url    = esc_url_raw( trim( (string) ( $fields['url'] ?? '' ) ) );
		// A preset builds its own request, so the URL may be blank with one (U-14); without one a webhook needs somewhere to go.
		if ( ! ( '' === $url && '' !== $preset ) && ! $this->url_acceptable( $url ) ) {
			$this->fail( 'webhook_url_invalid', $id, $fields );
		}

		$data = [
			'label'   => $label,
			'url'     => $url,
			'event'   => $event,
			'headers' => $this->lines_to_headers( (string) ( $fields['headers'] ?? '' ) ),
			'preset'  => $preset,
			'enabled' => self::flag( $fields, 'enabled' ),
		];
		if ( $this->upsell->is_pro() && array_key_exists( 'max_retries', $fields ) ) {
			$data['max_retries'] = (int) $fields['max_retries'];
		}

		if ( $id > 0 ) {
			$existing = $this->webhooks->find( $id );
			if ( null === $existing ) {
				$this->redirect( 'webhook_not_found' );
			}
			// Keys that are not header names never reached the textarea, so the form cannot carry them back: keep them from the row.
			$data['headers'] = $this->preserve_hidden_headers( $data['headers'], $this->webhooks->headers_of( $existing ) );
		}

		$secret = trim( (string) ( $fields['secret'] ?? '' ) );
		$shown  = null;
		if ( self::flag( $fields, 'regenerate_secret' ) ) {
			$shown          = self::generate_secret();
			$data['secret'] = $shown;
		} elseif ( '' !== $secret ) {
			$data['secret'] = $secret;
		}
		// Blank and not regenerating: no `secret` key, so update() keeps the stored one; create() gets one generated below.

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
			if ( ! $this->webhooks->update( $id, $data ) ) {
				$this->fail( 'webhook_invalid', $id, $fields );
			}
			$this->redirect( $this->saved( $shown ) );
		}

		if ( $this->webhooks->count() >= $this->webhooks->limit() ) {
			$this->redirect( 'webhook_limit' );
		}
		if ( ! is_string( $data['secret'] ?? null ) || '' === trim( $data['secret'] ) ) {
			// Generated here rather than left to the repository so it can be shown once.
			$shown          = self::generate_secret();
			$data['secret'] = $shown;
		}
		if ( false === $this->webhooks->create( $data ) ) {
			$this->fail( 'webhook_invalid', 0, $fields );
		}
		$this->redirect( $this->saved( $shown ) );
	}

	/**
	 * The URL rule the repository enforces, asked up front so the notice
	 * can name it: https (unless `bfag_webhook_allow_http`) and a public
	 * address by wp_http_validate_url().
	 *
	 * @param string $url The submitted URL.
	 */
	private function url_acceptable( string $url ): bool {
		if ( '' === $url || strlen( $url ) > 2000 || false === wp_http_validate_url( $url ) ) {
			return false;
		}
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		/** This filter is documented in includes/Webhook/WebhookRepository.php */
		$allow_http = (bool) apply_filters( 'bfag_webhook_allow_http', false );
		return 'https' === $scheme || ( 'http' === $scheme && $allow_http );
	}

	/**
	 * A refused save: keep what was typed (never the secret) for one
	 * re-render and go back to the form.
	 *
	 * @param string               $notice Notice code.
	 * @param int                  $id     Webhook id being edited, 0 when adding.
	 * @param array<string, mixed> $fields The submitted fields.
	 */
	private function fail( string $notice, int $id, array $fields ): never {
		unset( $fields['id'], $fields['secret'], $fields['regenerate_secret'] );
		$this->remember_form( $fields );
		$this->redirect(
			$notice,
			$id > 0 ? [
				'action' => 'edit',
				'id'     => $id,
			] : []
		);
	}

	/**
	 * The notice for a successful save. A generated secret is parked, sealed
	 * for the transient's own name, in a user-scoped transient for the tab
	 * to show once (WPMAP-140).
	 *
	 * @param string|null $shown The secret the plugin generated, if any.
	 */
	private function saved( ?string $shown ): string {
		if ( null === $shown ) {
			return 'webhook_saved';
		}
		$transient = self::new_secret_transient();
		set_transient( $transient, SealedValue::seal( $shown, $transient ), self::NEW_SECRET_TTL );
		return 'webhook_secret_shown';
	}

	/**
	 * The secret parked by the current user's last save, deleted on read.
	 * Anything that does not open as a value sealed for this user's
	 * transient — a plaintext value, another user's, a tampered one — is
	 * not shown, and is deleted all the same.
	 */
	private function take_new_secret(): ?string {
		$transient = self::new_secret_transient();
		$stored    = get_transient( $transient );
		if ( false === $stored ) {
			return null;
		}
		delete_transient( $transient );
		$secret = SealedValue::open( $stored, $transient );
		return null === $secret || '' === $secret ? null : $secret;
	}

	/**
	 * Transient carrying the one-time secret for the current user.
	 */
	private static function new_secret_transient(): string {
		return 'bfag_new_secret_' . get_current_user_id();
	}

	/**
	 * A fresh 64-character hexadecimal secret.
	 */
	private static function generate_secret(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Add back every key of the stored headers that is not a header name:
	 * such keys are never rendered or editable, so an edit of the visible
	 * headers must not drop them. Visible headers come from the form alone.
	 *
	 * @param array<string, string> $submitted Headers parsed from the textarea.
	 * @param array<string, string> $stored    Headers on the row being edited.
	 * @return array<string, string>
	 */
	private function preserve_hidden_headers( array $submitted, array $stored ): array {
		foreach ( $stored as $name => $value ) {
			if ( 1 !== preg_match( self::HEADER_NAME, $name ) ) {
				$submitted[ $name ] = $value;
			}
		}
		return $submitted;
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
		if ( $result['success'] ) {
			$this->redirect( 'webhook_test_ok' );
		}
		// The status travels with the notice; `none` is a transport error (no response at all).
		$this->redirect( 'webhook_test_failed', [ 'bfag_http' => null === $result['http_status'] ? 'none' : (string) $result['http_status'] ] );
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
			if ( '' === $name || 1 !== preg_match( self::HEADER_NAME, $name ) ) {
				continue;
			}
			$headers[ $name ] = $value;
		}
		return $headers;
	}

	/**
	 * Header map → `Name: value` lines for the textarea. Keys that are not
	 * header names are skipped: the dispatcher would never send them, and
	 * the Pro add-on keeps its preset credentials under such a key.
	 *
	 * @param array<string, string> $headers Header map.
	 */
	public function headers_to_lines( array $headers ): string {
		$lines = [];
		foreach ( $headers as $name => $value ) {
			if ( 1 !== preg_match( self::HEADER_NAME, (string) $name ) ) {
				continue;
			}
			$lines[] = $name . ': ' . $value;
		}
		return implode( "\n", $lines );
	}
}
