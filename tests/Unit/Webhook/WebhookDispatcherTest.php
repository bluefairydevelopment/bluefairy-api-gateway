<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Webhook;

use BlueFairy\ApiGateway\Logger;
use BlueFairy\ApiGateway\Plugin;
use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Tests\TestCase;
use BlueFairy\ApiGateway\Webhook\DeliveryRepository;
use BlueFairy\ApiGateway\Webhook\WebhookDispatcher;
use BlueFairy\ApiGateway\Webhook\WebhookRepository;

final class WebhookDispatcherTest extends TestCase {

	private const SSRF_HEADERS = '{"Host":"evil.com","X-Forwarded-For":"127.0.0.1","Content-Length":"1","Transfer-Encoding":"chunked","Cookie":"a=b","Authorization":"Bearer x","X-Custom":"ok","Bad Name!":"x","X-CRLF":"a\r\nInjected: 1"}';

	/**
	 * Mid-test reset: bfag_test_reset() swaps $GLOBALS['wpdb'], so re-bind ours.
	 */
	private function reset(): void {
		bfag_test_reset();
		$this->wpdb = $GLOBALS['wpdb'];
	}

	private function dispatcher( array $settings = [] ): WebhookDispatcher {
		update_option( 'bfag_settings', $settings );
		$s = new Settings();
		return new WebhookDispatcher( new DeliveryRepository( $s ), $s );
	}

	private function enable_logging(): void {
		update_option( 'bfag_settings', [ 'debug_logging' => true ] );
		Logger::use_settings( new Settings() );
		ini_set( 'error_log', '/dev/null' );
	}

	/**
	 * @param array<string, mixed> $over Column overrides.
	 * @return array<string, mixed>
	 */
	private function webhook( array $over = [] ): array {
		return array_merge(
			[
				'id'          => 7,
				'label'       => 'Test hook',
				'url'         => 'https://hooks.example.com/in',
				'event'       => 'post.published',
				'secret'      => 's3cret-value',
				'headers'     => '{}',
				'preset'      => '',
				'enabled'     => 1,
				'max_retries' => 3,
			],
			$over
		);
	}

	/** @return array<string, mixed> */
	private function sent_args( int $index = 0 ): array {
		return $GLOBALS['bfag_test_http_calls'][ $index ]['args'];
	}

	/** @return array<string, string> lowercased header names => values */
	private function sent_headers_lower( int $index = 0 ): array {
		return array_change_key_case( $this->sent_args( $index )['headers'], CASE_LOWER );
	}

	// ------------------------------------------------------------------
	// SEC5: header sanitisation.
	// ------------------------------------------------------------------

	public function test_ssrf_headers_are_stripped_and_each_rejection_logged(): void {
		$this->enable_logging();
		$dispatcher = new WebhookDispatcher( new DeliveryRepository( new Settings() ), new Settings() );

		$dispatcher->dispatch( $this->webhook( [ 'headers' => self::SSRF_HEADERS ] ), 'post.published', [ 'a' => 1 ] );

		$this->assertCount( 1, $GLOBALS['bfag_test_http_calls'] );
		$headers = $this->sent_headers_lower();
		$this->assertSame( 'ok', $headers['x-custom'], 'the one safe header survives' );
		foreach ( [ 'host', 'x-forwarded-for', 'content-length', 'transfer-encoding', 'cookie', 'authorization', 'bad name!', 'x-crlf' ] as $name ) {
			$this->assertArrayNotHasKey( $name, $headers, "$name must be stripped" );
		}
		foreach ( $headers as $value ) {
			$this->assertDoesNotMatchRegularExpression( '/[\r\n]/', $value );
		}

		$rejections = array_values( array_filter( Logger::lines(), static fn( string $l ): bool => str_contains( $l, 'webhook header rejected' ) ) );
		$this->assertCount( 8, $rejections, 'one log line per rejected header' );
		foreach ( [ 'Host', 'X-Forwarded-For', 'Content-Length', 'Transfer-Encoding', 'Cookie', 'Authorization', 'Bad Name!', 'X-CRLF' ] as $name ) {
			$this->assertTrue( (bool) array_filter( $rejections, static fn( string $l ): bool => str_contains( $l, '"name":' . json_encode( $name ) ) ), "$name rejection logged" );
		}
	}

	public function test_sanitize_headers_directly_keeps_only_the_safe_header(): void {
		$dispatcher = $this->dispatcher();
		$raw        = json_decode( self::SSRF_HEADERS, true );
		$this->assertCount( 9, $raw, 'fixture decodes to nine candidate headers' );
		$this->assertSame( [ 'X-Custom' => 'ok' ], $dispatcher->sanitize_headers( $raw ) );
		$this->assertSame( [], $dispatcher->sanitize_headers( [ 'x-forwarded-host' => 'a', 'HOST' => 'b', 'Proxy-Authorization' => 'c', 'Connection' => 'close' ] ), 'denylist is case-insensitive' );
		$this->assertSame( [ 'X-Ok' => '1' ], $dispatcher->sanitize_headers( [ 'X-Ok' => 1, 'X-Arr' => [ 'a' ], 'X-Null' => null ] ), 'scalars are cast, non-scalars dropped' );
	}

	public function test_rejections_are_not_logged_when_debug_logging_is_off(): void {
		$dispatcher = $this->dispatcher( [ 'debug_logging' => false ] );
		Logger::use_settings( new Settings() );
		$dispatcher->sanitize_headers( json_decode( self::SSRF_HEADERS, true ) );
		$this->assertSame( [], Logger::lines() );
	}

	/**
	 * F4: `bfag_webhook_request_args` is the one place a credential header
	 * is accepted — and it is trusted for credentials only. Transport
	 * headers it plants are sanitised again after the filter.
	 */
	public function test_request_args_filter_may_set_authorization_but_its_transport_headers_are_stripped(): void {
		$this->enable_logging();
		$dispatcher = new WebhookDispatcher( new DeliveryRepository( new Settings() ), new Settings() );
		add_filter(
			'bfag_webhook_request_args',
			static function ( array $args, array $webhook ): array {
				$args['headers']['Authorization']   = 'Bearer preset-' . $webhook['id'];
				$args['headers']['Host']            = 'evil.com';
				$args['headers']['X-Forwarded-For'] = '127.0.0.1';
				$args['headers']['X-Split']         = "a\r\nInjected: 1";
				$args['headers']['X-Preset']        = 'kept';
				return $args;
			},
			10,
			2
		);
		$dispatcher->dispatch( $this->webhook( [ 'headers' => '{"Authorization":"Bearer from-row"}' ] ), 'post.published', [] );
		$headers = $this->sent_headers_lower();
		$this->assertSame( 'Bearer preset-7', $headers['authorization'], 'the filter wins; the row cannot supply Authorization' );
		$this->assertArrayNotHasKey( 'host', $headers, 'Host planted by the filter is stripped' );
		$this->assertArrayNotHasKey( 'x-forwarded-for', $headers );
		$this->assertArrayNotHasKey( 'x-split', $headers, 'a CR/LF value is stripped' );
		$this->assertSame( 'kept', $headers['x-preset'] );
		$this->assertStringStartsWith( 'sha256=', $headers['x-bf-signature'], 'the core headers survive the second pass' );
		$this->assertSame( 'application/json', $headers['content-type'] );
		$this->assertSame( 3, count( array_filter( Logger::lines(), static fn( string $l ): bool => str_contains( $l, 'webhook header rejected' ) && ( str_contains( $l, '"Host"' ) || str_contains( $l, '"X-Forwarded-For"' ) || str_contains( $l, '"X-Split"' ) ) ) ), 'each stripped transport header is logged' );
		$this->assertFalse( has_filter( 'bfag_webhook_auth_headers' ), 'control' );
		$this->assertStringNotContainsString( 'bfag_webhook_auth_headers', (string) file_get_contents( BFAG_DIR . 'includes/Webhook/WebhookDispatcher.php' ), 'the dead seam is gone from the code' );
	}

	/**
	 * N5: a request-args filter that cannot build a usable request cancels
	 * the delivery by setting `bfag_skip`; the row is failed with that
	 * reason, nothing is sent, and the failed action fires as usual.
	 */
	public function test_request_args_filter_can_skip_the_delivery_with_a_reason(): void {
		$failed = [];
		add_action(
			'bfag_webhook_delivery_failed',
			static function ( int $id, array $webhook, int $attempt, ?int $status, bool $final ) use ( &$failed ): void {
				$failed[] = [ $id, $attempt, $status, $final ];
			},
			10,
			5
		);
		add_filter(
			'bfag_webhook_request_args',
			static function ( array $args ): array {
				$args['bfag_skip'] = 'Mailchimp preset: no email in payload';
				return $args;
			}
		);
		$this->wpdb->queue( 'insert', 1 );
		$result = $this->dispatcher()->dispatch( $this->webhook(), 'post.published', [ 'n' => 1 ] );

		$this->assertSame( [], $GLOBALS['bfag_test_http_calls'], 'nothing sent' );
		$this->assertFalse( $result['success'] );
		$this->assertNull( $result['http_status'] );
		$update = $this->wpdb->calls_to( 'update' )[0]['data'];
		$this->assertSame( 'failed', $update['status'] );
		$this->assertNull( $update['http_status'] );
		$this->assertSame( 'Mailchimp preset: no email in payload', $update['response_body'] );
		$this->assertSame( [ [ 1, 1, null, true ] ], $failed, 'bfag_webhook_delivery_failed fired once, and final: a retry cannot build the request either (D3)' );

		// Control: an empty string is not a skip.
		unset( $GLOBALS['bfag_test_hooks']['bfag_webhook_request_args'] );
		add_filter(
			'bfag_webhook_request_args',
			static function ( array $args ): array {
				$args['bfag_skip'] = '';
				return $args;
			}
		);
		$this->dispatcher()->dispatch( $this->webhook(), 'post.published', [ 'n' => 2 ] );
		$this->assertCount( 1, $GLOBALS['bfag_test_http_calls'], 'sent' );
		$this->assertArrayNotHasKey( 'bfag_skip', $this->sent_args(), 'the marker is not forwarded to wp_remote_post' );
	}

	/**
	 * F16: the dispatcher uses the repository it is given rather than
	 * building a second one.
	 */
	public function test_the_repository_handed_in_is_the_one_used(): void {
		$s          = new Settings();
		$repository = new WebhookRepository();
		$dispatcher = new WebhookDispatcher( new DeliveryRepository( $s ), $s, $repository );
		$property   = new \ReflectionProperty( $dispatcher, 'webhooks' );
		$this->assertSame( $repository, $property->getValue( $dispatcher ) );
		$this->assertInstanceOf( WebhookRepository::class, $property->getValue( new WebhookDispatcher( new DeliveryRepository( $s ), $s ) ), 'without one, it builds one' );
	}

	public function test_row_headers_cannot_override_signature_or_content_type(): void {
		$this->enable_logging();
		$dispatcher = new WebhookDispatcher( new DeliveryRepository( new Settings() ), new Settings() );
		$dispatcher->dispatch( $this->webhook( [ 'headers' => '{"X-BF-Signature":"sha256=forged","content-type":"text/plain","X-Keep":"1"}' ] ), 'post.published', [ 'k' => 'v' ] );
		$headers = $this->sent_args()['headers'];
		$this->assertSame( 'application/json', $headers['Content-Type'] );
		$this->assertArrayNotHasKey( 'content-type', $headers );
		$this->assertStringStartsWith( 'sha256=', $headers['X-BF-Signature'] );
		$this->assertNotSame( 'sha256=forged', $headers['X-BF-Signature'] );
		$this->assertSame( '1', $headers['X-Keep'] );
		$this->assertNotEmpty( array_filter( Logger::lines(), static fn( string $l ): bool => str_contains( $l, '"reason":"reserved"' ) ) );
	}

	// ------------------------------------------------------------------
	// Signature and request shape.
	// ------------------------------------------------------------------

	public function test_request_is_signed_and_shaped_per_contract(): void {
		$payload = [ 'event' => 'post.published', 'object' => [ 'id' => 5, 'title' => 'Héllo "quoted"' ] ];
		$webhook = $this->webhook();
		$result  = $this->dispatcher()->dispatch( $webhook, 'post.published', $payload );

		$call = $GLOBALS['bfag_test_http_calls'][0];
		$args = $call['args'];
		$this->assertSame( 'https://hooks.example.com/in', $call['url'] );
		$this->assertSame( 'POST', $args['method'] );
		$this->assertSame( 5, $args['timeout'], 'P6: up to twenty are sent in a row on shutdown' );
		$this->assertSame( 5, WebhookDispatcher::TIMEOUT );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertTrue( $args['reject_unsafe_urls'] );
		$this->assertSame( wp_json_encode( $payload ), $args['body'] );

		$h = $args['headers'];
		$this->assertSame( 'application/json', $h['Content-Type'] );
		$this->assertSame( 'BlueFairy-API-Gateway/' . BFAG_VERSION, $h['User-Agent'] );
		$this->assertSame( 'post.published', $h['X-BF-Event'] );
		$this->assertSame( (string) $result['delivery_id'], $h['X-BF-Delivery'] );
		$this->assertMatchesRegularExpression( '/^\d{10}$/', $h['X-BF-Timestamp'] );
		$this->assertEqualsWithDelta( time(), (int) $h['X-BF-Timestamp'], 5 );

		$expected = 'sha256=' . hash_hmac( 'sha256', $h['X-BF-Timestamp'] . '.' . $args['body'], $webhook['secret'] );
		$this->assertSame( $expected, $h['X-BF-Signature'] );
		$this->assertNotSame( 'sha256=' . hash_hmac( 'sha256', $args['body'], $webhook['secret'] ), $h['X-BF-Signature'], 'timestamp is part of the signed string' );
	}

	public function test_sign_is_hmac_sha256_over_timestamp_dot_body(): void {
		$this->assertSame( hash_hmac( 'sha256', '1700000000.{"a":1}', 'k' ), WebhookDispatcher::sign( 'k', 1700000000, '{"a":1}' ) );
		$this->assertNotSame( WebhookDispatcher::sign( 'k', 1700000000, '{"a":1}' ), WebhookDispatcher::sign( 'other', 1700000000, '{"a":1}' ) );
		$this->assertNotSame( WebhookDispatcher::sign( 'k', 1700000000, '{"a":1}' ), WebhookDispatcher::sign( 'k', 1700000001, '{"a":1}' ) );
	}

	// ------------------------------------------------------------------
	// Delivery log.
	// ------------------------------------------------------------------

	public function test_delivery_row_is_pending_before_send_and_success_after(): void {
		bfag_test_queue_http( 200, 'thanks' );
		$seen = [];
		add_filter(
			'bfag_webhook_request_args',
			function ( array $args ) use ( &$seen ): array {
				$seen['inserts_at_send'] = count( $this->wpdb->calls_to( 'insert' ) );
				$seen['updates_at_send'] = count( $this->wpdb->calls_to( 'update' ) );
				return $args;
			}
		);
		$delivered = [];
		add_action( 'bfag_webhook_delivered', static function ( int $id, array $webhook ) use ( &$delivered ): void {
			$delivered[] = [ $id, $webhook['id'] ];
		}, 10, 2 );
		add_action( 'bfag_webhook_delivery_failed', static function (): void {
			self::fail( 'failed action must not fire on 200' );
		} );

		$result = $this->dispatcher()->dispatch( $this->webhook(), 'post.published', [ 'x' => 1 ] );

		$this->assertSame( [ 'inserts_at_send' => 1, 'updates_at_send' => 0 ], $seen, 'row exists, and is not yet finalised, when the request goes out' );
		$insert = $this->wpdb->calls_to( 'insert' )[0];
		$this->assertSame( 'bfw_bfag_webhook_deliveries', $insert['table'] );
		$this->assertSame( 'pending', $insert['data']['status'] );
		$this->assertSame( 'out', $insert['data']['direction'] );
		$this->assertSame( 7, $insert['data']['webhook_id'] );
		$this->assertSame( 'post.published', $insert['data']['event'] );
		$this->assertSame( '{"x":1}', $insert['data']['payload'] );
		$this->assertSame( 1, $insert['data']['attempt'] );

		$update = $this->wpdb->calls_to( 'update' )[0];
		$this->assertSame( [ 'id' => 1 ], $update['where'] );
		$this->assertSame( 'success', $update['data']['status'] );
		$this->assertSame( 200, $update['data']['http_status'] );
		$this->assertSame( 'thanks', $update['data']['response_body'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $update['data']['delivered_at'] );
		$this->assertArrayHasKey( 'next_retry_at', $update['data'], 'PN2: a finished row is never due again' );
		$this->assertNull( $update['data']['next_retry_at'] );

		$this->assertSame( [ 'delivery_id' => 1, 'success' => true, 'http_status' => 200 ], $result );
		$this->assertSame( [ [ 1, 7 ] ], $delivered );
	}

	public function test_500_marks_failed_and_fires_failed_action_once(): void {
		bfag_test_queue_http( 500, 'boom' );
		$failed = [];
		add_action( 'bfag_webhook_delivery_failed', static function ( int $id, array $webhook, int $attempt, ?int $status, bool $final ) use ( &$failed ): void {
			$failed[] = [ $id, $webhook['id'], $attempt, $status, $final ];
		}, 10, 5 );
		add_action( 'bfag_webhook_delivered', static function (): void {
			self::fail( 'delivered action must not fire on 500' );
		} );

		$result = $this->dispatcher()->dispatch( $this->webhook(), 'post.published', [] );

		$update = $this->wpdb->calls_to( 'update' )[0]['data'];
		$this->assertSame( 'failed', $update['status'] );
		$this->assertSame( 500, $update['http_status'] );
		$this->assertSame( 'boom', $update['response_body'] );
		$this->assertNull( $update['delivered_at'] );
		$this->assertArrayHasKey( 'next_retry_at', $update, 'PN2: cleared on failure too, so the Pro scheduler starts from a clean row' );
		$this->assertNull( $update['next_retry_at'] );
		$this->assertSame( [ [ 1, 7, 1, 500, false ] ], $failed, 'fires exactly once with (delivery_id, webhook, attempt, 500, final = false)' );
		$this->assertSame( [ 'delivery_id' => 1, 'success' => false, 'http_status' => 500 ], $result );
	}

	public function test_transport_error_marks_failed_with_null_http_status(): void {
		bfag_test_queue_http_error( 'http_request_failed', 'Connection refused' );
		$failed = [];
		add_action( 'bfag_webhook_delivery_failed', static function ( int $id, array $webhook, int $attempt, ?int $status, bool $final ) use ( &$failed ): void {
			$failed[] = [ $status, $final ];
		}, 10, 5 );

		$result = $this->dispatcher()->dispatch( $this->webhook(), 'post.published', [] );

		$update = $this->wpdb->calls_to( 'update' )[0]['data'];
		$this->assertSame( 'failed', $update['status'] );
		$this->assertNull( $update['http_status'] );
		$this->assertSame( 'Connection refused', $update['response_body'] );
		$this->assertSame( [ [ null, false ] ], $failed, 'a transport error is not final: the next attempt may connect' );
		$this->assertSame( [ 'delivery_id' => 1, 'success' => false, 'http_status' => null ], $result );
	}

	public function test_3xx_and_4xx_are_failures_and_2xx_edges_are_success(): void {
		foreach ( [ 201 => true, 299 => true, 301 => false, 404 => false, 199 => false ] as $code => $ok ) {
			$this->reset();
			bfag_test_queue_http( $code );
			$this->assertSame( $ok, $this->dispatcher()->dispatch( $this->webhook(), 'e', [] )['success'], "HTTP $code" );
		}
	}

	public function test_response_body_is_truncated_to_2000_chars(): void {
		bfag_test_queue_http( 200, str_repeat( 'x', 3000 ) );
		$this->dispatcher()->dispatch( $this->webhook(), 'post.published', [] );
		$this->assertSame( 2000, strlen( $this->wpdb->calls_to( 'update' )[0]['data']['response_body'] ) );

		$this->reset();
		bfag_test_queue_http( 200, str_repeat( 'y', 1999 ) );
		$this->dispatcher()->dispatch( $this->webhook(), 'post.published', [] );
		$this->assertSame( 1999, strlen( $this->wpdb->calls_to( 'update' )[0]['data']['response_body'] ), 'shorter bodies are stored whole' );
	}

	public function test_long_payload_is_stored_truncated_but_sent_whole(): void {
		$dispatcher = $this->dispatcher( [ 'max_payload_bytes' => 1024, 'debug_logging' => true ] );
		Logger::use_settings( new Settings() );
		ini_set( 'error_log', '/dev/null' );

		$payload = [ 'blob' => str_repeat( 'z', 2000 ) ];
		$dispatcher->dispatch( $this->webhook(), 'post.published', $payload );

		$insert = $this->wpdb->calls_to( 'insert' )[0]['data'];
		$this->assertSame( 1024, strlen( $insert['payload'] ) );
		$this->assertSame( 1, $insert['truncated'] );
		$this->assertSame( wp_json_encode( $payload ), $this->sent_args()['body'], 'the wire body is the full document' );
		$this->assertNotEmpty( array_filter( Logger::lines(), static fn( string $l ): bool => str_contains( $l, 'exceeds max_payload_bytes' ) ) );

		$this->reset();
		$this->dispatcher( [ 'max_payload_bytes' => 1024 ] )->dispatch( $this->webhook(), 'post.published', [ 'ok' => 1 ] );
		$this->assertSame( 0, $this->wpdb->calls_to( 'insert' )[0]['data']['truncated'], 'a short payload is not flagged' );
	}

	public function test_invalid_or_unsafe_url_fails_without_an_http_call(): void {
		foreach ( [ 'http://localhost/hook', 'https://10.0.0.5/hook', 'not a url', '' ] as $url ) {
			$this->reset();
			$failed = [];
			add_action( 'bfag_webhook_delivery_failed', static function ( int $id, array $webhook, int $attempt, ?int $status, bool $final ) use ( &$failed ): void {
				$failed[] = [ $id, $attempt, $status, $final ];
			}, 10, 5 );
			$result = $this->dispatcher()->dispatch( $this->webhook( [ 'url' => $url ] ), 'post.published', [] );
			$this->assertCount( 0, $GLOBALS['bfag_test_http_calls'], "no request for '$url'" );
			$this->assertFalse( $result['success'] );
			$this->assertNull( $result['http_status'] );
			$update = $this->wpdb->calls_to( 'update' )[0]['data'];
			$this->assertSame( 'failed', $update['status'] );
			// D3: the log says why nothing was sent, and the action says a retry cannot help.
			$this->assertSame( 'Invalid or unsafe URL (nothing sent): ' . ( '' === $url ? '(empty)' : $url ), $update['response_body'], "body for '$url'" );
			$this->assertSame( [ [ 1, 1, null, true ] ], $failed, "final for '$url'" );
		}
	}

	/**
	 * N-05 / D3: the fifth argument of `bfag_webhook_delivery_failed` is
	 * true only for the two outcomes a retry can never change — the preset
	 * skipped the delivery, or the URL is invalid or unsafe — and false for
	 * an HTTP or transport failure, which the Pro scheduler may retry.
	 */
	public function test_delivery_failed_action_marks_skip_and_unsafe_url_final_but_not_http_or_transport_failures(): void {
		$outcomes = [];
		$listen   = static function () use ( &$outcomes ): void {
			add_action( 'bfag_webhook_delivery_failed', static function ( int $id, array $webhook, int $attempt, ?int $status, bool $final ) use ( &$outcomes ): void {
				$outcomes[] = [ 'status' => $status, 'final' => $final ];
			}, 10, 5 );
		};

		$listen();
		add_filter(
			'bfag_webhook_request_args',
			static function ( array $args ): array {
				$args['bfag_skip'] = 'preset: nothing to send';
				return $args;
			}
		);
		$this->dispatcher()->dispatch( $this->webhook(), 'post.published', [] );

		$this->reset();
		$listen();
		$this->dispatcher()->dispatch( $this->webhook( [ 'url' => 'https://127.0.0.1/hook' ] ), 'post.published', [] );

		$this->reset();
		$listen();
		bfag_test_queue_http( 503, 'later' );
		$this->dispatcher()->dispatch( $this->webhook(), 'post.published', [] );

		$this->reset();
		$listen();
		bfag_test_queue_http_error( 'http_request_failed', 'cURL error 28' );
		$this->dispatcher()->dispatch( $this->webhook(), 'post.published', [] );

		$this->assertSame(
			[
				[ 'status' => null, 'final' => true ],
				[ 'status' => null, 'final' => true ],
				[ 'status' => 503, 'final' => false ],
				[ 'status' => null, 'final' => false ],
			],
			$outcomes,
			'skip, unsafe URL, 503, transport error — in that order'
		);
		$this->assertCount( 1, $GLOBALS['bfag_test_http_calls'], 'control: only the transport-error attempt made a request after the last reset' );
	}

	public function test_request_args_filter_can_rewrite_url_and_body(): void {
		add_filter(
			'bfag_webhook_request_args',
			static function ( array $args, array $webhook, string $event, array $payload ): array {
				$args['url']  = 'https://preset.example.com/' . $event;
				$args['body'] = 'rewritten:' . $payload['n'];
				return $args;
			},
			10,
			4
		);
		$this->dispatcher()->dispatch( $this->webhook(), 'post.published', [ 'n' => 9 ] );
		$call = $GLOBALS['bfag_test_http_calls'][0];
		$this->assertSame( 'https://preset.example.com/post.published', $call['url'] );
		$this->assertSame( 'rewritten:9', $call['args']['body'] );
		$this->assertArrayNotHasKey( 'url', $call['args'] );
		$this->assertSame( '{"n":9}', $this->wpdb->calls_to( 'insert' )[0]['data']['payload'], 'the stored body is the one we signed, not the preset rewrite' );
	}

	public function test_existing_delivery_id_is_reused_not_reinserted(): void {
		$this->dispatcher()->dispatch( $this->webhook(), 'post.published', [ 'r' => 1 ], 3, 42 );
		$this->assertCount( 0, $this->wpdb->calls_to( 'insert' ) );
		$updates = $this->wpdb->calls_to( 'update' );
		$this->assertSame( [ 'status' => 'pending', 'attempt' => 3, 'next_retry_at' => null ], $updates[0]['data'], 'PN2: the due time goes with the status flip' );
		$this->assertSame( [ 'id' => 42 ], $updates[0]['where'] );
		$this->assertSame( 'success', $updates[1]['data']['status'] );
		$this->assertSame( 3, $updates[1]['data']['attempt'] );
		$this->assertSame( '42', $this->sent_args()['headers']['X-BF-Delivery'] );
	}

	public function test_dispatching_guard_is_set_during_send_and_restored_after(): void {
		$during = null;
		add_filter(
			'bfag_webhook_request_args',
			static function ( array $args ) use ( &$during ): array {
				$during = Plugin::$dispatching;
				return $args;
			}
		);
		$this->assertFalse( Plugin::$dispatching );
		$this->dispatcher()->dispatch( $this->webhook(), 'post.published', [] );
		$this->assertTrue( $during );
		$this->assertFalse( Plugin::$dispatching );

		add_filter( 'bfag_webhook_request_args', static function (): array {
			throw new \RuntimeException( 'preset exploded' );
		} );
		try {
			$this->dispatcher()->dispatch( $this->webhook(), 'post.published', [] );
			self::fail( 'exception expected' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'preset exploded', $e->getMessage() );
		}
		$this->assertFalse( Plugin::$dispatching, 'restored in finally even when the send throws' );
	}

	// ------------------------------------------------------------------
	// redeliver().
	// ------------------------------------------------------------------

	public function test_redeliver_resends_the_stored_bytes_as_the_next_attempt(): void {
		$stored = '{"object":{"id":5},"note":"exact bytes \u00e9"}';
		$this->wpdb->queue( 'get_row', [ 'id' => 11, 'webhook_id' => 7, 'direction' => 'out', 'event' => 'post.updated', 'payload' => $stored, 'truncated' => '0', 'status' => 'failed', 'attempt' => '2', 'http_status' => '500' ] );
		bfag_test_queue_http( 200 );

		$result = $this->dispatcher()->redeliver( 11, $this->webhook() );

		$args = $this->sent_args();
		$this->assertSame( $stored, $args['body'], 'byte-identical body' );
		$this->assertSame( 'post.updated', $args['headers']['X-BF-Event'] );
		$this->assertSame( '11', $args['headers']['X-BF-Delivery'] );
		$this->assertSame( 'sha256=' . hash_hmac( 'sha256', $args['headers']['X-BF-Timestamp'] . '.' . $stored, 's3cret-value' ), $args['headers']['X-BF-Signature'] );
		$updates = $this->wpdb->calls_to( 'update' );
		$this->assertSame( [ 'status' => 'pending', 'attempt' => 3, 'next_retry_at' => null ], $updates[0]['data'], 'PN2: a retrying row flipped to pending with its due time still set would be selected by the flush cron and sent twice' );
		$this->assertSame( 3, $updates[1]['data']['attempt'] );
		$this->assertSame( 'success', $updates[1]['data']['status'] );
		$this->assertCount( 0, $this->wpdb->calls_to( 'insert' ) );
		$this->assertSame( [ 'delivery_id' => 11, 'success' => true, 'http_status' => 200 ], $result );
	}

	/**
	 * P6: a queued row is sent as its own attempt (1), with its stored
	 * bytes, after its due time is cleared; the webhook comes from the row.
	 */
	public function test_dispatch_pending_sends_the_stored_bytes_as_attempt_one_after_clearing_the_due_time(): void {
		$stored = '{"object":{"id":9}}';
		wp_cache_set( WebhookRepository::CACHE_KEY, [ $this->webhook( [ 'id' => 6 ] ), $this->webhook() ], WebhookRepository::CACHE_GROUP ); // all_enabled(), warm.
		$this->wpdb->rows_by_id( 'bfag_webhooks', [ $this->webhook( [ 'id' => 6, 'url' => 'https://hooks.example.com/six' ] ), $this->webhook() ] );
		bfag_test_queue_http( 204 );

		$result = $this->dispatcher()->dispatch_pending( [ 'id' => 31, 'webhook_id' => 7, 'event' => 'post.updated', 'payload' => $stored, 'truncated' => 0, 'status' => 'pending', 'attempt' => 1, 'next_retry_at' => '2026-01-01 00:00:00' ] );

		$this->assertSame( [ 'SELECT * FROM bfw_bfag_webhooks WHERE id = 7 LIMIT 1' ], array_column( $this->wpdb->calls_to( 'get_row' ), 'sql' ), 'PN4 / WPMAP-117: picked from the cached enabled list; one find() — the credentials of the delivery sent' );
		$this->assertSame( [], $this->wpdb->calls_to( 'get_results' ), 'and the list was warm' );
		$this->assertSame( 'https://hooks.example.com/in', $GLOBALS['bfag_test_http_calls'][0]['url'], 'the row\'s webhook (id 7), not the first in the list' );
		$args = $this->sent_args();
		$this->assertSame( $stored, $args['body'] );
		$this->assertSame( '31', $args['headers']['X-BF-Delivery'] );
		$this->assertSame( 'post.updated', $args['headers']['X-BF-Event'] );
		$updates = $this->wpdb->calls_to( 'update' );
		$this->assertSame( [ 'next_retry_at' => null ], $updates[0]['data'], 'handed out once' );
		$this->assertSame( 1, $updates[1]['data']['attempt'], 'attempt 1, not a retry' );
		$this->assertSame( 'success', $updates[1]['data']['status'] );
		$this->assertCount( 0, $this->wpdb->calls_to( 'insert' ) );
		$this->assertSame( [ 'delivery_id' => 31, 'success' => true, 'http_status' => 204 ], $result );
	}

	public function test_dispatch_pending_fails_the_row_without_sending_when_the_webhook_is_gone_disabled_or_the_payload_truncated(): void {
		$dispatcher = $this->dispatcher();
		$this->enable_logging(); // After dispatcher(): that helper resets the settings option.
		$row        = [ 'id' => 32, 'webhook_id' => 7, 'event' => 'post.updated', 'payload' => '{}', 'truncated' => 0, 'status' => 'pending', 'attempt' => 1 ];

		wp_cache_set( WebhookRepository::CACHE_KEY, [], WebhookRepository::CACHE_GROUP );
		$this->assertSame( [ 'delivery_id' => 32, 'success' => false, 'http_status' => null ], $dispatcher->dispatch_pending( $row ), 'missing webhook (not in the enabled list)' );
		wp_cache_set( WebhookRepository::CACHE_KEY, [ $this->webhook( [ 'enabled' => 0 ] ) ], WebhookRepository::CACHE_GROUP );
		$this->assertFalse( $dispatcher->dispatch_pending( $row )['success'], 'a disabled row that somehow sits in the list' );
		wp_cache_set( WebhookRepository::CACHE_KEY, [ $this->webhook() ], WebhookRepository::CACHE_GROUP );
		$this->assertFalse( $dispatcher->dispatch_pending( [ 'truncated' => 1 ] + $row )['success'], 'truncated payload' );

		$this->assertSame( [], $GLOBALS['bfag_test_http_calls'], 'nothing sent' );
		foreach ( $this->wpdb->calls_to( 'update' ) as $update ) {
			$this->assertSame( [ 'status' => 'failed', 'next_retry_at' => null ], $update['data'] );
			$this->assertSame( [ 'id' => 32 ], $update['where'] );
		}
		$this->assertCount( 3, $this->wpdb->calls_to( 'update' ) );
		$this->assertCount( 3, Logger::lines() );
	}

	public function test_redeliver_refuses_a_missing_or_truncated_delivery(): void {
		$result = $this->dispatcher()->redeliver( 99, $this->webhook() );
		$this->assertSame( [ 'delivery_id' => 99, 'success' => false, 'http_status' => null ], $result );
		$this->assertCount( 0, $GLOBALS['bfag_test_http_calls'] );

		$this->wpdb->queue( 'get_row', [ 'id' => 12, 'webhook_id' => 7, 'direction' => 'out', 'event' => 'e', 'payload' => '{"cut', 'truncated' => '1', 'status' => 'failed', 'attempt' => '1' ] );
		$result = $this->dispatcher()->redeliver( 12, $this->webhook() );
		$this->assertFalse( $result['success'] );
		$this->assertCount( 0, $GLOBALS['bfag_test_http_calls'], 'truncated bytes are not the signed document' );
		$this->assertCount( 0, $this->wpdb->calls_to( 'update' ) );
	}
	// ------------------------------------------------------------------
	// WPMAP-139: a delivery whose stored copy is truncated can never be
	// resent, so its failure is final — never left `retrying` forever.
	// ------------------------------------------------------------------

	/**
	 * Before the fix the 503 of an oversized payload fired with final =
	 * false; Pro marked the row `retrying`, redeliver() refused it without
	 * changing its status, and every sweep selected it again.
	 */
	public function test_a_failed_delivery_whose_stored_copy_is_truncated_is_final(): void {
		$outcomes = [];
		$listen   = static function () use ( &$outcomes ): void {
			add_action( 'bfag_webhook_delivery_failed', static function ( int $id, array $webhook, int $attempt, ?int $status, bool $final ) use ( &$outcomes ): void {
				$outcomes[] = [ 'status' => $status, 'final' => $final ];
			}, 10, 5 );
		};
		$big = [ 'blob' => str_repeat( 'z', 2000 ) ];

		$listen();
		bfag_test_queue_http( 503, 'later' );
		$this->dispatcher( [ 'max_payload_bytes' => 1024 ] )->dispatch( $this->webhook(), 'post.published', $big );
		$this->assertSame( 1, $this->wpdb->calls_to( 'insert' )[0]['data']['truncated'], 'precondition: the stored copy is cut' );
		$this->assertSame( 'failed', $this->wpdb->calls_to( 'update' )[0]['data']['status'] );

		$this->reset();
		$listen();
		bfag_test_queue_http_error( 'http_request_failed', 'cURL error 28' );
		$this->dispatcher( [ 'max_payload_bytes' => 1024 ] )->dispatch( $this->webhook(), 'post.published', $big );

		// Controls: the same failures of a payload that fits are not final.
		$this->reset();
		$listen();
		bfag_test_queue_http( 503, 'later' );
		$this->dispatcher( [ 'max_payload_bytes' => 1024 ] )->dispatch( $this->webhook(), 'post.published', [ 'ok' => 1 ] );

		$this->reset();
		$listen();
		bfag_test_queue_http_error( 'http_request_failed', 'cURL error 28' );
		$this->dispatcher( [ 'max_payload_bytes' => 1024 ] )->dispatch( $this->webhook(), 'post.published', [ 'ok' => 1 ] );

		$this->assertSame(
			[
				[ 'status' => 503, 'final' => true ],
				[ 'status' => null, 'final' => true ],
				[ 'status' => 503, 'final' => false ],
				[ 'status' => null, 'final' => false ],
			],
			$outcomes,
			'truncated 503, truncated transport error, then the same two with a payload that fits'
		);
	}

	public function test_a_truncated_delivery_that_succeeds_fires_no_failure(): void {
		add_action( 'bfag_webhook_delivery_failed', static function (): void {
			self::fail( 'a 2xx is never a failure, truncated or not' );
		} );
		bfag_test_queue_http( 200, 'ok' );
		$result = $this->dispatcher( [ 'max_payload_bytes' => 1024 ] )->dispatch( $this->webhook(), 'post.published', [ 'blob' => str_repeat( 'z', 2000 ) ] );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'success', $this->wpdb->calls_to( 'update' )[0]['data']['status'] );
	}

	/**
	 * A truncated row a scheduler holds (`retrying`) reaching redeliver()
	 * ends `failed` with no due time, and the failure action fires once as
	 * final with the row's stored attempt and status — nothing is sent and
	 * the stored response is kept.
	 */
	public function test_redeliver_ends_a_retrying_truncated_row_as_a_final_failure(): void {
		$failed = [];
		add_action( 'bfag_webhook_delivery_failed', static function ( int $id, array $webhook, int $attempt, ?int $status, bool $final ) use ( &$failed ): void {
			$failed[] = [ $id, $webhook['id'], $attempt, $status, $final ];
		}, 10, 5 );
		$this->wpdb->queue( 'get_row', [ 'id' => 14, 'webhook_id' => 7, 'direction' => 'out', 'event' => 'e', 'payload' => '{"cut', 'truncated' => '1', 'status' => 'retrying', 'attempt' => '2', 'http_status' => '503', 'next_retry_at' => '2026-10-02 00:00:00' ] );

		$result = $this->dispatcher()->redeliver( 14, $this->webhook() );

		$this->assertSame( [ 'delivery_id' => 14, 'success' => false, 'http_status' => null ], $result );
		$this->assertCount( 0, $GLOBALS['bfag_test_http_calls'], 'nothing is sent' );
		$updates = $this->wpdb->calls_to( 'update' );
		$this->assertCount( 1, $updates );
		$this->assertSame(
			[
				'status'        => 'failed',
				'next_retry_at' => null,
			],
			$updates[0]['data'],
			'only the status and the due time change: the last response and attempt stay'
		);
		$this->assertSame( [ [ 14, 7, 2, 503, true ] ], $failed );
	}

	public function test_redeliver_leaves_a_truncated_row_that_is_already_failed_alone(): void {
		add_action( 'bfag_webhook_delivery_failed', static function (): void {
			self::fail( 'a failed row was announced when it failed; refusing it again announces nothing' );
		} );
		foreach ( [ 'failed', 'success', 'pending' ] as $status ) {
			$this->reset();
			$this->wpdb->queue( 'get_row', [ 'id' => 15, 'webhook_id' => 7, 'direction' => 'out', 'event' => 'e', 'payload' => '{"cut', 'truncated' => '1', 'status' => $status, 'attempt' => '1' ] );
			$this->assertFalse( $this->dispatcher()->redeliver( 15, $this->webhook() )['success'] );
			$this->assertCount( 0, $this->wpdb->calls_to( 'update' ), "a $status row is not rewritten" );
			$this->assertCount( 0, $GLOBALS['bfag_test_http_calls'] );
		}
	}
}
