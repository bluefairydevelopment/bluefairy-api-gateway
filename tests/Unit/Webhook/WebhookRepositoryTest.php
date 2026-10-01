<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Webhook;

use BlueFairy\ApiGateway\Logger;
use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Tests\TestCase;
use BlueFairy\ApiGateway\Webhook\WebhookRepository;

final class WebhookRepositoryTest extends TestCase {

	private const VALID = [ 'label' => 'CRM', 'url' => 'https://hooks.example.com/in', 'event' => 'post.published' ];

	private function enable_logging(): void {
		update_option( 'bfag_settings', [ 'debug_logging' => true ] );
		Logger::use_settings( new Settings() );
		ini_set( 'error_log', '/dev/null' );
	}

	/** @return array<string, mixed> */
	private function row( int $id, string $event = 'post.published', int $enabled = 1 ): array {
		return [ 'id' => (string) $id, 'label' => 'W' . $id, 'url' => 'https://h.example.com/' . $id, 'event' => $event, 'secret' => 's', 'headers' => '{"X-A":"1"}', 'preset' => '', 'enabled' => (string) $enabled, 'max_retries' => '3', 'created_at' => '2026-09-30 00:00:00' ];
	}

	// ------------------------------------------------------------------
	// create()
	// ------------------------------------------------------------------

	public function test_create_refuses_the_fourth_webhook_until_the_limit_filter_raises_it(): void {
		$this->enable_logging();
		$repo = new WebhookRepository();
		$this->assertSame( 3, $repo->limit() );

		$this->wpdb->queue( 'get_var', '3' );
		$this->assertFalse( $repo->create( self::VALID ) );
		$this->assertCount( 0, $this->wpdb->calls_to( 'insert' ) );
		$this->assertNotEmpty( array_filter( Logger::lines(), static fn( string $l ): bool => str_contains( $l, 'webhook limit reached' ) ) );

		$this->wpdb->queue( 'get_var', '2' );
		$this->assertSame( 1, $repo->create( self::VALID ), 'the third is allowed' );

		add_filter( 'bfag_webhook_limit', static fn(): int => 10 );
		$this->assertSame( 10, $repo->limit() );
		$this->wpdb->queue( 'get_var', '3' );
		$this->assertSame( 2, $repo->create( self::VALID ) );
		$this->assertCount( 2, $this->wpdb->calls_to( 'insert' ) );
	}

	public function test_create_stores_sanitised_columns_and_generates_a_secret(): void {
		$repo = new WebhookRepository();
		$this->wpdb->queue( 'get_var', '0' );
		$id = $repo->create( [ 'label' => "  CRM <b>sync</b>\n", 'url' => 'https://hooks.example.com/in', 'event' => 'Post.Published', 'preset' => 'HubSpot!' ] );
		$this->assertSame( 1, $id );
		$insert = $this->wpdb->calls_to( 'insert' )[0];
		$this->assertSame( 'bfw_bfag_webhooks', $insert['table'] );
		$data = $insert['data'];
		$this->assertSame( 'CRM sync', $data['label'] );
		$this->assertSame( 'https://hooks.example.com/in', $data['url'] );
		$this->assertSame( 'post.published', $data['event'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $data['secret'] );
		$this->assertSame( '{}', $data['headers'] );
		$this->assertSame( 'hubspot', $data['preset'] );
		$this->assertSame( 1, $data['enabled'] );
		$this->assertSame( 3, $data['max_retries'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $data['created_at'] );
		$this->assertSame( [ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ], $insert['format'] );

		$this->wpdb->queue( 'get_var', '0' );
		$repo->create( self::VALID + [ 'secret' => 'my-own', 'enabled' => '0', 'max_retries' => 99 ] );
		$data = $this->wpdb->calls_to( 'insert' )[1]['data'];
		$this->assertSame( 'my-own', $data['secret'], 'a supplied secret is kept' );
		$this->assertSame( 0, $data['enabled'] );
		$this->assertSame( 10, $data['max_retries'], 'clamped' );
	}

	public function test_create_requires_https_unless_the_allow_http_filter_says_otherwise(): void {
		$repo = new WebhookRepository();
		$this->wpdb->always( 'get_var', '0' );
		$this->assertFalse( $repo->create( [ 'url' => 'http://hooks.example.com/in' ] + self::VALID ) );
		$this->assertCount( 0, $this->wpdb->calls_to( 'insert' ) );

		add_filter( 'bfag_webhook_allow_http', static fn(): bool => true );
		$this->assertSame( 1, $repo->create( [ 'url' => 'http://hooks.example.com/in' ] + self::VALID ) );
		$this->assertFalse( $repo->create( [ 'url' => 'ftp://hooks.example.com/in' ] + self::VALID ), 'only http is unlocked' );
	}

	public function test_create_refuses_invalid_or_unsafe_urls(): void {
		$repo = new WebhookRepository();
		$this->wpdb->always( 'get_var', '0' );
		foreach ( [ 'not a url', 'https://localhost/in', 'https://10.0.0.1/in', 'https://box.local/in', '', 'javascript:alert(1)' ] as $url ) {
			$this->assertFalse( $repo->create( [ 'url' => $url ] + self::VALID ), "'$url' must be refused" );
		}
		$this->assertCount( 0, $this->wpdb->calls_to( 'insert' ) );
		$this->assertSame( 1, $repo->create( self::VALID ), 'control: the valid URL is accepted' );
	}

	public function test_create_validates_label_event_and_headers(): void {
		$repo = new WebhookRepository();
		$this->wpdb->always( 'get_var', '0' );
		$this->assertFalse( $repo->create( [ 'label' => '' ] + self::VALID ) );
		$this->assertFalse( $repo->create( [ 'label' => str_repeat( 'a', 201 ) ] + self::VALID ) );
		$this->assertFalse( $repo->create( [ 'event' => '!!!' ] + self::VALID ) );
		$this->assertFalse( $repo->create( [ 'headers' => 'not json' ] + self::VALID ) );
		$this->assertFalse( $repo->create( [ 'headers' => '[1,2]' ] + self::VALID ), 'a list is not an object' );
		$this->assertFalse( $repo->create( [ 'headers' => '{"X-A":{"nested":1}}' ] + self::VALID ) );
		$this->assertFalse( $repo->create( [ 'headers' => [ 'X-A' => [ 'arr' ] ] ] + self::VALID ) );
		$this->assertCount( 0, $this->wpdb->calls_to( 'insert' ) );

		$this->assertSame( 1, $repo->create( [ 'headers' => [ 'X-A' => '1', 'X-B' => 2 ] ] + self::VALID ) );
		$this->assertSame( '{"X-A":"1","X-B":"2"}', $this->wpdb->calls_to( 'insert' )[0]['data']['headers'] );
		$this->assertSame( 2, $repo->create( [ 'headers' => '{"X-Json":"yes"}' ] + self::VALID ) );
		$this->assertSame( '{"X-Json":"yes"}', $this->wpdb->calls_to( 'insert' )[1]['data']['headers'] );
		$this->assertSame( 3, $repo->create( [ 'label' => str_repeat( 'a', 200 ) ] + self::VALID ) );
	}

	public function test_create_returns_false_when_the_insert_fails(): void {
		$repo = new WebhookRepository();
		$this->wpdb->queue( 'get_var', '0' );
		$this->wpdb->queue( 'insert', false );
		$this->assertFalse( $repo->create( self::VALID ) );
	}

	// ------------------------------------------------------------------
	// Reads and caching.
	// ------------------------------------------------------------------

	public function test_all_enabled_is_object_cached_and_every_write_invalidates_it(): void {
		$repo = new WebhookRepository();
		$this->wpdb->queue( 'get_results', [ $this->row( 1 ), $this->row( 2, 'post.updated' ) ] );

		$first = $repo->all_enabled();
		$this->assertCount( 2, $first );
		$this->assertSame( 1, $first[0]['id'] );
		$this->assertSame( 1, $first[0]['enabled'], 'ints are cast' );
		$this->assertSame( $first, $repo->all_enabled() );
		$this->assertCount( 1, $this->wpdb->calls_to( 'get_results' ), 'second read served from cache' );
		$this->assertStringContainsString( 'WHERE enabled = 1', $this->wpdb->calls_to( 'get_results' )[0]['sql'] );
		$this->assertStringContainsString( 'LIMIT', $this->wpdb->calls_to( 'get_results' )[0]['sql'] );
		$this->assertSame( $first, wp_cache_get( 'webhooks_enabled', 'bfag' ) );

		$writes = [
			'create'      => function () use ( $repo ): void {
				$this->wpdb->queue( 'get_var', '0' );
				$repo->create( self::VALID );
			},
			'update'      => static fn() => $repo->update( 1, [ 'label' => 'x' ] ),
			'set_enabled' => static fn() => $repo->set_enabled( 1, false ),
			'delete'      => static fn() => $repo->delete( 1 ),
		];
		foreach ( $writes as $name => $write ) {
			wp_cache_set( 'webhooks_enabled', [ 'stale' ], 'bfag' );
			$write();
			$this->assertFalse( wp_cache_get( 'webhooks_enabled', 'bfag' ), "$name invalidates" );
		}
	}

	public function test_for_event_and_enabled_events_derive_from_the_enabled_list(): void {
		$repo = new WebhookRepository();
		// The cache holds rows as all_enabled() stores them: already normalised.
		$this->wpdb->queue( 'get_results', [ $this->row( 1 ), $this->row( 2, 'post.updated' ), $this->row( 3 ) ] );
		$repo->all_enabled();
		$this->assertSame( [ 1, 3 ], array_column( $repo->for_event( 'post.published' ), 'id' ) );
		$this->assertSame( [], $repo->for_event( 'nothing.here' ) );
		$this->assertSame( [ 'post.published', 'post.updated' ], $repo->enabled_events() );
		$this->assertCount( 1, $this->wpdb->calls_to( 'get_results' ), 'for_event and enabled_events read the cache, not the DB' );
	}

	public function test_all_find_and_count(): void {
		$repo = new WebhookRepository();
		$this->wpdb->queue( 'get_results', [ $this->row( 1 ), $this->row( 2, 'x', 0 ) ] );
		$all = $repo->all( 5 );
		$this->assertSame( [ 1, 2 ], array_column( $all, 'id' ) );
		$this->assertSame( 0, $all[1]['enabled'] );
		$this->assertStringContainsString( 'LIMIT 5', $this->wpdb->calls_to( 'get_results' )[0]['sql'] );
		$repo->all( 5000 );
		$this->assertStringContainsString( 'LIMIT 1000', $this->wpdb->calls_to( 'get_results' )[1]['sql'], 'clamped' );

		$this->wpdb->queue( 'get_row', $this->row( 4 ) );
		$found = $repo->find( 4 );
		$this->assertSame( 4, $found['id'] );
		$this->assertSame( 3, $found['max_retries'] );
		$this->assertStringContainsString( 'WHERE id = 4 LIMIT 1', $this->wpdb->calls_to( 'get_row' )[0]['sql'] );
		$this->assertNull( $repo->find( 5 ), 'no row queued' );
		$this->assertNull( $repo->find( 0 ) );
		$this->assertCount( 2, $this->wpdb->calls_to( 'get_row' ), 'find(0) does not query' );

		$this->wpdb->queue( 'get_var', '7' );
		$this->assertSame( 7, $repo->count() );
		$this->assertSame( 0, $repo->count(), 'null from the DB counts as zero' );
	}

	// ------------------------------------------------------------------
	// update(), set_enabled(), delete()
	// ------------------------------------------------------------------

	public function test_update_touches_only_supplied_columns_and_refuses_invalid_values(): void {
		$repo = new WebhookRepository();
		$this->assertTrue( $repo->update( 3, [ 'label' => 'New', 'headers' => [ 'X-A' => 'b' ], 'secret' => '' ] ) );
		$call = $this->wpdb->calls_to( 'update' )[0];
		$this->assertSame( 'bfw_bfag_webhooks', $call['table'] );
		$this->assertSame( [ 'label' => 'New', 'headers' => '{"X-A":"b"}' ], $call['data'], 'an empty secret on update means "keep"' );
		$this->assertSame( [ 'id' => 3 ], $call['where'] );

		$this->assertFalse( $repo->update( 3, [ 'url' => 'http://insecure.example.com' ] ) );
		$this->assertFalse( $repo->update( 3, [] ) );
		$this->assertFalse( $repo->update( 3, [ 'unknown' => 1 ] ) );
		$this->assertFalse( $repo->update( 0, [ 'label' => 'x' ] ) );
		$this->assertCount( 1, $this->wpdb->calls_to( 'update' ), 'no update issued for refused input' );

		$this->wpdb->queue( 'update', false );
		$this->assertFalse( $repo->update( 3, [ 'label' => 'y' ] ), 'DB failure propagates' );
	}

	public function test_set_enabled_writes_the_flag(): void {
		$repo = new WebhookRepository();
		$this->assertTrue( $repo->set_enabled( 2, false ) );
		$this->assertTrue( $repo->set_enabled( 2, true ) );
		$updates = $this->wpdb->calls_to( 'update' );
		$this->assertSame( [ 'enabled' => 0 ], $updates[0]['data'] );
		$this->assertSame( [ 'enabled' => 1 ], $updates[1]['data'] );
		$this->assertSame( [ 'id' => 2 ], $updates[1]['where'] );
	}

	public function test_delete_removes_deliveries_then_the_webhook_inside_a_transaction(): void {
		$repo = new WebhookRepository();
		$this->assertTrue( $repo->delete( 9 ) );

		$ops = array_map(
			static fn( array $c ): string => $c['method'] . ':' . ( $c['sql'] ?? $c['table'] ),
			array_values( array_filter( $this->wpdb->calls, static fn( array $c ): bool => in_array( $c['method'], [ 'query', 'delete' ], true ) ) )
		);
		$this->assertSame(
			[
				'query:START TRANSACTION',
				'query:DELETE FROM bfw_bfag_webhook_deliveries WHERE webhook_id = 9',
				'delete:bfw_bfag_webhooks',
				'query:COMMIT',
			],
			$ops
		);
		$this->assertSame( [ 'id' => 9 ], $this->wpdb->calls_to( 'delete' )[0]['where'] );
		$this->assertSame( [], $this->wpdb->queries_matching( 'ROLLBACK' ) );
	}

	public function test_delete_rolls_back_when_the_webhook_delete_fails(): void {
		$this->enable_logging();
		$repo = new WebhookRepository();
		$this->wpdb->queue( 'query', 1 ); // START TRANSACTION.
		$this->wpdb->queue( 'query', 2 ); // deliveries deleted.
		$this->wpdb->queue( 'delete', false );
		$this->assertFalse( $repo->delete( 9 ) );
		$this->assertCount( 1, $this->wpdb->queries_matching( 'ROLLBACK' ) );
		$this->assertSame( [], $this->wpdb->queries_matching( 'COMMIT' ) );
		$this->assertNotEmpty( array_filter( Logger::lines(), static fn( string $l ): bool => str_contains( $l, 'rolled back' ) ) );

		$this->assertFalse( $repo->delete( 0 ) );
		$this->assertCount( 1, $this->wpdb->queries_matching( 'START TRANSACTION' ), 'delete(0) opens no transaction' );
	}

	// ------------------------------------------------------------------
	// headers_of()
	// ------------------------------------------------------------------

	public function test_headers_of_decodes_json_and_returns_empty_on_garbage(): void {
		$repo = new WebhookRepository();
		$this->assertSame( [ 'X-A' => '1', 'X-B' => '2' ], $repo->headers_of( [ 'headers' => '{"X-A":"1","X-B":2}' ] ) );
		$this->assertSame( [], $repo->headers_of( [ 'headers' => 'garbage{' ] ) );
		$this->assertSame( [], $repo->headers_of( [ 'headers' => null ] ) );
		$this->assertSame( [], $repo->headers_of( [] ) );
		$this->assertSame( [], $repo->headers_of( [ 'headers' => '"just a string"' ] ) );
		$this->assertSame( [], $repo->headers_of( [ 'headers' => '[1,2]' ] ), 'list keys are not header names' );
		$this->assertSame( [ 'X-A' => 'x' ], $repo->headers_of( [ 'headers' => '{"X-A":"x","X-Obj":{"a":1},"":"empty"}' ] ) );
		$this->assertSame( [ 'X-Arr' => 'v' ], $repo->headers_of( [ 'headers' => [ 'X-Arr' => 'v' ] ] ), 'already-decoded arrays pass through' );
	}

	public function test_create_refuses_userinfo_and_ports_outside_the_safe_list_as_core_does(): void {
		$repo = new WebhookRepository();
		$this->wpdb->always( 'get_var', '0' );
		$this->assertFalse( $repo->create( [ 'url' => 'https://hooks.example.com:8443/in' ] + self::VALID ), 'a port outside http_allowed_safe_ports (80, 443, 8080)' );
		$this->assertFalse( $repo->create( [ 'url' => 'https://user:p4ss@hooks.example.com/in' ] + self::VALID ), 'userinfo' );
		$this->assertCount( 0, $this->wpdb->calls_to( 'insert' ) );

		$this->assertSame( 1, $repo->create( [ 'url' => 'https://hooks.example.com:8080/in' ] + self::VALID ), 'control: 8080 is a safe port' );
		$this->assertSame( 'https://hooks.example.com:8080/in', $this->wpdb->calls_to( 'insert' )[0]['data']['url'] );
	}
}
