<?php
/**
 * Sends one signed webhook request and records the outcome.
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
	 * Seconds to wait for the receiver. Deliveries are sent after the
	 * response, up to EventBus::FLUSH_LIMIT of them in a row, so a slow
	 * receiver must not hold a worker for minutes.
	 */
	public const TIMEOUT = 5;

	/**
	 * The response body recorded for a queued delivery whose stored payload
	 * is truncated: nothing was sent (WPMAP-141).
	 */
	public const TRUNCATED_UNSENT = 'Payload longer than Max stored payload (bytes): the stored copy is truncated, so it could not be sent (nothing sent).';

	/**
	 * Reader of a row's custom headers and of the webhook a queued delivery
	 * belongs to: the shared instance from Plugin::boot(), or one built here
	 * when none was given (F16).
	 *
	 * @var WebhookRepository
	 */
	private WebhookRepository $webhooks;

	/**
	 * Constructor.
	 *
	 * @param DeliveryRepository     $deliveries Delivery log.
	 * @param Settings               $settings   Plugin settings.
	 * @param WebhookRepository|null $webhooks   The shared repository; null builds one.
	 */
	public function __construct(
		private readonly DeliveryRepository $deliveries,
		private readonly Settings $settings,
		?WebhookRepository $webhooks = null
	) {
		$this->webhooks = $webhooks ?? new WebhookRepository();
	}

	/**
	 * Deliver $payload to $webhook. A delivery row is written as `pending`
	 * before the request and finalised after it, so a fatal mid-send leaves a
	 * visible pending row rather than nothing.
	 *
	 * @param array<string, mixed> $webhook     The webhook row, or a summary from all_enabled() (no secret: the full row is read with find()).
	 * @param string               $event       Event key.
	 * @param array<string, mixed> $payload     Payload to encode.
	 * @param int                  $attempt     Attempt number (1 = first).
	 * @param int|null             $delivery_id Existing row to reuse (Pro retries); null creates one.
	 * @return array{delivery_id:int, success:bool, http_status:int|null}
	 */
	public function dispatch( array $webhook, string $event, array $payload, int $attempt = 1, ?int $delivery_id = null ): array {
		$full = $this->with_credentials( $webhook );
		if ( null === $full ) {
			Logger::log( 'webhook delivery skipped: the webhook is gone or disabled', [ 'webhook_id' => (int) ( $webhook['id'] ?? 0 ) ] );
			return [
				'delivery_id' => (int) ( $delivery_id ?? 0 ),
				'success'     => false,
				'http_status' => null,
			];
		}
		$webhook = $full;
		$body    = wp_json_encode( $payload );
		if ( ! is_string( $body ) ) {
			Logger::log( 'webhook payload could not be encoded', [ 'event' => $event ] );
			$body = '{}';
		}
		$attempt = max( 1, $attempt );

		$max_bytes = max( 1, (int) $this->settings->get( 'max_payload_bytes' ) );
		$truncated = strlen( $body ) > $max_bytes;
		if ( $truncated ) {
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
					'status'        => 'pending',
					'attempt'       => $attempt,
					'next_retry_at' => null,
				]
			);
		}

		// WPMAP-139: a failure of a delivery whose stored copy is cut is
		// final — nothing could resend it — so a scheduler leaves it `failed`.
		return $this->send( $webhook, $event, $payload, $body, $attempt, $delivery_id, $truncated );
	}

	/**
	 * Resend a stored delivery's exact bytes as the next attempt. A truncated
	 * payload is refused: its bytes are no longer the signed document. A
	 * truncated row that is `retrying` (a scheduler is holding it) is also
	 * recorded as a final failure — `failed`, `next_retry_at` cleared,
	 * `bfag_webhook_delivery_failed` with `$is_final` true — so the sweep
	 * stops selecting it and the final-failure alert fires (WPMAP-139); its
	 * stored response and attempt are left as they were. An
	 * inbound row (`direction` other than `out`) is refused too: it was
	 * written by whoever called the receiving endpoint, and signing it with
	 * the site's secret would launder an unverified sender's payload.
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
		if ( 'out' !== (string) ( $row['direction'] ?? '' ) ) {
			Logger::log(
				'redeliver: only outgoing deliveries can be resent',
				[
					'delivery_id' => $delivery_id,
					'direction'   => (string) ( $row['direction'] ?? '' ),
				]
			);
			return $failure;
		}
		if ( 1 === (int) $row['truncated'] ) {
			Logger::log( 'redeliver: payload was truncated, refusing to resend', [ 'delivery_id' => $delivery_id ] );
			if ( 'retrying' === (string) ( $row['status'] ?? '' ) ) {
				$this->end_unresendable( $delivery_id, $row, $webhook );
			}
			return $failure;
		}
		$body    = (string) $row['payload'];
		$decoded = json_decode( $body, true );
		$attempt = (int) $row['attempt'] + 1;
		// PN2: a `retrying` row carries the due time the Pro scheduler set.
		// Flipped to `pending` with it still set, the row is also what
		// DeliveryRepository::pending() selects — and the flush cron would
		// send it a second time mid-redeliver. Clear it with the status.
		$this->deliveries->update(
			$delivery_id,
			[
				'status'        => 'pending',
				'attempt'       => $attempt,
				'next_retry_at' => null,
			]
		);
		return $this->send( $webhook, (string) $row['event'], is_array( $decoded ) ? $decoded : [], $body, $attempt, $delivery_id );
	}

	/**
	 * Send a delivery the EventBus queued past its flush limit: the row's
	 * stored payload, as the attempt the row records (1). The webhook comes
	 * from `WebhookRepository::all_enabled()` — object-cached, so a batch of
	 * queued rows costs no webhook query per row (PN4); a webhook missing
	 * from it (deleted or disabled) marks the row `failed` without sending.
	 * A truncated payload (WPMAP-141) cannot be sent at all — the full body
	 * existed only in the request that queued it — so the row is finished as
	 * a FINAL failure: `failed`, `response_body` TRUNCATED_UNSENT, and
	 * `bfag_webhook_delivery_failed` with `$is_final` true, as the shutdown
	 * flush does for an oversized delivery that fails, so the Pro alert
	 * fires. `next_retry_at` is cleared first so the row is handed out once.
	 *
	 * @param array<string, mixed> $row A delivery row from DeliveryRepository::pending().
	 * @return array{delivery_id:int, success:bool, http_status:int|null}
	 */
	public function dispatch_pending( array $row ): array {
		$delivery_id = (int) ( $row['id'] ?? 0 );
		$failure     = [
			'delivery_id' => $delivery_id,
			'success'     => false,
			'http_status' => null,
		];
		$webhook_id  = (int) ( $row['webhook_id'] ?? 0 );
		$webhook     = null;
		foreach ( $this->webhooks->all_enabled() as $candidate ) {
			if ( (int) ( $candidate['id'] ?? 0 ) === $webhook_id ) {
				$webhook = $candidate;
				break;
			}
		}
		if ( null === $webhook || 1 !== (int) ( $webhook['enabled'] ?? 0 ) ) {
			Logger::log( 'pending delivery dropped: webhook missing or disabled', [ 'delivery_id' => $delivery_id ] );
			$this->deliveries->update(
				$delivery_id,
				[
					'status'        => 'failed',
					'next_retry_at' => null,
				]
			);
			return $failure;
		}
		if ( 1 === (int) ( $row['truncated'] ?? 0 ) ) {
			// WPMAP-141: the stored copy is all that is left and it is cut, so
			// nothing can be sent now or ever. Recorded like any other final
			// failure — `failed`, the reason as the response, and
			// bfag_webhook_delivery_failed with $is_final true — so a scheduler
			// leaves it and the final-failure alert fires, exactly as for an
			// oversized delivery that failed in the shutdown flush.
			Logger::log( 'pending delivery dropped: payload was truncated', [ 'delivery_id' => $delivery_id ] );
			return $this->finish(
				$delivery_id,
				$webhook,
				max( 1, (int) ( $row['attempt'] ?? 1 ) ),
				false,
				null,
				self::TRUNCATED_UNSENT,
				true
			);
		}
		// The summary decided; the credentials come from the row itself (WPMAP-117), one read per delivery sent.
		$webhook = $this->with_credentials( $webhook );
		if ( null === $webhook ) {
			Logger::log( 'pending delivery dropped: webhook missing or disabled', [ 'delivery_id' => $delivery_id ] );
			$this->deliveries->update(
				$delivery_id,
				[
					'status'        => 'failed',
					'next_retry_at' => null,
				]
			);
			return $failure;
		}
		$this->deliveries->update( $delivery_id, [ 'next_retry_at' => null ] );
		$body    = (string) ( $row['payload'] ?? '' );
		$decoded = json_decode( $body, true );
		return $this->send( $webhook, (string) ( $row['event'] ?? '' ), is_array( $decoded ) ? $decoded : [], $body, max( 1, (int) ( $row['attempt'] ?? 1 ) ), $delivery_id );
	}

	/**
	 * Record a `retrying` row that can never be resent (its stored payload
	 * is truncated) as a final failure: `failed`, no due time, and
	 * `bfag_webhook_delivery_failed` with `$is_final` true, so a scheduler
	 * leaves it and its final-failure alert fires (WPMAP-139). Nothing is
	 * sent; the row's last response and attempt stay as recorded.
	 *
	 * @param int                  $delivery_id Delivery row id.
	 * @param array<string, mixed> $row         The delivery row.
	 * @param array<string, mixed> $webhook     The webhook row.
	 */
	private function end_unresendable( int $delivery_id, array $row, array $webhook ): void {
		$this->deliveries->update(
			$delivery_id,
			[
				'status'        => 'failed',
				'next_retry_at' => null,
			]
		);
		$http_status = is_numeric( $row['http_status'] ?? null ) ? (int) $row['http_status'] : null;
		/** This action is documented in includes/Webhook/WebhookDispatcher.php (finish()). */
		do_action( 'bfag_webhook_delivery_failed', $delivery_id, $webhook, max( 1, (int) ( $row['attempt'] ?? 1 ) ), $http_status, true );
	}

	/**
	 * The full row of a webhook given as a summary (no `secret` key — what
	 * all_enabled() returns, WPMAP-117): read with find(), and null when it
	 * is gone or no longer enabled. A full row (it carries `secret`) is
	 * returned as given: the admin's Send test and Retry pass find() rows.
	 *
	 * @param array<string, mixed> $webhook A webhook row or summary.
	 * @return array<string, mixed>|null
	 */
	private function with_credentials( array $webhook ): ?array {
		if ( array_key_exists( 'secret', $webhook ) ) {
			return $webhook;
		}
		$full = $this->webhooks->find( (int) ( $webhook['id'] ?? 0 ) );
		return null !== $full && 1 === (int) ( $full['enabled'] ?? 0 ) ? $full : null;
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
	 * @param bool                 $unresendable True when the stored copy is truncated (WPMAP-139): a
	 *                                           failure is then final, because no retry could resend it.
	 * @return array{delivery_id:int, success:bool, http_status:int|null}
	 */
	private function send( array $webhook, string $event, array $payload, string $body, int $attempt, int $delivery_id, bool $unresendable = false ): array {
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

			$custom = $this->sanitize_headers( $this->webhooks->headers_of( $webhook ) );
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

			$args = [
				'timeout'            => self::TIMEOUT,
				'redirection'        => 0,
				'reject_unsafe_urls' => true,
				'blocking'           => true,
				'headers'            => array_merge( $custom, $core ),
				'body'               => $body,
			];

			/**
			 * Filter the request before it is sent. A preset may rewrite the
			 * body, headers or set `url`, and may set `Authorization` (this
			 * is the one place a credential header is accepted); every other
			 * transport header it plants — `Host`, `X-Forwarded-*`, a CR/LF
			 * value — is stripped again afterwards (F4). Setting
			 * `bfag_skip` to a non-empty string cancels the delivery: it is
			 * recorded as failed with that string as the response body and
			 * nothing is sent (N5).
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

			$skip = $args['bfag_skip'] ?? null;
			unset( $args['bfag_skip'] );
			if ( is_string( $skip ) && '' !== $skip ) {
				Logger::log(
					'webhook delivery skipped by filter',
					[
						'delivery_id' => $delivery_id,
						'reason'      => $skip,
					]
				);
				return $this->finish( $delivery_id, $webhook, $attempt, false, null, $skip, true );
			}

			// The filter is trusted for credentials, not for transport headers.
			$args['headers'] = $this->filter_headers( is_array( $args['headers'] ?? null ) ? $args['headers'] : [], true );

			$url = is_string( $webhook['url'] ?? null ) ? $webhook['url'] : '';
			if ( isset( $args['url'] ) && is_string( $args['url'] ) ) {
				$url = $args['url'];
				unset( $args['url'] );
			}
			if ( '' === $url || false === wp_http_validate_url( $url ) ) {
				Logger::log( 'webhook url rejected', [ 'url' => $url ] );
				// D3: stored as the response body so the log says why nothing
				// was sent; final, because the same URL fails the same way.
				return $this->finish(
					$delivery_id,
					$webhook,
					$attempt,
					false,
					null,
					'Invalid or unsafe URL (nothing sent): ' . ( '' === $url ? '(empty)' : $url ),
					true
				);
			}

			$response = wp_remote_post( $url, $args );
			if ( is_wp_error( $response ) ) {
				return $this->finish( $delivery_id, $webhook, $attempt, false, null, $response->get_error_message(), $unresendable );
			}
			$code    = (int) wp_remote_retrieve_response_code( $response );
			$success = $code >= 200 && $code < 300;
			return $this->finish( $delivery_id, $webhook, $attempt, $success, $code, wp_remote_retrieve_body( $response ), ! $success && $unresendable );
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
	 * @param bool                 $is_final    True when a retry could never succeed (the preset
	 *                                          skipped it, the URL is invalid or unsafe, or the stored
	 *                                          payload is truncated so nothing could resend it).
	 * @return array{delivery_id:int, success:bool, http_status:int|null}
	 */
	private function finish( int $delivery_id, array $webhook, int $attempt, bool $success, ?int $http_status, string $body, bool $is_final = false ): array {
		// PN2: a finished row is never due again. Left set, every finished
		// row stayed in idx_next_retry and the pending sweep paid for it.
		$this->deliveries->update(
			$delivery_id,
			[
				'status'        => $success ? 'success' : 'failed',
				'http_status'   => $http_status,
				'response_body' => substr( $body, 0, 2000 ),
				'delivered_at'  => $success ? gmdate( 'Y-m-d H:i:s' ) : null,
				'attempt'       => $attempt,
				'next_retry_at' => null,
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
					'final'       => $is_final,
				]
			);

			/**
			 * A delivery failed. The Pro add-on schedules retries and alerts
			 * from here.
			 *
			 * `$final` is true for the outcomes a retry can never change
			 * (N-05 / D3): the request-args filter cancelled the delivery
			 * (`bfag_skip` — the preset could not build a request from this
			 * payload), the URL is invalid or unsafe, or the payload was
			 * longer than `max_payload_bytes`, so the stored copy is
			 * truncated and could not be resent (WPMAP-139). A scheduler must
			 * leave such a row `failed`. It is false for any other HTTP
			 * (non-2xx) or transport failure (`$http_status` null), which may
			 * be retried.
			 *
			 * @since 1.0.0
			 *
			 * @param int                  $delivery_id Delivery row id.
			 * @param array<string, mixed> $webhook     The webhook row.
			 * @param int                  $attempt     Attempt number.
			 * @param int|null             $http_status Response code, null when no HTTP response was received.
			 * @param bool                 $is_final    True when no retry could succeed.
			 */
			do_action( 'bfag_webhook_delivery_failed', $delivery_id, $webhook, $attempt, $http_status, $is_final );
		}

		return [
			'delivery_id' => $delivery_id,
			'success'     => $success,
			'http_status' => $http_status,
		];
	}

	/**
	 * The shared header filter. $allow_authorization is true only for the
	 * headers that come back from `bfag_webhook_request_args`.
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
}
