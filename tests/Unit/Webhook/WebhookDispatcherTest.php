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

	public function test_auth_headers_filter_can_add_authorization_but_not_host(): void {
		add_filter( 'bfag_webhook_auth_headers', static fn( array $h, array $webhook ): array => [ 'Authorization' => 'Bearer preset-' . $webhook['id'], 'Host' => 'evil.com' ], 10, 2 );
		$this->dispatcher()->dispatch( $this->webhook( [ 'headers' => '{"Authorization":"Bearer from-row"}' ] ), 'post.published', [] );
		$headers = $this->sent_headers_lower();
		$this->assertSame( 'Bearer preset-7', $headers['authorization'], 'the preset wins; the row cannot supply Authorization' );
		$this->assertArrayNotHasKey( 'host', $headers );
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
		$this->assertSame( 10, $args['timeout'] );
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

		$this->assertSame( [ 'delivery_id' => 1, 'success' => true, 'http_status' => 200 ], $result );
		$this->assertSame( [ [ 1, 7 ] ], $delivered );
	}

	public function test_500_marks_failed_and_fires_failed_action_once(): void {
		bfag_test_queue_http( 500, 'boom' );
		$failed = [];
		add_action( 'bfag_webhook_delivery_failed', static function ( int $id, array $webhook, int $attempt, ?int $status ) use ( &$failed ): void {
			$failed[] = [ $id, $webhook['id'], $attempt, $status ];
		}, 10, 4 );
		add_action( 'bfag_webhook_delivered', static function (): void {
			self::fail( 'delivered action must not fire on 500' );
		} );

		$result = $this->dispatcher()->dispatch( $this->webhook(), 'post.published', [] );

		$update = $this->wpdb->calls_to( 'update' )[0]['data'];
		$this->assertSame( 'failed', $update['status'] );
		$this->assertSame( 500, $update['http_status'] );
		$this->assertSame( 'boom', $update['response_body'] );
		$this->assertNull( $update['delivered_at'] );
		$this->assertSame( [ [ 1, 7, 1, 500 ] ], $failed, 'fires exactly once with (delivery_id, webhook, attempt, 500)' );
		$this->assertSame( [ 'delivery_id' => 1, 'success' => false, 'http_status' => 500 ], $result );
	}

	public function test_transport_error_marks_failed_with_null_http_status(): void {
		bfag_test_queue_http_error( 'http_request_failed', 'Connection refused' );
		$failed = [];
		add_action( 'bfag_webhook_delivery_failed', static function ( int $id, array $webhook, int $attempt, ?int $status ) use ( &$failed ): void {
			$failed[] = $status;
		}, 10, 4 );

		$result = $this->dispatcher()->dispatch( $this->webhook(), 'post.published', [] );

		$update = $this->wpdb->calls_to( 'update' )[0]['data'];
		$this->assertSame( 'failed', $update['status'] );
		$this->assertNull( $update['http_status'] );
		$this->assertSame( 'Connection refused', $update['response_body'] );
		$this->assertSame( [ null ], $failed );
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
		$failed = 0;
		add_action( 'bfag_webhook_delivery_failed', static function () use ( &$failed ): void {
			++$failed;
		} );
		foreach ( [ 'http://localhost/hook', 'https://10.0.0.5/hook', 'not a url', '' ] as $url ) {
			$this->reset();
			$result = $this->dispatcher()->dispatch( $this->webhook( [ 'url' => $url ] ), 'post.published', [] );
			$this->assertCount( 0, $GLOBALS['bfag_test_http_calls'], "no request for '$url'" );
			$this->assertFalse( $result['success'] );
			$this->assertNull( $result['http_status'] );
			$this->assertSame( 'failed', $this->wpdb->calls_to( 'update' )[0]['data']['status'] );
		}
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
		$this->assertSame( [ 'status' => 'pending', 'attempt' => 3 ], $updates[0]['data'] );
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
		$this->wpdb->queue( 'get_row', [ 'id' => 11, 'webhook_id' => 7, 'event' => 'post.updated', 'payload' => $stored, 'truncated' => '0', 'status' => 'failed', 'attempt' => '2', 'http_status' => '500' ] );
		bfag_test_queue_http( 200 );

		$result = $this->dispatcher()->redeliver( 11, $this->webhook() );

		$args = $this->sent_args();
		$this->assertSame( $stored, $args['body'], 'byte-identical body' );
		$this->assertSame( 'post.updated', $args['headers']['X-BF-Event'] );
		$this->assertSame( '11', $args['headers']['X-BF-Delivery'] );
		$this->assertSame( 'sha256=' . hash_hmac( 'sha256', $args['headers']['X-BF-Timestamp'] . '.' . $stored, 's3cret-value' ), $args['headers']['X-BF-Signature'] );
		$updates = $this->wpdb->calls_to( 'update' );
		$this->assertSame( [ 'status' => 'pending', 'attempt' => 3 ], $updates[0]['data'] );
		$this->assertSame( 3, $updates[1]['data']['attempt'] );
		$this->assertSame( 'success', $updates[1]['data']['status'] );
		$this->assertCount( 0, $this->wpdb->calls_to( 'insert' ) );
		$this->assertSame( [ 'delivery_id' => 11, 'success' => true, 'http_status' => 200 ], $result );
	}

	public function test_redeliver_refuses_a_missing_or_truncated_delivery(): void {
		$result = $this->dispatcher()->redeliver( 99, $this->webhook() );
		$this->assertSame( [ 'delivery_id' => 99, 'success' => false, 'http_status' => null ], $result );
		$this->assertCount( 0, $GLOBALS['bfag_test_http_calls'] );

		$this->wpdb->queue( 'get_row', [ 'id' => 12, 'webhook_id' => 7, 'event' => 'e', 'payload' => '{"cut', 'truncated' => '1', 'status' => 'failed', 'attempt' => '1' ] );
		$result = $this->dispatcher()->redeliver( 12, $this->webhook() );
		$this->assertFalse( $result['success'] );
		$this->assertCount( 0, $GLOBALS['bfag_test_http_calls'], 'truncated bytes are not the signed document' );
		$this->assertCount( 0, $this->wpdb->calls_to( 'update' ) );
	}
}
