<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\RateLimit;

use BlueFairy\ApiGateway\Logger;
use BlueFairy\ApiGateway\RateLimit\RateLimitRuleRepository;
use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Tests\TestCase;

final class RateLimitRuleRepositoryTest extends TestCase {

	/**
	 * A raw row as $wpdb would return it (strings everywhere).
	 *
	 * @param array<string, mixed> $overrides Column overrides.
	 * @return array<string, string>
	 */
	private function row( array $overrides = [] ): array {
		return array_merge(
			[
				'id'              => '1',
				'label'           => 'Global',
				'subject_type'    => 'ip',
				'route_glob'      => '**',
				'max_requests'    => '60',
				'window_seconds'  => '60',
				'penalty_seconds' => '0',
				'enabled'         => '1',
				'created_at'      => '2026-09-30 00:00:00',
			],
			array_map( 'strval', $overrides )
		);
	}

	private function enable_logging(): void {
		update_option( 'bfag_settings', [ 'debug_logging' => true ] );
		Logger::use_settings( new Settings() );
		ini_set( 'error_log', '/dev/null' );
	}

	// ------------------------------------------------------------------
	// all_enabled()
	// ------------------------------------------------------------------

	public function test_all_enabled_queries_once_then_serves_the_object_cache(): void {
		$this->wpdb->queue( 'get_results', [ $this->row() ] );
		$repo = new RateLimitRuleRepository();

		$first = $repo->all_enabled();
		$this->assertCount( 1, $first );
		$this->assertSame( 1, $first[0]['id'] );
		$this->assertCount( 1, $this->wpdb->calls_to( 'get_results' ) );
		$sql = (string) $this->wpdb->calls_to( 'get_results' )[0]['sql'];
		$this->assertSame( 'SELECT * FROM bfw_bfag_rate_limit_rules WHERE enabled = 1 ORDER BY id ASC LIMIT 200', $sql );

		$this->assertSame( $first, wp_cache_get( 'rl_rules_enabled', 'bfag' ) );
		$this->assertSame( $first, $repo->all_enabled() );
		$this->assertCount( 1, $this->wpdb->calls_to( 'get_results' ), 'second call served from cache' );

		wp_cache_delete( 'rl_rules_enabled', 'bfag' );
		$this->wpdb->queue( 'get_results', [] );
		$this->assertSame( [], $repo->all_enabled(), 'a cold cache re-reads the table' );
		$this->assertCount( 2, $this->wpdb->calls_to( 'get_results' ) );
	}

	public function test_all_enabled_casts_integer_columns_and_accepts_objects(): void {
		$this->wpdb->queue( 'get_results', [ (object) $this->row( [ 'max_requests' => 100, 'window_seconds' => 300, 'penalty_seconds' => 900, 'enabled' => 1, 'id' => 7 ] ) ] );
		$rule = ( new RateLimitRuleRepository() )->all_enabled()[0];
		$this->assertSame( 7, $rule['id'] );
		$this->assertSame( 100, $rule['max_requests'] );
		$this->assertSame( 300, $rule['window_seconds'] );
		$this->assertSame( 900, $rule['penalty_seconds'] );
		$this->assertSame( 1, $rule['enabled'] );
		$this->assertSame( 'ip', $rule['subject_type'] );
		$this->assertSame( '**', $rule['route_glob'] );
	}

	// ------------------------------------------------------------------
	// all(), find(), count()
	// ------------------------------------------------------------------

	public function test_all_lists_every_rule_with_a_limit_and_bypasses_the_cache(): void {
		wp_cache_set( 'rl_rules_enabled', [ $this->row() ], 'bfag' );
		$this->wpdb->queue( 'get_results', [ $this->row(), $this->row( [ 'id' => 2, 'enabled' => 0 ] ) ] );
		$rules = ( new RateLimitRuleRepository() )->all( 50 );
		$this->assertCount( 2, $rules );
		$this->assertSame( 0, $rules[1]['enabled'] );
		$this->assertSame( 'SELECT * FROM bfw_bfag_rate_limit_rules ORDER BY id ASC LIMIT 50', $this->wpdb->calls_to( 'get_results' )[0]['sql'] );

		// T10: a queued, non-empty answer — `assertSame( [], all() )` against an empty queue could never fail.
		$this->wpdb->queue( 'get_results', [ $this->row( [ 'id' => 3 ] ) ] );
		$default = ( new RateLimitRuleRepository() )->all();
		$this->assertCount( 1, $default );
		$this->assertSame( 3, $default[0]['id'] );
		$this->assertStringEndsWith( 'LIMIT 200', (string) $this->wpdb->calls_to( 'get_results' )[1]['sql'] );
	}

	public function test_find_returns_the_normalised_row_or_null(): void {
		$this->wpdb->queue( 'get_row', $this->row( [ 'id' => 4 ] ) );
		$repo  = new RateLimitRuleRepository();
		$found = $repo->find( 4 );
		$this->assertNotNull( $found );
		$this->assertSame( 4, $found['id'] );
		$this->assertSame( 60, $found['max_requests'] );
		$this->assertSame( 'SELECT * FROM bfw_bfag_rate_limit_rules WHERE id = 4 LIMIT 1', $this->wpdb->calls_to( 'get_row' )[0]['sql'] );

		$this->assertNull( $repo->find( 99 ) );
	}

	public function test_count_reads_count_star(): void {
		$this->wpdb->queue( 'get_var', '3' );
		$this->assertSame( 3, ( new RateLimitRuleRepository() )->count() );
		$this->assertSame( 'SELECT COUNT(*) FROM bfw_bfag_rate_limit_rules', $this->wpdb->calls_to( 'get_var' )[0]['sql'] );
		$this->assertSame( 0, ( new RateLimitRuleRepository() )->count() );
	}

	// ------------------------------------------------------------------
	// create()
	// ------------------------------------------------------------------

	public function test_create_inserts_with_defaults_formats_and_invalidates_the_cache(): void {
		wp_cache_set( 'rl_rules_enabled', [ $this->row() ], 'bfag' );
		$this->wpdb->queue( 'get_var', '0' );
		$id = ( new RateLimitRuleRepository() )->create( [ 'label' => ' Posts ', 'route_glob' => 'wp/v2/posts/*', 'max_requests' => '30' ] );

		$this->assertSame( 1, $id );
		$inserts = $this->wpdb->calls_to( 'insert' );
		$this->assertCount( 1, $inserts );
		$this->assertSame( 'bfw_bfag_rate_limit_rules', $inserts[0]['table'] );
		$this->assertSame(
			[
				'label'           => 'Posts',
				'subject_type'    => 'ip',
				'route_glob'      => 'wp/v2/posts/*',
				'max_requests'    => 30,
				'window_seconds'  => 60,
				'penalty_seconds' => 0,
				'enabled'         => 1,
			],
			$inserts[0]['data']
		);
		$this->assertSame( [ '%s', '%s', '%s', '%d', '%d', '%d', '%d' ], $inserts[0]['format'] );
		$this->assertFalse( wp_cache_get( 'rl_rules_enabled', 'bfag' ), 'a write forgets the cached list' );
	}

	public function test_create_refuses_a_second_rule_under_the_lite_limit_until_the_filter_raises_it(): void {
		$this->enable_logging();
		$repo = new RateLimitRuleRepository();

		$this->wpdb->queue( 'get_var', '1' );
		$this->assertFalse( $repo->create( [ 'label' => 'Second' ] ) );
		$this->assertCount( 0, $this->wpdb->calls_to( 'insert' ) );
		$this->assertStringContainsString( 'limit reached', Logger::lines()[0] );

		add_filter( 'bfag_rate_limit_rule_limit', static fn(): int => 5 );
		$this->wpdb->queue( 'get_var', '1' );
		$this->assertSame( 1, $repo->create( [ 'label' => 'Second' ] ) );
		$this->assertCount( 1, $this->wpdb->calls_to( 'insert' ) );

		$this->wpdb->queue( 'get_var', '5' );
		$this->assertFalse( $repo->create( [ 'label' => 'Sixth' ] ), 'the raised limit still binds' );
		$this->assertCount( 1, $this->wpdb->calls_to( 'insert' ) );
	}

	public function test_create_refuses_a_subject_type_until_the_filter_adds_it(): void {
		$this->enable_logging();
		$this->wpdb->always( 'get_var', '0' );
		$repo = new RateLimitRuleRepository();

		$this->assertFalse( $repo->create( [ 'subject_type' => 'user' ] ) );
		$this->assertCount( 0, $this->wpdb->calls_to( 'insert' ) );
		$this->assertStringContainsString( 'subject type not available', Logger::lines()[0] );

		add_filter( 'bfag_rate_limit_subject_types', static fn( array $types ): array => array_merge( $types, [ 'user', 'apikey' ] ) );
		$this->assertSame( 1, $repo->create( [ 'subject_type' => 'user' ] ) );
		$this->assertSame( 'user', $this->wpdb->calls_to( 'insert' )[0]['data']['subject_type'] );
		$this->assertFalse( $repo->create( [ 'subject_type' => 'hostname' ] ), 'a type the filter did not add is still refused' );
	}

	/**
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function invalid_values(): iterable {
		yield 'max_requests 0' => [ [ 'max_requests' => 0 ] ];
		yield 'max_requests 65536' => [ [ 'max_requests' => 65536 ] ];
		yield 'max_requests text' => [ [ 'max_requests' => 'lots' ] ];
		yield 'window 0' => [ [ 'window_seconds' => 0 ] ];
		yield 'window 86401' => [ [ 'window_seconds' => 86401 ] ];
		yield 'penalty -1' => [ [ 'penalty_seconds' => -1 ] ];
		yield 'penalty 2592001' => [ [ 'penalty_seconds' => 2592001 ] ];
		yield 'empty glob' => [ [ 'route_glob' => '' ] ];
		yield 'glob with space' => [ [ 'route_glob' => 'wp/v2 posts' ] ];
		yield 'glob with regex' => [ [ 'route_glob' => 'wp/v2/(posts|pages)' ] ];
	}

	/**
	 * @dataProvider invalid_values
	 * @param array<string, mixed> $data Invalid input.
	 */
	public function test_create_rejects_out_of_range_and_invalid_values( array $data ): void {
		$this->wpdb->always( 'get_var', '0' );
		$this->assertFalse( ( new RateLimitRuleRepository() )->create( $data ) );
		$this->assertCount( 0, $this->wpdb->calls_to( 'insert' ) );
	}

	/**
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function boundary_values(): iterable {
		yield 'max_requests 1' => [ [ 'max_requests' => 1 ] ];
		yield 'max_requests 65535' => [ [ 'max_requests' => 65535 ] ];
		yield 'window 1' => [ [ 'window_seconds' => 1 ] ];
		yield 'window 86400' => [ [ 'window_seconds' => 86400 ] ];
		yield 'penalty 0' => [ [ 'penalty_seconds' => 0 ] ];
		yield 'penalty 2592000' => [ [ 'penalty_seconds' => 2592000 ] ];
		yield 'numeric string' => [ [ 'max_requests' => '120' ] ];
		yield 'glob with leading slash' => [ [ 'route_glob' => '/wc/v3/orders/**' ] ];
	}

	/**
	 * @dataProvider boundary_values
	 * @param array<string, mixed> $data Valid input at a bound.
	 */
	public function test_create_accepts_values_at_the_bounds( array $data ): void {
		$this->wpdb->always( 'get_var', '0' );
		$this->assertSame( 1, ( new RateLimitRuleRepository() )->create( $data ) );
		$this->assertCount( 1, $this->wpdb->calls_to( 'insert' ) );
	}

	public function test_create_returns_false_when_the_insert_fails(): void {
		$this->wpdb->queue( 'get_var', '0' );
		$this->wpdb->queue( 'insert', false );
		$this->assertFalse( ( new RateLimitRuleRepository() )->create( [] ) );
	}

	// ------------------------------------------------------------------
	// update(), delete(), set_enabled()
	// ------------------------------------------------------------------

	public function test_update_writes_only_known_columns_and_invalidates_the_cache(): void {
		wp_cache_set( 'rl_rules_enabled', [ $this->row() ], 'bfag' );
		$this->assertTrue( ( new RateLimitRuleRepository() )->update( 3, [ 'max_requests' => '90', 'id' => 99, 'created_at' => 'x', 'bogus' => 1 ] ) );
		$update = $this->wpdb->calls_to( 'update' )[0];
		$this->assertSame( 'bfw_bfag_rate_limit_rules', $update['table'] );
		$this->assertSame( [ 'max_requests' => 90 ], $update['data'] );
		$this->assertSame( [ 'id' => 3 ], $update['where'] );
		$this->assertFalse( wp_cache_get( 'rl_rules_enabled', 'bfag' ) );
	}

	public function test_update_refuses_invalid_values_and_empty_updates(): void {
		$repo = new RateLimitRuleRepository();
		$this->assertFalse( $repo->update( 3, [ 'window_seconds' => 0 ] ) );
		$this->assertFalse( $repo->update( 3, [ 'subject_type' => 'user' ] ) );
		$this->assertFalse( $repo->update( 3, [] ) );
		$this->assertFalse( $repo->update( 3, [ 'unknown' => 1 ] ) );
		$this->assertCount( 0, $this->wpdb->calls_to( 'update' ) );
	}

	public function test_update_returns_false_when_the_query_fails(): void {
		$this->wpdb->queue( 'update', false );
		$this->assertFalse( ( new RateLimitRuleRepository() )->update( 3, [ 'label' => 'x' ] ) );
		$this->assertCount( 1, $this->wpdb->calls_to( 'update' ) );
	}

	public function test_delete_removes_by_id_and_invalidates_the_cache(): void {
		wp_cache_set( 'rl_rules_enabled', [ $this->row() ], 'bfag' );
		$repo = new RateLimitRuleRepository();
		$this->assertTrue( $repo->delete( 3 ) );
		$this->assertSame( [ 'id' => 3 ], $this->wpdb->calls_to( 'delete' )[0]['where'] );
		$this->assertFalse( wp_cache_get( 'rl_rules_enabled', 'bfag' ) );

		$this->wpdb->queue( 'delete', 0 );
		$this->assertFalse( $repo->delete( 42 ), 'no row deleted is not success' );
		$this->wpdb->queue( 'delete', false );
		$this->assertFalse( $repo->delete( 42 ) );
	}

	public function test_set_enabled_writes_the_flag(): void {
		$repo = new RateLimitRuleRepository();
		$this->assertTrue( $repo->set_enabled( 3, false ) );
		$this->assertTrue( $repo->set_enabled( 3, true ) );
		$updates = $this->wpdb->calls_to( 'update' );
		$this->assertSame( [ 'enabled' => 0 ], $updates[0]['data'] );
		$this->assertSame( [ 'enabled' => 1 ], $updates[1]['data'] );
	}

	// ------------------------------------------------------------------
	// limit(), subject_types()
	// ------------------------------------------------------------------

	public function test_limit_is_one_until_filtered(): void {
		$repo = new RateLimitRuleRepository();
		$this->assertSame( 1, $repo->limit() );
		add_filter( 'bfag_rate_limit_rule_limit', static fn(): string => '25' );
		$this->assertSame( 25, $repo->limit() );
	}

	public function test_subject_types_default_to_ip_and_filter_output_is_cleaned(): void {
		$repo = new RateLimitRuleRepository();
		$this->assertSame( [ 'ip' ], $repo->subject_types() );
		add_filter( 'bfag_rate_limit_subject_types', static fn( array $types ): array => array_merge( $types, [ 'user', '', 7, 'user', 'apikey' ] ) );
		$this->assertSame( [ 'ip', 'user', 'apikey' ], $repo->subject_types() );
	}

	public function test_subject_types_fall_back_to_ip_when_the_filter_returns_garbage(): void {
		add_filter( 'bfag_rate_limit_subject_types', static fn(): string => 'ip,user' );
		$this->assertSame( [ 'ip' ], ( new RateLimitRuleRepository() )->subject_types() );
	}

	// ------------------------------------------------------------------
	// matching(), max_window()
	// ------------------------------------------------------------------

	public function test_matching_honours_globs_order_and_enabled(): void {
		$this->wpdb->queue(
			'get_results',
			[
				$this->row( [ 'id' => 1, 'route_glob' => 'wp/v2/posts/*' ] ),
				$this->row( [ 'id' => 2, 'route_glob' => '**' ] ),
				$this->row( [ 'id' => 3, 'route_glob' => 'wp/v2/**', 'enabled' => 0 ] ),
				$this->row( [ 'id' => 4, 'route_glob' => 'wc/v3/**' ] ),
			]
		);
		$repo = new RateLimitRuleRepository();

		$ids = array_column( $repo->matching( '/wp/v2/posts/12' ), 'id' );
		$this->assertSame( [ 1, 2 ], $ids, 'single-segment glob and catch-all match; disabled and other-namespace rules do not' );

		$this->assertSame( [ 2 ], array_column( $repo->matching( '/wp/v2/posts/12/revisions' ), 'id' ), '* does not cross a slash' );
		$this->assertSame( [ 2, 4 ], array_column( $repo->matching( '/wc/v3/orders/7' ), 'id' ) );
		$this->assertSame( [ 2 ], array_column( $repo->matching( '/bfag/v1/status' ), 'id' ) );
	}

	public function test_matching_is_empty_without_rules(): void {
		$this->assertSame( [], ( new RateLimitRuleRepository() )->matching( '/wp/v2/posts' ) );
	}

	public function test_max_window_is_the_largest_enabled_window_or_60(): void {
		$this->assertSame( 60, ( new RateLimitRuleRepository() )->max_window(), 'no rules' );

		wp_cache_delete( 'rl_rules_enabled', 'bfag' );
		$this->wpdb->queue(
			'get_results',
			[
				$this->row( [ 'id' => 1, 'window_seconds' => 30 ] ),
				$this->row( [ 'id' => 2, 'window_seconds' => 3600 ] ),
				$this->row( [ 'id' => 3, 'window_seconds' => 120 ] ),
			]
		);
		$this->assertSame( 3600, ( new RateLimitRuleRepository() )->max_window() );

		wp_cache_delete( 'rl_rules_enabled', 'bfag' );
		$this->wpdb->queue( 'get_results', [ $this->row( [ 'window_seconds' => 10 ] ) ] );
		$this->assertSame( 10, ( new RateLimitRuleRepository() )->max_window(), 'a window below 60 is reported, not floored' );
	}

	public function test_max_window_can_be_raised_but_not_zeroed_by_the_filter(): void {
		add_filter( 'bfag_rate_limit_max_window', static fn( int $max ): int => max( $max, 900 ) );
		$this->assertSame( 900, ( new RateLimitRuleRepository() )->max_window(), 'the Pro DDoS window raises it' );
		remove_filter( 'bfag_rate_limit_max_window', static fn( int $max ): int => max( $max, 900 ) );
		$GLOBALS['bfag_test_hooks']['bfag_rate_limit_max_window'] = [];
		add_filter( 'bfag_rate_limit_max_window', '__return_zero' );
		$this->assertSame( 1, ( new RateLimitRuleRepository() )->max_window(), 'never below one second' );
	}
}
