<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\RateLimit;

use BlueFairy\ApiGateway\Logger;
use BlueFairy\ApiGateway\RateLimit\HitCounter;
use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Tests\SqliteWpdb;
use BlueFairy\ApiGateway\Tests\TestCase;

final class HitCounterTest extends TestCase {

	/**
	 * Swap the recorded FakeWpdb for one that executes SQL in memory.
	 */
	private function sqlite(): SqliteWpdb {
		$db              = new SqliteWpdb();
		$GLOBALS['wpdb'] = $db;
		return $db;
	}

	/**
	 * The GMT datetime in the first `hit_at > '…'` / `hit_at < '…'` clause of $sql, as a unix timestamp.
	 */
	private function clause_time( string $sql, string $operator ): int {
		$this->assertSame( 1, preg_match( "/hit_at {$operator} '(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)'/", $sql, $m ), "no GMT datetime clause in: {$sql}" );
		return (int) strtotime( $m[1] . ' UTC' );
	}

	// ------------------------------------------------------------------
	// route_hash()
	// ------------------------------------------------------------------

	public function test_route_hash_is_the_first_16_hex_chars_of_sha256(): void {
		$hash = HitCounter::route_hash( '/wp/v2/posts' );
		$this->assertSame( 16, strlen( $hash ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $hash );
		$this->assertSame( substr( hash( 'sha256', '/wp/v2/posts' ), 0, 16 ), $hash );
		$this->assertNotSame( $hash, HitCounter::route_hash( '/wp/v2/pages' ) );
		$this->assertNotSame( $hash, HitCounter::route_hash( 'wp/v2/posts' ), 'the leading slash is part of the hashed route' );
	}

	// ------------------------------------------------------------------
	// consume() — statement shape, against the recording FakeWpdb
	// ------------------------------------------------------------------

	public function test_consume_inserts_first_then_counts_with_a_gmt_window_start(): void {
		$this->wpdb->queue( 'get_var', '3' );
		$t0     = time();
		$result = ( new HitCounter() )->consume( '203.0.113.10', '/wp/v2/posts', 60, 60 );
		$t1     = time();

		$this->assertFalse( $result );
		$this->assertCount( 2, $this->wpdb->calls );
		$this->assertSame( 'insert', $this->wpdb->calls[0]['method'], 'the hit is written BEFORE it is counted (SEC6)' );
		$this->assertSame( 'get_var', $this->wpdb->calls[1]['method'] );

		$insert = $this->wpdb->calls[0];
		$this->assertSame( 'bfw_bfag_rate_limit_hits', $insert['table'] );
		$this->assertSame( '203.0.113.10', $insert['data']['subject'] );
		$this->assertSame( '/wp/v2/posts', $insert['data']['route'] );
		$this->assertSame( HitCounter::route_hash( '/wp/v2/posts' ), $insert['data']['route_hash'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $insert['data']['hit_at'] );
		$hit_at = (int) strtotime( $insert['data']['hit_at'] . ' UTC' );
		$this->assertGreaterThanOrEqual( $t0, $hit_at );
		$this->assertLessThanOrEqual( $t1, $hit_at );
		$this->assertSame( [ '%s', '%s', '%s', '%s' ], $insert['format'] );

		$sql = (string) $this->wpdb->calls[1]['sql'];
		$this->assertStringStartsWith( 'SELECT COUNT(*) FROM bfw_bfag_rate_limit_hits WHERE subject = ', $sql );
		$this->assertStringContainsString( "subject = '203.0.113.10'", $sql );
		$this->assertStringContainsString( "route_hash = '" . HitCounter::route_hash( '/wp/v2/posts' ) . "'", $sql );
		$this->assertStringNotContainsString( '/wp/v2/posts', $sql, 'the count filters on the hash, not the raw route' );
		$since = $this->clause_time( $sql, '>' );
		$this->assertGreaterThanOrEqual( $t0 - 60, $since );
		$this->assertLessThanOrEqual( $t1 - 60, $since );
	}

	public function test_consume_is_over_limit_only_when_the_count_exceeds_max(): void {
		$counter = new HitCounter();
		$this->wpdb->queue( 'get_var', '60' );
		$this->assertFalse( $counter->consume( 'a', '/r', 60, 60 ), 'count == max is the last allowed request' );
		$this->wpdb->queue( 'get_var', '61' );
		$this->assertTrue( $counter->consume( 'a', '/r', 60, 60 ), 'count > max is over the limit' );
	}

	public function test_consume_fails_open_and_logs_when_the_insert_fails(): void {
		update_option( 'bfag_settings', [ 'debug_logging' => true ] );
		Logger::use_settings( new Settings() );
		ini_set( 'error_log', '/dev/null' );
		$this->wpdb->queue( 'insert', false );
		$this->wpdb->queue( 'get_var', '999' );

		$this->assertFalse( ( new HitCounter() )->consume( 'a', '/r', 60, 1 ) );
		$this->assertCount( 0, $this->wpdb->calls_to( 'get_var' ), 'no count when nothing was written' );
		$this->assertCount( 1, Logger::lines() );
		$this->assertStringContainsString( 'hit insert failed', Logger::lines()[0] );
	}

	public function test_consume_truncates_subject_and_route_to_their_columns_but_hashes_the_full_route(): void {
		$long_subject = str_repeat( 's', 250 );
		$long_route   = '/' . str_repeat( 'r', 600 );
		( new HitCounter() )->consume( $long_subject, $long_route, 60, 60 );
		$insert = $this->wpdb->calls_to( 'insert' )[0];
		$this->assertSame( 200, strlen( $insert['data']['subject'] ) );
		$this->assertSame( 191, strlen( $insert['data']['route'] ), 'P16: the route column is capped at 191 (the admin reads it; the index is on route_hash)' );
		$this->assertSame( 191, HitCounter::ROUTE_COLUMN_LENGTH );
		$this->assertSame( HitCounter::route_hash( $long_route ), $insert['data']['route_hash'] );
		$this->assertStringContainsString( "subject = '" . substr( $long_subject, 0, 200 ) . "'", (string) $this->wpdb->calls_to( 'get_var' )[0]['sql'], 'the count uses the same truncated subject it wrote' );
	}

	// ------------------------------------------------------------------
	// consume() — the SEC6 concurrency property, against real SQL
	// ------------------------------------------------------------------

	public function test_one_hundred_consumes_near_simultaneously_let_exactly_max_through(): void {
		$db      = $this->sqlite();
		$counter = new HitCounter();
		$results = [];
		for ( $i = 0; $i < 100; $i++ ) {
			$results[] = $counter->consume( '203.0.113.10', '/wp/v2/posts', 60, 60 );
		}

		$allowed = array_keys( $results, false, true );
		$limited = array_keys( $results, true, true );
		$this->assertCount( 60, $allowed, 'exactly max requests pass' );
		$this->assertCount( 40, $limited, 'every request after max is refused' );
		$this->assertSame( range( 0, 59 ), $allowed, 'the first 60 are the ones allowed' );
		$this->assertSame( range( 60, 99 ), $limited, 'the 61st onward are refused — each count includes its own insert' );

		$this->assertCount( 100, $db->calls_to( 'insert' ), 'every request is recorded, allowed or not' );
		$this->assertSame( 100, (int) $db->pdo()->query( 'SELECT COUNT(*) FROM bfw_bfag_rate_limit_hits' )->fetchColumn(), 'independent row count' );
		$this->assertSame( 100, $counter->total_rows() );
	}

	public function test_hits_older_than_the_window_are_not_counted(): void {
		$db      = $this->sqlite();
		$counter = new HitCounter();
		for ( $i = 0; $i < 5; $i++ ) {
			$db->seed_hit( '203.0.113.10', '/wp/v2/posts', 7200 );
		}
		$db->seed_hit( '203.0.113.10', '/wp/v2/posts', 10 );

		$this->assertSame( 1, $counter->count( '203.0.113.10', '/wp/v2/posts', 60 ), 'the two-hour-old hits are outside a 60 s window' );
		$this->assertSame( 6, $counter->count( '203.0.113.10', '/wp/v2/posts', 86400 ), 'and inside a one-day window' );
		$this->assertSame( 0, $counter->count( '203.0.113.10', '/wp/v2/posts', 5 ), 'a window shorter than the newest hit counts nothing' );

		$this->assertFalse( $counter->consume( '203.0.113.10', '/wp/v2/posts', 60, 2 ), 'own hit + the recent one = 2, not over 2' );
		$this->assertTrue( $counter->consume( '203.0.113.10', '/wp/v2/posts', 86400, 2 ), 'with the old hits in the window the same limit is breached' );
	}

	public function test_subjects_and_routes_are_counted_independently(): void {
		$this->sqlite();
		$counter = new HitCounter();
		for ( $i = 0; $i < 3; $i++ ) {
			$counter->consume( 'alice', '/wp/v2/posts', 60, 100 );
		}
		$counter->consume( 'alice', '/wp/v2/pages', 60, 100 );
		$counter->consume( 'bob', '/wp/v2/posts', 60, 100 );

		$this->assertSame( 3, $counter->count( 'alice', '/wp/v2/posts', 60 ) );
		$this->assertSame( 1, $counter->count( 'alice', '/wp/v2/pages', 60 ) );
		$this->assertSame( 1, $counter->count( 'bob', '/wp/v2/posts', 60 ) );
		$this->assertSame( 0, $counter->count( 'carol', '/wp/v2/posts', 60 ) );
		$this->assertFalse( $counter->consume( 'bob', '/wp/v2/posts', 60, 2 ), "alice's hits do not count against bob" );
	}

	// ------------------------------------------------------------------
	// count()
	// ------------------------------------------------------------------

	public function test_count_queries_the_window_without_inserting(): void {
		$this->wpdb->queue( 'get_var', '7' );
		$t0 = time();
		$this->assertSame( 7, ( new HitCounter() )->count( 'a', '/r', 300 ) );
		$this->assertCount( 0, $this->wpdb->calls_to( 'insert' ) );
		$sql   = (string) $this->wpdb->calls_to( 'get_var' )[0]['sql'];
		$since = $this->clause_time( $sql, '>' );
		$this->assertGreaterThanOrEqual( $t0 - 300, $since );
		$this->assertLessThanOrEqual( time() - 300, $since );
	}

	public function test_count_is_zero_when_the_query_returns_nothing(): void {
		$this->assertSame( 0, ( new HitCounter() )->count( 'a', '/r', 60 ) );
	}

	// ------------------------------------------------------------------
	// prune()
	// ------------------------------------------------------------------

	public function test_prune_loops_in_batches_of_10000_until_a_batch_deletes_nothing(): void {
		foreach ( [ 10000, 10000, 5, 0 ] as $rows ) {
			$this->wpdb->queue( 'query', $rows );
		}
		$t0      = time();
		$deleted = ( new HitCounter() )->prune( 3600 );

		$this->assertSame( 20005, $deleted );
		$deletes = $this->wpdb->queries_matching( 'DELETE FROM bfw_bfag_rate_limit_hits' );
		$this->assertCount( 4, $deletes, 'three deleting batches plus the one that found nothing' );
		foreach ( $deletes as $sql ) {
			$this->assertStringEndsWith( 'LIMIT 10000', $sql );
			$before = $this->clause_time( $sql, '<' );
			$this->assertGreaterThanOrEqual( $t0 - 3600, $before );
			$this->assertLessThanOrEqual( time() - 3600, $before );
		}
	}

	public function test_prune_stops_after_50_iterations_even_when_rows_keep_coming(): void {
		$this->wpdb->always( 'query', 10000 );
		$deleted = ( new HitCounter() )->prune( 60 );
		$this->assertSame( 500000, $deleted );
		$this->assertCount( 50, $this->wpdb->queries_matching( 'DELETE FROM' ) );
	}

	public function test_prune_returns_zero_and_runs_one_query_when_nothing_is_old(): void {
		$this->assertSame( 0, ( new HitCounter() )->prune( 60 ) );
		$this->assertCount( 1, $this->wpdb->queries_matching( 'DELETE FROM' ) );
	}

	public function test_prune_treats_a_failed_query_as_zero_rows(): void {
		$this->wpdb->queue( 'query', false );
		$this->assertSame( 0, ( new HitCounter() )->prune( 60 ) );
		$this->assertCount( 1, $this->wpdb->queries_matching( 'DELETE FROM' ) );
	}

	public function test_prune_against_sqlite_removes_only_rows_older_than_the_window(): void {
		$db      = $this->sqlite();
		$counter = new HitCounter();
		$db->seed_hit( 'a', '/r', 7200 );
		$db->seed_hit( 'a', '/r', 7200 );
		$db->seed_hit( 'a', '/r', 30 );

		$this->assertSame( 2, $counter->prune( 3600 ) );
		$this->assertSame( 1, $counter->total_rows() );
		$this->assertSame( 1, $counter->count( 'a', '/r', 60 ), 'the recent hit survives' );
		$this->assertSame( 0, $counter->prune( 3600 ), 'nothing left to prune' );
	}

	// ------------------------------------------------------------------
	// total_rows() and rotate_if_huge()
	// ------------------------------------------------------------------

	// ------------------------------------------------------------------
	// P3: counting in the object cache when it is persistent
	// ------------------------------------------------------------------

	public function test_with_a_persistent_object_cache_consume_inserts_but_counts_with_wp_cache_incr(): void {
		wp_using_ext_object_cache( true );
		bfag_test_set_time( 1_700_000_000 );
		$counter = new HitCounter();
		$this->wpdb->queue( 'get_var', '999' ); // Must never be consumed.

		for ( $n = 1; $n <= 3; $n++ ) {
			$this->assertFalse( $counter->consume( '203.0.113.5', 'rule:1', 60, 3 ), "request {$n} is within 3" );
		}
		$this->assertTrue( $counter->consume( '203.0.113.5', 'rule:1', 60, 3 ), 'the 4th exceeds 3' );

		$this->assertCount( 4, $this->wpdb->calls_to( 'insert' ), 'every hit is still written: the admin log and the Pro cross-route count read the table' );
		$this->assertSame( [], $this->wpdb->calls_to( 'get_var' ), 'no COUNT(*)' );
		$key = HitCounter::window_key( '203.0.113.5', 'rule:1', 60 );
		$this->assertSame( 'bfag:rl:' . md5( '203.0.113.5|rule:1' ) . ':' . intdiv( time(), 60 ), $key, 'subject and bucket key separated; the window index is the real clock, as hit_at is' );
		$this->assertSame( 4, wp_cache_get( $key, 'bfag' ) );
		$this->assertFalse( $counter->consume( '203.0.113.6', 'rule:1', 60, 3 ), 'another subject has its own counter' );
		$this->assertFalse( $counter->consume( '203.0.113.5', 'rule:2', 60, 3 ), 'another rule has its own counter' );

		$this->assertSame( 1, $counter->count_in_window( 'x', 'rule:9', 0 ), 'control: a fresh key starts at 1 (a window of 0 is treated as 1 s)' );
	}

	public function test_a_failed_insert_still_fails_open_with_a_persistent_object_cache(): void {
		wp_using_ext_object_cache( true );
		$this->wpdb->queue( 'insert', false );
		$this->assertFalse( ( new HitCounter() )->consume( 's', 'rule:1', 60, 0 ) );
		$this->assertFalse( wp_cache_get( HitCounter::window_key( 's', 'rule:1', 60 ), 'bfag' ), 'nothing counted' );
	}

	public function test_without_a_persistent_object_cache_consume_still_counts_in_the_table(): void {
		$this->assertNull( wp_using_ext_object_cache() );
		$this->wpdb->queue( 'get_var', '4' );
		$this->assertTrue( ( new HitCounter() )->consume( '203.0.113.5', 'rule:1', 60, 3 ) );
		$this->assertCount( 1, $this->wpdb->calls_to( 'get_var' ), 'the COUNT(*) is issued' );
		$this->assertSame( [], $GLOBALS['bfag_test_cache'], 'no counter: a per-request object cache would forget it' );
	}

	public function test_total_rows_counts_the_whole_table(): void {
		$this->wpdb->queue( 'get_var', '12345' );
		$this->assertSame( 12345, ( new HitCounter() )->total_rows() );
		$this->assertSame( 'SELECT COUNT(*) FROM bfw_bfag_rate_limit_hits', $this->wpdb->calls_to( 'get_var' )[0]['sql'] );
	}

	/**
	 * P17 / WPMAP-108: above the threshold the OLDEST rows are deleted by id
	 * range, in slices, so the newest $threshold remain — never a TRUNCATE
	 * (an exclusive metadata lock that stalls every INSERT and forgives every
	 * subject's window at once).
	 */
	public function test_rotate_if_huge_deletes_the_oldest_rows_only_above_the_threshold(): void {
		update_option( 'bfag_settings', [ 'debug_logging' => true ] );
		Logger::use_settings( new Settings() );
		ini_set( 'error_log', '/dev/null' );
		$counter = new HitCounter();

		$this->wpdb->queue( 'get_var', '10' );
		$this->assertFalse( $counter->rotate_if_huge( 10 ), 'equal to the threshold is not above it' );
		$this->assertSame( [], $this->wpdb->calls_to( 'query' ), 'nothing deleted' );
		$this->assertSame( [], Logger::lines() );
		$this->assertSame( [], $this->wpdb->queries_matching( 'COUNT(*)' ), 'P10: no COUNT(*)' );
		$this->assertSame( [], $this->wpdb->queries_matching( 'SHOW TABLE STATUS' ), 'PN9: not the engine estimate either (a day stale on MySQL 8)' );
		$this->assertSame( [ 'SELECT COALESCE(MAX(id) - MIN(id) + 1, 0) FROM bfw_bfag_rate_limit_hits' ], array_column( $this->wpdb->calls_to( 'get_var' ), 'sql' ), 'the id-range bound: two index ends' );

		$this->wpdb->calls = [];
		$this->wpdb->queue( 'get_var', '11' );  // The bound.
		$this->wpdb->queue( 'get_var', '500' ); // MAX(id).
		$this->wpdb->queue( 'query', 1 );
		$this->assertTrue( $counter->rotate_if_huge( 10 ) );
		$this->assertSame( [ 'DELETE FROM bfw_bfag_rate_limit_hits WHERE id < 491 LIMIT 10000' ], array_column( $this->wpdb->calls_to( 'query' ), 'sql' ), 'ids 491..500 — the newest ten — stay' );
		$this->assertSame( [], $this->wpdb->queries_matching( 'TRUNCATE' ) );
		$this->assertCount( 1, Logger::lines() );
		$this->assertStringContainsString( '"rows":11,"threshold":10,"deleted":1', Logger::lines()[0] );
	}

	public function test_rotate_if_huge_deletes_in_slices_until_a_short_one_and_at_most_the_iteration_cap(): void {
		$counter = new HitCounter();
		$this->wpdb->queue( 'get_var', '99999999' );
		$this->wpdb->queue( 'get_var', '99999999' );
		$this->wpdb->queue( 'query', HitCounter::PRUNE_BATCH );
		$this->wpdb->queue( 'query', HitCounter::PRUNE_BATCH );
		$this->wpdb->queue( 'query', 3 );
		$this->assertTrue( $counter->rotate_if_huge() );
		$this->assertCount( 3, $this->wpdb->calls_to( 'query' ), 'a short slice ends the run' );

		$this->wpdb->calls = [];
		$this->wpdb->queue( 'get_var', '99999999' );
		$this->wpdb->queue( 'get_var', '99999999' );
		$this->wpdb->always( 'query', HitCounter::PRUNE_BATCH );
		$this->assertTrue( $counter->rotate_if_huge() );
		$this->assertCount( HitCounter::PRUNE_MAX_ITERATIONS, $this->wpdb->calls_to( 'query' ), 'bounded per run; the next run carries on' );
	}

	public function test_rotate_if_huge_reports_false_when_nothing_could_be_deleted(): void {
		$counter = new HitCounter();
		$this->wpdb->queue( 'get_var', '11' );
		$this->wpdb->queue( 'get_var', null ); // MAX(id) unanswerable.
		$this->assertFalse( $counter->rotate_if_huge( 10 ) );
		$this->assertSame( [], $this->wpdb->calls_to( 'query' ) );

		$this->wpdb->queue( 'get_var', '11' );
		$this->wpdb->queue( 'get_var', '500' );
		$this->wpdb->queue( 'query', false ); // The DELETE failed.
		$this->assertFalse( $counter->rotate_if_huge( 10 ) );
	}

	public function test_rotate_if_huge_default_threshold_is_ten_million(): void {
		$this->assertSame( 10000000, HitCounter::ROTATE_THRESHOLD );
		$this->wpdb->queue( 'get_var', '10000000' );
		$this->assertFalse( ( new HitCounter() )->rotate_if_huge() );
		$this->wpdb->queue( 'get_var', '10000001' );
		$this->wpdb->queue( 'get_var', '10000001' );
		$this->wpdb->queue( 'query', 1 );
		$this->assertTrue( ( new HitCounter() )->rotate_if_huge() );
		$this->assertSame( [ 'DELETE FROM bfw_bfag_rate_limit_hits WHERE id < 2 LIMIT 10000' ], array_column( $this->wpdb->calls_to( 'query' ), 'sql' ) );
		$this->assertSame( 0, ( new HitCounter() )->approximate_rows(), 'no answer (a table that does not exist): zero, never a delete' );
		$this->wpdb->queue( 'get_var', '-3' );
		$this->assertSame( 0, ( new HitCounter() )->approximate_rows(), 'never negative' );
	}

	public function test_rotate_if_huge_against_sqlite_keeps_the_newest_rows(): void {
		$db      = $this->sqlite();
		$counter = new HitCounter();
		foreach ( [ 'a', 'b', 'c', 'd', 'e' ] as $subject ) {
			$db->seed_hit( $subject, '/r', 0 );
		}
		$this->assertSame( 5, $counter->approximate_rows(), 'MAX(id) - MIN(id) + 1 over a real table' );
		$this->assertFalse( $counter->rotate_if_huge( 5 ) );
		$this->assertSame( 5, $counter->total_rows() );

		$this->assertTrue( $counter->rotate_if_huge( 2 ) );
		$this->assertSame( 2, $counter->total_rows() );
		$this->assertSame( [ 'd', 'e' ], $db->pdo()->query( 'SELECT subject FROM bfw_bfag_rate_limit_hits ORDER BY id' )->fetchAll( \PDO::FETCH_COLUMN ), 'the two newest hits remain; the oldest went' );
		$this->assertSame( 2, $counter->approximate_rows(), 'oldest-first deletes leave no hole in the id range' );
		$this->assertSame( [], $db->queries_matching( 'TRUNCATE' ) );
		$this->assertSame( 1, $counter->count( 'e', '/r', 60 ), 'a newest subject keeps its window' );

		// After a prune of the oldest rows the bound is exact too.
		$db->seed_hit( 'f', '/r', 7200 );
		$counter->prune( 3600 );
		$this->assertSame( 2, $counter->total_rows() );
	}
}
