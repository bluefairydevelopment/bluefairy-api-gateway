<?php
/**
 * WPMAP-117 (review R1-12): no webhook secret, URL or header — where a Pro
 * preset keeps its credentials — enters the shared object cache; the
 * dispatcher reads them with find() for each delivery it sends.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Webhook;

use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Tests\TestCase;
use BlueFairy\ApiGateway\Webhook\DeliveryRepository;
use BlueFairy\ApiGateway\Webhook\EventBus;
use BlueFairy\ApiGateway\Webhook\EventRegistry;
use BlueFairy\ApiGateway\Webhook\WebhookDispatcher;
use BlueFairy\ApiGateway\Webhook\WebhookRepository;

final class WebhookCacheSecretsTest extends TestCase {

	private const SECRET     = 'whsec-0123456789-do-not-cache';
	private const CREDENTIAL = 'xoxb-preset-token-do-not-cache';
	private const URL        = 'https://hooks.example.com/services/T0/B0/url-token-do-not-cache';

	/**
	 * A full row as the table holds it.
	 *
	 * @param array<string, mixed> $over Overrides.
	 * @return array<string, mixed>
	 */
	private function row( array $over = [] ): array {
		return array_merge(
			[
				'id'          => '7',
				'label'       => 'Orders',
				'url'         => self::URL,
				'event'       => 'post.published',
				'secret'      => self::SECRET,
				'headers'     => (string) wp_json_encode(
					[
						'X-Api-Key'     => 'k-123',
						'preset_config' => (string) wp_json_encode( [ 'token' => self::CREDENTIAL ] ),
					]
				),
				'preset'      => '',
				'enabled'     => '1',
				'max_retries' => '3',
				'created_at'  => '2026-10-01 00:00:00',
			],
			$over
		);
	}

	private function assert_no_credentials( mixed $value, string $where ): void {
		$text = serialize( $value );
		foreach ( [ self::SECRET, self::CREDENTIAL, self::URL, 'k-123' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $text, "{$where}: {$needle}" );
		}
	}

	public function test_the_enabled_list_and_its_cache_entry_carry_no_secret_url_or_headers(): void {
		$this->wpdb->queue( 'get_results', [ $this->row() ] ); // Even were the database to answer with every column.
		$rows = ( new WebhookRepository() )->all_enabled();

		$this->assertSame( WebhookRepository::SUMMARY_COLUMNS, array_keys( $rows[0] ) );
		$this->assertSame( [ 7, 'post.published', '', 1 ], [ $rows[0]['id'], $rows[0]['event'], $rows[0]['preset'], $rows[0]['enabled'] ] );
		$this->assert_no_credentials( $rows, 'returned' );
		$cached = wp_cache_get( WebhookRepository::CACHE_KEY, WebhookRepository::CACHE_GROUP );
		$this->assertIsArray( $cached );
		$this->assert_no_credentials( $cached, 'object cache' );
		$this->assertSame(
			'SELECT id, label, event, preset, enabled, max_retries FROM bfw_bfag_webhooks WHERE enabled = 1 ORDER BY id ASC LIMIT 1000',
			$this->wpdb->calls_to( 'get_results' )[0]['sql'],
			'the credentials are not even read'
		);
	}

	/**
	 * An entry an earlier version cached (full rows) is reduced on read.
	 */
	public function test_a_full_row_left_in_the_cache_is_reduced_on_read(): void {
		wp_cache_set( WebhookRepository::CACHE_KEY, [ $this->row() ], WebhookRepository::CACHE_GROUP );
		$rows = ( new WebhookRepository() )->all_enabled();
		$this->assert_no_credentials( $rows, 'returned' );
		$this->assertSame( 7, $rows[0]['id'] );
	}

	/**
	 * Control for the instrument: the full row DOES carry every needle, so
	 * the absence assertions above can fail.
	 */
	public function test_control_find_returns_the_credentials(): void {
		$this->wpdb->rows_by_id( 'bfag_webhooks', [ $this->row() ] );
		$full = ( new WebhookRepository() )->find( 7 );
		$this->assertIsArray( $full );
		$this->assertSame( self::SECRET, $full['secret'] );
		$this->assertStringContainsString( self::CREDENTIAL, (string) $full['headers'] );
		$this->assertSame( self::URL, $full['url'] );
	}

	private function bus(): EventBus {
		$settings   = new Settings();
		$deliveries = new DeliveryRepository( $settings );
		$webhooks   = new WebhookRepository();
		return new EventBus( $webhooks, new WebhookDispatcher( $deliveries, $settings, $webhooks ), new EventRegistry(), $deliveries );
	}

	private function publish( int $id ): void {
		$post = bfag_test_add_post( [ 'ID' => $id, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'P' . $id ] );
		do_action( 'transition_post_status', 'publish', 'draft', $post );
	}

	/**
	 * End to end from the cached summary: the delivery is signed with the
	 * row's secret, sent to the row's URL with the row's headers, and the
	 * full row is read once per delivery sent.
	 */
	public function test_a_delivery_from_the_cached_summary_is_signed_with_the_rows_secret(): void {
		$this->wpdb->queue( 'get_results', [ $this->row() ] );
		$this->wpdb->rows_by_id( 'bfag_webhooks', [ $this->row() ] );
		bfag_test_queue_http( 200 );
		bfag_test_queue_http( 200 );
		$bus = $this->bus();
		$bus->register_hooks();
		$this->publish( 5 );
		$this->publish( 6 );
		$bus->flush();

		$this->assertCount( 2, $GLOBALS['bfag_test_http_calls'] );
		$call = $GLOBALS['bfag_test_http_calls'][0];
		$this->assertSame( self::URL, $call['url'] );
		$headers = $call['args']['headers'];
		$this->assertSame( 'sha256=' . WebhookDispatcher::sign( self::SECRET, (int) $headers['X-BF-Timestamp'], (string) $call['args']['body'] ), $headers['X-BF-Signature'] );
		$this->assertSame( 'k-123', $headers['X-Api-Key'] );
		$this->assertCount( 2, $this->wpdb->calls_to( 'get_row' ), 'one find() per delivery sent' );
		$this->assert_no_credentials( wp_cache_get( WebhookRepository::CACHE_KEY, WebhookRepository::CACHE_GROUP ), 'object cache after sending' );
	}

	/**
	 * Twin: a webhook disabled or deleted after the list was cached is not
	 * sent to, and leaves no delivery row.
	 */
	public function test_a_webhook_disabled_or_deleted_since_the_list_was_cached_is_not_sent_to(): void {
		foreach ( [ 'disabled' => [ $this->row( [ 'enabled' => '0' ] ) ], 'deleted' => [] ] as $case => $rows ) {
			bfag_test_reset();
			$this->wpdb = $GLOBALS['wpdb'];
			wp_cache_set( WebhookRepository::CACHE_KEY, [ $this->row() ], WebhookRepository::CACHE_GROUP );
			$this->wpdb->rows_by_id( 'bfag_webhooks', $rows );
			$bus = $this->bus();
			$bus->register_hooks();
			$this->publish( 5 );
			$bus->flush();

			$this->assertSame( [], $GLOBALS['bfag_test_http_calls'], $case );
			$this->assertSame( [], $this->wpdb->calls_to( 'insert' ), "{$case}: no delivery row" );
		}
	}

	/**
	 * A full row (the admin's Send test, a Retry) is used as given: no
	 * extra read.
	 */
	public function test_a_full_row_is_sent_without_a_find(): void {
		bfag_test_queue_http( 200 );
		$settings = new Settings();
		$result   = ( new WebhookDispatcher( new DeliveryRepository( $settings ), $settings ) )->dispatch( $this->row(), 'test', [ 'ok' => true ] );
		$this->assertTrue( $result['success'] );
		$this->assertSame( [], $this->wpdb->calls_to( 'get_row' ) );
	}
}
