<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Webhook;

use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Tests\TestCase;
use BlueFairy\ApiGateway\Webhook\DeliveryRepository;

final class DeliveryRepositoryTest extends TestCase {

	/**
	 * Mid-test reset: bfag_test_reset() swaps $GLOBALS['wpdb'], so re-bind ours.
	 */
	private function reset(): void {
		bfag_test_reset();
		$this->wpdb = $GLOBALS['wpdb'];
	}

	private function repo( array $settings = [] ): DeliveryRepository {
		update_option( 'bfag_settings', $settings );
		return new DeliveryRepository( new Settings() );
	}

	// ------------------------------------------------------------------
	// create()
	// ------------------------------------------------------------------

	public function test_create_applies_defaults_and_returns_the_id(): void {
		$id = $this->repo()->create( [ 'webhook_id' => '4', 'event' => 'post.published', 'payload' => '{"a":1}' ] );
		$this->assertSame( 1, $id );
		$insert = $this->wpdb->calls_to( 'insert' )[0];
		$this->assertSame( 'bfw_bfag_webhook_deliveries', $insert['table'] );
		$this->assertSame( 4, $insert['data']['webhook_id'] );
		$this->assertSame( 'out', $insert['data']['direction'] );
		$this->assertSame( 'pending', $insert['data']['status'] );
		$this->assertSame( '{"a":1}', $insert['data']['payload'] );
		$this->assertSame( 0, $insert['data']['truncated'] );
		$this->assertNull( $insert['data']['http_status'] );
		$this->assertNull( $insert['data']['response_body'] );
		$this->assertSame( 1, $insert['data']['attempt'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $insert['data']['created_at'] );
		$this->assertSame( [ '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%s' ], $insert['format'] );
	}

	public function test_create_truncates_the_payload_at_max_payload_bytes(): void {
		$repo = $this->repo( [ 'max_payload_bytes' => 1024 ] );
		$repo->create( [ 'webhook_id' => 1, 'event' => 'e', 'payload' => str_repeat( 'x', 1025 ) ] );
		$repo->create( [ 'webhook_id' => 1, 'event' => 'e', 'payload' => str_repeat( 'x', 1024 ) ] );
		$inserts = $this->wpdb->calls_to( 'insert' );
		$this->assertSame( 1024, strlen( $inserts[0]['data']['payload'] ) );
		$this->assertSame( 1, $inserts[0]['data']['truncated'] );
		$this->assertSame( 1024, strlen( $inserts[1]['data']['payload'] ) );
		$this->assertSame( 0, $inserts[1]['data']['truncated'], 'exactly at the limit is not truncated' );
	}

	public function test_create_coerces_direction_status_and_optional_columns(): void {
		$repo = $this->repo();
		$repo->create( [ 'webhook_id' => 1, 'event' => 'e', 'payload' => '{}', 'direction' => 'in', 'status' => 'success', 'http_status' => '200', 'response_body' => str_repeat( 'r', 2500 ), 'attempt' => 2 ] );
		$repo->create( [ 'webhook_id' => 1, 'event' => str_repeat( 'e', 150 ), 'payload' => '{}', 'direction' => 'sideways', 'status' => 'bogus', 'attempt' => 0 ] );
		$inserts = $this->wpdb->calls_to( 'insert' );
		$this->assertSame( 'in', $inserts[0]['data']['direction'] );
		$this->assertSame( 'success', $inserts[0]['data']['status'] );
		$this->assertSame( 200, $inserts[0]['data']['http_status'] );
		$this->assertSame( 2000, strlen( $inserts[0]['data']['response_body'] ) );
		$this->assertSame( 2, $inserts[0]['data']['attempt'] );
		$this->assertSame( 'out', $inserts[1]['data']['direction'] );
		$this->assertSame( 'pending', $inserts[1]['data']['status'] );
		$this->assertSame( 100, strlen( $inserts[1]['data']['event'] ) );
		$this->assertSame( 1, $inserts[1]['data']['attempt'] );
	}

	public function test_create_returns_false_when_the_insert_fails(): void {
		$this->wpdb->queue( 'insert', false );
		$this->assertFalse( $this->repo()->create( [ 'webhook_id' => 1, 'event' => 'e', 'payload' => '{}' ] ) );
	}

	// ------------------------------------------------------------------
	// update(), find()
	// ------------------------------------------------------------------

	public function test_update_writes_allowed_columns_and_refuses_bad_input(): void {
		$repo = $this->repo();
		$this->assertTrue( $repo->update( 5, [ 'status' => 'failed', 'http_status' => '503', 'response_body' => str_repeat( 'b', 2100 ), 'delivered_at' => null, 'attempt' => 2, 'id' => 999, 'webhook_id' => 1 ] ) );
		$call = $this->wpdb->calls_to( 'update' )[0];
		$this->assertSame( 'bfw_bfag_webhook_deliveries', $call['table'] );
		$this->assertSame( [ 'id' => 5 ], $call['where'] );
		$this->assertSame( [ 'status', 'http_status', 'response_body', 'attempt', 'delivered_at' ], array_keys( $call['data'] ), 'id and webhook_id are not updatable' );
		$this->assertSame( 503, $call['data']['http_status'] );
		$this->assertSame( 2000, strlen( $call['data']['response_body'] ) );
		$this->assertNull( $call['data']['delivered_at'] );

		$this->assertFalse( $repo->update( 5, [ 'status' => 'exploded' ] ) );
		$this->assertFalse( $repo->update( 5, [ 'id' => 1 ] ), 'nothing updatable' );
		$this->assertFalse( $repo->update( 5, [ 'delivered_at' => [ 'array' ] ] ) );
		$this->assertFalse( $repo->update( 0, [ 'status' => 'success' ] ) );
		$this->assertCount( 1, $this->wpdb->calls_to( 'update' ), 'no update issued for refused input' );

		$this->assertTrue( $repo->update( 5, [ 'response_body' => [ 'array' ] ] ), 'a non-scalar response body is coerced to empty, not refused' );
		$this->assertSame( '', $this->wpdb->calls_to( 'update' )[1]['data']['response_body'] );

		$this->wpdb->queue( 'update', false );
		$this->assertFalse( $repo->update( 5, [ 'status' => 'success' ] ) );
	}

	public function test_find_normalises_the_row_and_returns_null_when_missing(): void {
		$repo = $this->repo();
		$this->wpdb->queue( 'get_row', [ 'id' => '8', 'webhook_id' => '2', 'direction' => 'out', 'event' => 'e', 'payload' => '{"x":1}', 'truncated' => '0', 'status' => 'failed', 'http_status' => '500', 'attempt' => '2' ] );
		$row = $repo->find( 8 );
		$this->assertSame( 8, $row['id'] );
		$this->assertSame( 2, $row['webhook_id'] );
		$this->assertSame( 500, $row['http_status'] );
		$this->assertSame( 2, $row['attempt'] );
		$this->assertSame( 0, $row['truncated'] );
		$this->assertStringContainsString( 'WHERE id = 8 LIMIT 1', $this->wpdb->calls_to( 'get_row' )[0]['sql'] );

		$this->wpdb->queue( 'get_row', [ 'id' => '9', 'http_status' => null ] );
		$this->assertNull( $repo->find( 9 )['http_status'] );
		$this->assertNull( $repo->find( 10 ) );
		$this->assertNull( $repo->find( 0 ) );
		$this->assertCount( 3, $this->wpdb->calls_to( 'get_row' ) );
	}

	// ------------------------------------------------------------------
	// recent()
	// ------------------------------------------------------------------

	public function test_recent_builds_filters_into_prepared_sql(): void {
		$repo = $this->repo();
		$this->wpdb->queue( 'get_results', [ [ 'id' => '3', 'webhook_id' => '1', 'status' => 'success', 'payload' => '{}', 'attempt' => '1' ] ] );
		$rows = $repo->recent( 10, [ 'webhook_id' => 1, 'status' => 'success', 'direction' => 'in' ] );
		$this->assertSame( 3, $rows[0]['id'] );
		$sql = $this->wpdb->calls_to( 'get_results' )[0]['sql'];
		$this->assertStringContainsString( "WHERE 1 = 1 AND webhook_id = 1 AND status = 'success' AND direction = 'in' ORDER BY id DESC LIMIT 10", $sql );

		$repo->recent();
		$this->assertStringContainsString( 'WHERE 1 = 1 ORDER BY id DESC LIMIT 50', $this->wpdb->calls_to( 'get_results' )[1]['sql'] );

		$repo->recent( 9999, [ 'status' => "x' OR 1=1", 'direction' => 'up', 'webhook_id' => 0 ] );
		$sql = $this->wpdb->calls_to( 'get_results' )[2]['sql'];
		$this->assertStringContainsString( 'WHERE 1 = 1 ORDER BY id DESC LIMIT 500', $sql, 'invalid filter values are ignored; limit clamped' );
		$this->assertStringNotContainsString( 'OR 1=1', $sql );
		$this->assertSame( [], $repo->recent( 5 ), 'no rows queued' );
	}

	// ------------------------------------------------------------------
	// prune(), stats_since()
	// ------------------------------------------------------------------

	public function test_prune_loops_until_a_slice_deletes_nothing(): void {
		$repo = $this->repo();
		foreach ( [ 5000, 5000, 120, 0, 77 ] as $n ) {
			$this->wpdb->queue( 'query', $n );
		}
		$before = time();
		$this->assertSame( 10120, $repo->prune( 7 ) );
		$deletes = $this->wpdb->queries_matching( 'DELETE FROM bfw_bfag_webhook_deliveries' );
		$this->assertCount( 4, $deletes, 'stops at the first zero; the queued 77 is never reached' );
		$cutoff = gmdate( 'Y-m-d H:i', $before - 7 * DAY_IN_SECONDS );
		$this->assertStringContainsString( "WHERE status IN ('success','failed') AND created_at < '$cutoff", $deletes[0] );
		$this->assertStringEndsWith( 'LIMIT 5000', $deletes[0] );

		$this->reset();
		$this->assertSame( 0, $repo->prune( 0 ), 'nothing queued: zero rows' );
		$this->assertStringContainsString( gmdate( 'Y-m-d H:i', time() - DAY_IN_SECONDS ), $this->wpdb->queries_matching( 'DELETE' )[0], 'retention clamps to at least one day' );

		$this->reset();
		$this->wpdb->queue( 'query', false );
		$this->assertSame( 0, $repo->prune( 30 ), 'a DB error stops the loop' );
	}

	public function test_stats_since_fills_every_status_and_ignores_unknown_ones(): void {
		$repo = $this->repo();
		$this->wpdb->queue( 'get_results', [ [ 'status' => 'success', 'n' => '12' ], [ 'status' => 'failed', 'n' => '3' ], [ 'status' => 'weird', 'n' => '9' ] ] );
		$this->assertSame( [ 'success' => 12, 'failed' => 3, 'pending' => 0, 'retrying' => 0 ], $repo->stats_since( '2026-09-29 00:00:00' ) );
		$this->assertStringContainsString( "WHERE created_at >= '2026-09-29 00:00:00' GROUP BY status", $this->wpdb->calls_to( 'get_results' )[0]['sql'] );
		$this->assertSame( [ 'success' => 0, 'failed' => 0, 'pending' => 0, 'retrying' => 0 ], $repo->stats_since( '2026-09-29 00:00:00' ), 'empty result set' );
	}

	// ------------------------------------------------------------------
	// consecutive_failures(), last_status()
	// ------------------------------------------------------------------

	public function test_consecutive_failures_counts_the_newest_run_of_failures(): void {
		$repo = $this->repo();
		$this->wpdb->queue( 'get_col', [ 'failed', 'failed', 'failed', 'success', 'failed', 'failed' ] );
		$this->assertSame( 3, $repo->consecutive_failures( 4 ) );
		$sql = $this->wpdb->calls_to( 'get_col' )[0]['sql'];
		$this->assertStringContainsString( "WHERE webhook_id = 4 AND direction = 'out' AND status IN ('success','failed') ORDER BY id DESC LIMIT 100", $sql );

		$this->wpdb->queue( 'get_col', [ 'success', 'failed', 'failed' ] );
		$this->assertSame( 0, $repo->consecutive_failures( 4 ), 'a success most recent resets the count' );
		$this->wpdb->queue( 'get_col', [ 'failed' ] );
		$this->assertSame( 1, $repo->consecutive_failures( 4 ) );
		$this->assertSame( 0, $repo->consecutive_failures( 4 ), 'no rows' );
	}

	public function test_last_status(): void {
		$repo = $this->repo();
		$this->wpdb->queue( 'get_var', 'failed' );
		$this->assertSame( 'failed', $repo->last_status( 4 ) );
		$this->assertStringContainsString( "WHERE webhook_id = 4 AND direction = 'out' ORDER BY id DESC LIMIT 1", $this->wpdb->calls_to( 'get_var' )[0]['sql'] );
		$this->assertNull( $repo->last_status( 4 ) );
		$this->wpdb->queue( 'get_var', '' );
		$this->assertNull( $repo->last_status( 4 ) );
	}
}
