<?php
/**
 * Sends one signed webhook request and records the outcome.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Webhook;

use BlueFairy\ApiGateway\Logger;
use BlueFairy\ApiGateway\Plugin;
use BlueFairy\ApiGateway\Settings;

/**
 * The only place an outgoing HTTP request is built.
 *
 * Every request carries an HMAC-SHA256 signature over `timestamp.body` so the
 * receiver can verify both origin and freshness, and the exact body bytes are
 * stored in the delivery row so a retry re-sends what was signed.
 *
 * Custom headers from the webhook row pass through sanitize_headers() (review
 * finding SEC5): an admin's stored `{"Host": "169.254.169.254"}` must never
 * turn the plugin into an SSRF proxy, and a value with CR/LF must never split
 * the request. Each rejection is logged when debug logging is on.
 */
final class WebhookDispatcher {

	/**
	 * Header names that are never forwarded from the row, lowercased.
	 *
	 * @var list<string>
	 */
	private const DENY = [
		'host',
		'content-length',
		'transfer-encoding',
		'connection',
		'cookie',
		'authorization',
		'proxy-authorization',
	];

	/**
	 * Headers this class sets itself; a custom header of the same name is
	 * dropped so a row cannot override the signature or the content type.
	 *
	 * @var list<string>
	 */
	private const RESERVED = [
		'content-type',
		'user-agent',
		'x-bf-event',
		'x-bf-delivery',
		'x-bf-timestamp',
		'x-bf-signature',
	];

	/**
	 * Lazily built reader of a row's custom headers.
	 *
	 * @var WebhookRepository|null
	 */
	private ?WebhookRepository $webhooks = null;

	/**
	 * Constructor.
	 *
	 * @param DeliveryRepository $deliveries Delivery log.
	 * @param Settings           $settings   Plugin settings.
	 */
	public function __construct(
		private readonly DeliveryRepository $deliveries,
		private readonly Settings $settings
	) {}

	/**
	 * Deliver $payload to $webhook. A delivery row is written as `pending`
	 * before the request and finalised after it, so a fatal mid-send leaves a
	 * visible pending row rather than nothing.
	 *
	 * @param array<string, mixed> $webhook     The webhook row.
	 * @param string               $event       Event key.
	 * @param array<string, mixed> $payload     Payload to encode.
	 * @param int                  $attempt     Attempt number (1 = first).
	 * @param int|null             $delivery_id Existing row to reuse (Pro retries); null creates one.
	 * @return array{delivery_id:int, success:bool, http_status:int|null}
	 */
	public function dispatch( array $webhook, string $event, array $payload, int $attempt = 1, ?int $delivery_id = null ): array {
		$body = wp_json_encode( $payload );
		if ( ! is_string( $body ) ) {
			Logger::log( 'webhook payload could not be encoded', [ 'event' => $event ] );
			$body = '{}';
		}
		$attempt = max( 1, $attempt );

		$max_bytes = max( 1, (int) $this->settings->get( 'max_payload_bytes' ) );
		if ( strlen( $body ) > $max_bytes ) {
			// The request still carries the full body; only the stored copy is cut, so this delivery cannot be redelivered.
			Logger::log(
				'webhook payload exceeds max_payload_bytes; stored copy will be truncated',
				[
					'event' => $event,
					'bytes' => strlen( $body ),
					'max'   => $max_bytes,
				]
			);
		}

		if ( null === $delivery_id || $delivery_id <= 0 ) {
			$delivery_id = $this->deliveries->create(
				[
					'webhook_id' => (int) ( $webhook['id'] ?? 0 ),
					'direction'  => 'out',
					'event'      => $event,
					'payload'    => $body,
					'status'     => 'pending',
					'attempt'    => $attempt,
				]
			);
			if ( false === $delivery_id ) {
				Logger::log( 'webhook delivery row could not be created', [ 'event' => $event ] );
				return [
					'delivery_id' => 0,
					'success'     => false,
					'http_status' => null,
				];
			}
		} else {
			$this->deliveries->update(
				$delivery_id,
				[
					'status'  => 'pending',
					'attempt' => $attempt,
				]
			);
		}

		return $this->send( $webhook, $event, $payload, $body, $attempt, $delivery_id );
	}

	/**
	 * Resend a stored delivery's exact bytes as the next attempt. A truncated
	 * payload is refused: its bytes are no longer the signed document.
	 *
	 * @param int                  $delivery_id Delivery row id.
	 * @param array<string, mixed> $webhook     The webhook row.
	 * @return array{delivery_id:int, success:bool, http_status:int|null}
	 */
	public function redeliver( int $delivery_id, array $webhook ): array {
		$failure = [
			'delivery_id' => $delivery_id,
			'success'     => false,
			'http_status' => null,
		];
		$row     = $this->deliveries->find( $delivery_id );
		if ( null === $row ) {
			Logger::log( 'redeliver: delivery not found', [ 'delivery_id' => $delivery_id ] );
			return $failure;
		}
		if ( 1 === (int) $row['truncated'] ) {
			Logger::log( 'redeliver: payload was truncated, refusing to resend', [ 'delivery_id' => $delivery_id ] );
			return $failure;
		}
		$body    = (string) $row['payload'];
		$decoded = json_decode( $body, true );
		$attempt = (int) $row['attempt'] + 1;
		$this->deliveries->update(
			$delivery_id,
			[
				'status'  => 'pending',
				'attempt' => $attempt,
			]
		);
		return $this->send( $webhook, (string) $row['event'], is_array( $decoded ) ? $decoded : [], $body, $attempt, $delivery_id );
	}

	/**
	 * Keep only safe custom headers. Names must match `[A-Za-z0-9-]+`; the
	 * hop-by-hop, routing and credential headers are dropped regardless of
	 * case, as is any value carrying CR or LF. Every rejection is logged.
	 *
	 * @param array<mixed> $raw Header name => value.
	 * @return array<string, string>
	 */
	public function sanitize_headers( array $raw ): array {
		return $this->filter_headers( $raw, false );
	}

	/**
	 * HMAC-SHA256 of `timestamp.body` with the webhook secret.
	 *
	 * @param string $secret    Shared secret.
	 * @param int    $timestamp Unix timestamp sent in X-BF-Timestamp.
	 * @param string $body      The exact request body.
	 */
	public static function sign( string $secret, int $timestamp, string $body ): string {
		return hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
	}

	/**
	 * Build, send and record one request.
	 *
	 * @param array<string, mixed> $webhook     The webhook row.
	 * @param string               $event       Event key.
	 * @param array<string, mixed> $payload     Decoded payload (for filters).
	 * @param string               $body        Exact bytes to send.
	 * @param int                  $attempt     Attempt number.
	 * @param int                  $delivery_id Delivery row id.
	 * @return array{delivery_id:int, success:bool, http_status:int|null}
	 */
	private function send( array $webhook, string $event, array $payload, string $body, int $attempt, int $delivery_id ): array {
		$was_dispatching     = Plugin::$dispatching;
		Plugin::$dispatching = true;
		try {
			$timestamp = time();
			$secret    = is_string( $webhook['secret'] ?? null ) ? $webhook['secret'] : '';
			$core      = [
				'Content-Type'   => 'application/json',
				'User-Agent'     => 'BlueFairy-API-Gateway/' . BFAG_VERSION,
				'X-BF-Event'     => $event,
				'X-BF-Delivery'  => (string) $delivery_id,
				'X-BF-Timestamp' => (string) $timestamp,
				'X-BF-Signature' => 'sha256=' . self::sign( $secret, $timestamp, $body ),
			];

			$custom = $this->sanitize_headers( $this->repository()->headers_of( $webhook ) );
			foreach ( array_keys( $custom ) as $name ) {
				if ( in_array( strtolower( $name ), self::RESERVED, true ) ) {
					Logger::log(
						'webhook header rejected',
						[
							'name'   => $name,
							'reason' => 'reserved',
						]
					);
					unset( $custom[ $name ] );
				}
			}

			/**
			 * Credential headers an add-on preset supplies. `Authorization` is
			 * accepted here and nowhere else.
			 *
			 * @since 1.0.0
			 *
			 * @param array<string, string> $headers Default [].
			 * @param array<string, mixed>  $webhook The webhook row.
			 */
			$auth = apply_filters( 'bfag_webhook_auth_headers', [], $webhook );
			$auth = $this->filter_headers( is_array( $auth ) ? $auth : [], true );

			$args = [
				'timeout'            => 10,
				'redirection'        => 0,
				'reject_unsafe_urls' => true,
				'blocking'           => true,
				'headers'            => array_merge( $custom, $auth, $core ),
				'body'               => $body,
			];

			/**
			 * Filter the request before it is sent. A preset may rewrite the
			 * body, headers or set `url`.
			 *
			 * @since 1.0.0
			 *
			 * @param array<string, mixed> $args    wp_remote_post() arguments.
			 * @param array<string, mixed> $webhook The webhook row.
			 * @param string               $event   Event key.
			 * @param array<string, mixed> $payload Decoded payload.
			 */
			$filtered = apply_filters( 'bfag_webhook_request_args', $args, $webhook, $event, $payload );
			$args     = is_array( $filtered ) ? $filtered : $args;

			$url = is_string( $webhook['url'] ?? null ) ? $webhook['url'] : '';
			if ( isset( $args['url'] ) && is_string( $args['url'] ) ) {
				$url = $args['url'];
				unset( $args['url'] );
			}
			if ( '' === $url || false === wp_http_validate_url( $url ) ) {
				Logger::log( 'webhook url rejected', [ 'url' => $url ] );
				return $this->finish( $delivery_id, $webhook, $attempt, false, null, 'Invalid or unsafe URL' );
			}

			$response = wp_remote_post( $url, $args );
			if ( is_wp_error( $response ) ) {
				return $this->finish( $delivery_id, $webhook, $attempt, false, null, $response->get_error_message() );
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			return $this->finish( $delivery_id, $webhook, $attempt, $code >= 200 && $code < 300, $code, wp_remote_retrieve_body( $response ) );
		} finally {
			Plugin::$dispatching = $was_dispatching;
		}
	}

	/**
	 * Record the outcome and fire the matching action.
	 *
	 * @param int                  $delivery_id Delivery row id.
	 * @param array<string, mixed> $webhook     The webhook row.
	 * @param int                  $attempt     Attempt number.
	 * @param bool                 $success     Whether the response was 2xx.
	 * @param int|null             $http_status Response code, null for a transport failure.
	 * @param string               $body        Response body or error message.
	 * @return array{delivery_id:int, success:bool, http_status:int|null}
	 */
	private function finish( int $delivery_id, array $webhook, int $attempt, bool $success, ?int $http_status, string $body ): array {
		$this->deliveries->update(
			$delivery_id,
			[
				'status'        => $success ? 'success' : 'failed',
				'http_status'   => $http_status,
				'response_body' => substr( $body, 0, 2000 ),
				'delivered_at'  => $success ? gmdate( 'Y-m-d H:i:s' ) : null,
				'attempt'       => $attempt,
			]
		);

		if ( $success ) {
			/**
			 * A delivery succeeded.
			 *
			 * @since 1.0.0
			 *
			 * @param int                  $delivery_id Delivery row id.
			 * @param array<string, mixed> $webhook     The webhook row.
			 */
			do_action( 'bfag_webhook_delivered', $delivery_id, $webhook );
		} else {
			Logger::log(
				'webhook delivery failed',
				[
					'delivery_id' => $delivery_id,
					'webhook_id'  => (int) ( $webhook['id'] ?? 0 ),
					'attempt'     => $attempt,
					'http_status' => $http_status,
				]
			);

			/**
			 * A delivery failed (non-2xx or transport error). The Pro add-on
			 * schedules retries and alerts from here.
			 *
			 * @since 1.0.0
			 *
			 * @param int                  $delivery_id Delivery row id.
			 * @param array<string, mixed> $webhook     The webhook row.
			 * @param int                  $attempt     Attempt number.
			 * @param int|null             $http_status Response code, null for a transport failure.
			 */
			do_action( 'bfag_webhook_delivery_failed', $delivery_id, $webhook, $attempt, $http_status );
		}

		return [
			'delivery_id' => $delivery_id,
			'success'     => $success,
			'http_status' => $http_status,
		];
	}

	/**
	 * The shared header filter. $allow_authorization is true only for the
	 * `bfag_webhook_auth_headers` result.
	 *
	 * @param array<mixed> $raw                 Header name => value.
	 * @param bool         $allow_authorization Whether Authorization may pass.
	 * @return array<string, string>
	 */
	private function filter_headers( array $raw, bool $allow_authorization ): array {
		$out = [];
		foreach ( $raw as $name => $value ) {
			$name = is_string( $name ) ? $name : (string) $name;
			if ( 1 !== preg_match( '/^[A-Za-z0-9-]+$/', $name ) ) {
				$this->reject( $name, 'invalid name' );
				continue;
			}
			$lower = strtolower( $name );
			if ( str_starts_with( $lower, 'x-forwarded-' ) ) {
				$this->reject( $name, 'forwarding header' );
				continue;
			}
			if ( in_array( $lower, self::DENY, true ) && ! ( $allow_authorization && in_array( $lower, [ 'authorization', 'proxy-authorization' ], true ) ) ) {
				$this->reject( $name, 'denied header' );
				continue;
			}
			if ( ! is_scalar( $value ) ) {
				$this->reject( $name, 'non-scalar value' );
				continue;
			}
			$value = (string) $value;
			if ( 1 === preg_match( '/[\r\n]/', $value ) ) {
				$this->reject( $name, 'CR/LF in value' );
				continue;
			}
			$out[ $name ] = $value;
		}
		return $out;
	}

	/**
	 * Log one rejected header.
	 *
	 * @param string $name   Header name as supplied.
	 * @param string $reason Why it was dropped.
	 */
	private function reject( string $name, string $reason ): void {
		Logger::log(
			'webhook header rejected',
			[
				'name'   => $name,
				'reason' => $reason,
			]
		);
	}

	/**
	 * The repository used only to decode a row's headers column.
	 */
	private function repository(): WebhookRepository {
		if ( null === $this->webhooks ) {
			$this->webhooks = new WebhookRepository();
		}
		return $this->webhooks;
	}
}
