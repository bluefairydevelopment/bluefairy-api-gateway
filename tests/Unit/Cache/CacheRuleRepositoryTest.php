<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Cache;

use BlueFairy\ApiGateway\Cache\CacheRuleRepository;
use BlueFairy\ApiGateway\Logger;
use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Support\Limits;
use BlueFairy\ApiGateway\Tests\TestCase;

final class CacheRuleRepositoryTest extends TestCase {

	private CacheRuleRepository $repo;

	protected function setUp(): void {
		parent::setUp();
		$this->repo = new CacheRuleRepository();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row( int $id, string $glob = '**', string $tags = '', int $enabled = 1 ): array {
		return [
			'id'          => (string) $id,
			'label'       => 'Rule ' . $id,
			'route_glob'  => $glob,
			'ttl_seconds' => '120',
			'tags'        => $tags,
			'headers'     => null,
			'enabled'     => (string) $enabled,
			'created_at'  => '2026-09-30 00:00:00',
		];
	}

	private function enable_logging(): void {
		update_option( 'bfag_settings', [ 'debug_logging' => true ] );
		Logger::use_settings( new Settings() );
		ini_set( 'error_log', '/dev/null' );
	}

	public function test_all_enabled_queries_once_normalises_and_caches_under_the_contract_key(): void {
		$this->wpdb->queue( 'get_results', [ $this->row( 1 ), $this->row( 2, 'wp/v2/*' ) ] );

		$first  = $this->repo->all_enabled();
		$second = $this->repo->all_enabled();

		$this->assertSame( $first, $second );
		$this->assertCount( 1, $this->wpdb->calls_to( 'get_results' ), 'second call served from the object cache' );
		$this->assertSame(
			'SELECT * FROM bfw_bfag_cache_rules WHERE enabled = 1 ORDER BY id ASC LIMIT ' . Limits::MAX_ROWS,
			$this->wpdb->calls_to( 'get_results' )[0]['sql']
		);
		$this->assertSame( $first, wp_cache_get( CacheRuleRepository::CACHE_KEY, 'bfag' ) );
		$this->assertSame( 'cache_rules_enabled', CacheRuleRepository::CACHE_KEY );

		$this->assertSame( 1, $first[0]['id'] );
		$this->assertSame( 120, $first[0]['ttl_seconds'] );
		$this->assertSame( 1, $first[0]['enabled'] );
		$this->assertSame( '', $first[0]['tags'] );
		$this->assertNull( $first[0]['headers'] );
	}

	public function test_all_enabled_is_empty_when_there_are_no_rows_and_a_cached_list_is_used_as_is(): void {
		$this->assertSame( [], $this->repo->all_enabled() );

		wp_cache_set( CacheRuleRepository::CACHE_KEY, [ [ 'id' => 9, 'route_glob' => '**' ] ], 'bfag' );
		$this->assertSame( 9, $this->repo->all_enabled()[0]['id'] );
		$this->assertCount( 1, $this->wpdb->calls_to( 'get_results' ) );
	}

	public function test_all_find_and_count_issue_prepared_queries(): void {
		$this->wpdb->queue( 'get_results', [ $this->row( 1 ), $this->row( 2, '**', '', 0 ) ] );
		$all = $this->repo->all( 50 );
		$this->assertCount( 2, $all );
		$this->assertSame( 0, $all[1]['enabled'], 'all() includes disabled rules' );
		$this->assertSame( 'SELECT * FROM bfw_bfag_cache_rules ORDER BY id ASC LIMIT 50', $this->wpdb->calls_to( 'get_results' )[0]['sql'] );

		$this->wpdb->queue( 'get_row', $this->row( 4 ) );
		$found = $this->repo->find( 4 );
		$this->assertNotNull( $found );
		$this->assertSame( 4, $found['id'] );
		$this->assertSame( 'SELECT * FROM bfw_bfag_cache_rules WHERE id = 4 LIMIT 1', $this->wpdb->calls_to( 'get_row' )[0]['sql'] );
		$this->assertNull( $this->repo->find( 5 ), 'no row queued' );

		$this->wpdb->queue( 'get_var', '3' );
		$this->assertSame( 3, $this->repo->count() );
		$this->assertStringContainsString( 'SELECT COUNT(*) FROM bfw_bfag_cache_rules', $this->wpdb->calls_to( 'get_var' )[0]['sql'] );
		$this->assertSame( 0, $this->repo->count(), 'nothing queued counts as zero' );
	}

	public function test_limit_is_500_unless_filtered(): void {
		$this->assertSame( 500, $this->repo->limit(), 'WPMAP-142: the same ceiling as Pro' );
		$this->assertSame( Limits::DEFAULT_LIMIT, $this->repo->limit() );
		add_filter( 'bfag_cache_rule_limit', static fn(): int => 25 );
		$this->assertSame( 25, $this->repo->limit() );
	}

	public function test_create_inserts_sanitised_columns_and_invalidates_the_cache(): void {
		wp_cache_set( CacheRuleRepository::CACHE_KEY, [], 'bfag' );
		$this->wpdb->queue( 'get_var', '0' );

		$id = $this->repo->create(
			[
				'label'       => '  Products <b>list</b>  ',
				'route_glob'  => '/wc/v3/products/**',
				'ttl_seconds' => '300',
				'tags'        => [ 'Products', 'posts!', 'products', '' ],
				'headers'     => [ 'X-Robots-Tag' => 'noindex' ],
				'enabled'     => 'on',
			]
		);

		$this->assertSame( 1, $id );
		$inserts = $this->wpdb->calls_to( 'insert' );
		$this->assertCount( 1, $inserts );
		$this->assertSame( 'bfw_bfag_cache_rules', $inserts[0]['table'] );
		$this->assertSame(
			[
				'label'       => 'Products list',
				'route_glob'  => 'wc/v3/products/**',
				'ttl_seconds' => 300,
				'tags'        => 'products,posts',
				'headers'     => '{"X-Robots-Tag":"noindex"}',
				'enabled'     => 1,
			],
			$inserts[0]['data']
		);
		$this->assertSame( [ '%s', '%s', '%d', '%s', '%s', '%d' ], $inserts[0]['format'] );
		$this->assertFalse( wp_cache_get( CacheRuleRepository::CACHE_KEY, 'bfag' ), 'enabled-rules cache invalidated' );
	}

	public function test_create_defaults_when_only_a_glob_is_given(): void {
		$this->wpdb->queue( 'get_var', '0' );
		$this->assertSame( 1, $this->repo->create( [] ) );
		$data = $this->wpdb->calls_to( 'insert' )[0]['data'];
		$this->assertSame( '**', $data['route_glob'] );
		$this->assertSame( Settings::DEFAULTS['default_cache_ttl'], $data['ttl_seconds'] );
		$this->assertSame( '', $data['tags'] );
		$this->assertNull( $data['headers'] );
		$this->assertSame( 1, $data['enabled'] );
	}

	public function test_create_accepts_a_second_rule_by_default(): void {
		$this->wpdb->queue( 'get_var', '1' );
		$this->assertSame( 1, $this->repo->create( [ 'route_glob' => 'wp/v2/posts/**' ] ), 'WPMAP-142: one stored rule no longer fills the free plugin' );
		$this->assertCount( 1, $this->wpdb->calls_to( 'insert' ) );
	}

	public function test_create_refuses_a_rule_at_the_default_limit_and_logs(): void {
		$this->enable_logging();
		$this->wpdb->queue( 'get_var', '500' );

		$this->assertFalse( $this->repo->create( [ 'route_glob' => '**' ] ) );
		$this->assertCount( 0, $this->wpdb->calls_to( 'insert' ) );
		$this->assertCount( 1, Logger::lines() );
		$this->assertStringContainsString( 'limit', Logger::lines()[0] );
	}

	public function test_create_allows_more_rules_when_the_limit_filter_is_raised(): void {
		add_filter( 'bfag_cache_rule_limit', static fn(): int => 10 );
		$this->wpdb->queue( 'get_var', '1' );
		$this->assertSame( 1, $this->repo->create( [ 'route_glob' => '**' ] ) );

		$this->wpdb->queue( 'get_var', '10' );
		$this->assertFalse( $this->repo->create( [ 'route_glob' => '**' ] ), 'the raised limit is still a limit' );
		$this->assertCount( 1, $this->wpdb->calls_to( 'insert' ) );
	}

	public function test_create_rejects_an_invalid_glob_and_logs(): void {
		$this->enable_logging();
		$this->wpdb->always( 'get_var', '0' );

		$this->assertFalse( $this->repo->create( [ 'route_glob' => 'wp/v2/[bad' ] ) );
		$this->assertFalse( $this->repo->create( [ 'route_glob' => '' ] ) );
		$this->assertCount( 0, $this->wpdb->calls_to( 'insert' ) );
		$this->assertCount( 2, Logger::lines() );

		$this->assertSame( 1, $this->repo->create( [ 'route_glob' => 'wp/v2/posts/?' ] ), 'a valid glob is accepted' );
	}

	public function test_create_clamps_ttl_to_1_through_86400(): void {
		$this->wpdb->always( 'get_var', '0' );
		$this->repo->create( [ 'ttl_seconds' => 0 ] );
		$this->repo->create( [ 'ttl_seconds' => -5 ] );
		$this->repo->create( [ 'ttl_seconds' => 999999 ] );
		$this->repo->create( [ 'ttl_seconds' => 86400 ] );
		$ttls = array_map( static fn( array $c ): int => $c['data']['ttl_seconds'], $this->wpdb->calls_to( 'insert' ) );
		$this->assertSame( [ 1, 1, 86400, 86400 ], $ttls );
	}

	public function test_create_caps_tags_at_twenty_and_strips_bad_characters(): void {
		$this->wpdb->always( 'get_var', '0' );
		$tags = [];
		for ( $i = 1; $i <= 25; $i++ ) {
			$tags[] = 'tag-' . $i;
		}
		$this->repo->create( [ 'tags' => implode( ',', $tags ) ] );
		$this->repo->create( [ 'tags' => 'Hello World, foo_bar, foo-bar, a.b, Post:7' ] );

		$inserts = $this->wpdb->calls_to( 'insert' );
		$this->assertCount( 20, explode( ',', $inserts[0]['data']['tags'] ) );
		$this->assertSame( 'tag-1', explode( ',', $inserts[0]['data']['tags'] )[0] );
		$this->assertSame( 'helloworld,foo_bar,foo-bar,ab,post:7', $inserts[1]['data']['tags'], 'the colon of a Pro tag survives (CacheStore::sanitize_tag)' );
	}

	public function test_create_sanitises_headers_json(): void {
		$this->wpdb->always( 'get_var', '0' );
		$this->repo->create( [ 'headers' => '{"X-One":"1","Bad Name":"x","Set-Cookie":"a=b","X-CRLF":"a\r\nb","X-Num":5}' ] );
		$this->repo->create( [ 'headers' => 'not json' ] );
		$this->repo->create( [ 'headers' => '{"Bad Name":"x"}' ] );

		$inserts = $this->wpdb->calls_to( 'insert' );
		$this->assertSame( '{"X-One":"1","X-Num":"5"}', $inserts[0]['data']['headers'] );
		$this->assertNull( $inserts[1]['data']['headers'], 'unparseable JSON stores null' );
		$this->assertNull( $inserts[2]['data']['headers'], 'nothing survives sanitising' );
	}

	public function test_create_returns_false_and_logs_when_the_insert_fails(): void {
		$this->enable_logging();
		$this->wpdb->queue( 'get_var', '0' );
		$this->wpdb->queue( 'insert', false );
		$this->assertFalse( $this->repo->create( [ 'route_glob' => '**' ] ) );
		$this->assertCount( 1, Logger::lines() );
	}

	public function test_update_changes_only_the_given_columns_and_invalidates_the_cache(): void {
		wp_cache_set( CacheRuleRepository::CACHE_KEY, [], 'bfag' );

		$this->assertTrue( $this->repo->update( 3, [ 'ttl_seconds' => 999, 'tags' => 'A,B' ] ) );

		$updates = $this->wpdb->calls_to( 'update' );
		$this->assertCount( 1, $updates );
		$this->assertSame( 'bfw_bfag_cache_rules', $updates[0]['table'] );
		$this->assertSame(
			[
				'ttl_seconds' => 999,
				'tags'        => 'a,b',
			],
			$updates[0]['data']
		);
		$this->assertSame( [ 'id' => 3 ], $updates[0]['where'] );
		$this->assertFalse( wp_cache_get( CacheRuleRepository::CACHE_KEY, 'bfag' ) );
	}

	public function test_update_refuses_empty_or_invalid_input_and_reports_db_failure(): void {
		$this->assertFalse( $this->repo->update( 3, [] ), 'nothing to change' );
		$this->assertFalse( $this->repo->update( 3, [ 'route_glob' => '[bad' ] ), 'invalid glob' );
		$this->assertCount( 0, $this->wpdb->calls_to( 'update' ) );

		$this->wpdb->queue( 'update', false );
		$this->assertFalse( $this->repo->update( 3, [ 'label' => 'x' ] ) );
		$this->assertCount( 1, $this->wpdb->calls_to( 'update' ) );
	}

	public function test_delete_reports_whether_a_row_went_and_invalidates_the_cache(): void {
		wp_cache_set( CacheRuleRepository::CACHE_KEY, [], 'bfag' );
		$this->wpdb->queue( 'delete', 1 );
		$this->assertTrue( $this->repo->delete( 3 ) );
		$this->assertSame( [ 'id' => 3 ], $this->wpdb->calls_to( 'delete' )[0]['where'] );
		$this->assertFalse( wp_cache_get( CacheRuleRepository::CACHE_KEY, 'bfag' ) );

		$this->wpdb->queue( 'delete', 0 );
		$this->assertFalse( $this->repo->delete( 4 ), 'no such row' );
		$this->wpdb->queue( 'delete', false );
		$this->assertFalse( $this->repo->delete( 5 ), 'db error' );
	}

	public function test_set_enabled_writes_the_flag(): void {
		$this->assertTrue( $this->repo->set_enabled( 2, false ) );
		$this->assertTrue( $this->repo->set_enabled( 2, true ) );
		$updates = $this->wpdb->calls_to( 'update' );
		$this->assertSame( [ 'enabled' => 0 ], $updates[0]['data'] );
		$this->assertSame( [ 'enabled' => 1 ], $updates[1]['data'] );
		$this->assertSame( [ 'id' => 2 ], $updates[1]['where'] );
	}

	public function test_match_returns_the_enabled_rule_whose_glob_matches(): void {
		$this->wpdb->queue( 'get_results', [ $this->row( 1, 'wc/v3/products/*' ), $this->row( 2, 'wc/v3/**' ) ] );

		$this->assertSame( 1, $this->repo->match( '/wc/v3/products/12' )['id'] ?? null );
		$this->assertSame( 2, $this->repo->match( '/wc/v3/products/12/variations' )['id'] ?? null, 'single * does not cross a slash' );
		$this->assertNull( $this->repo->match( '/wp/v2/posts' ) );
		$this->assertNull( $this->repo->match( '' ) );
	}

	public function test_match_prefers_the_most_specific_rule_not_the_lowest_id(): void {
		// The seeded `**` rule has id 1; a narrower rule added later has id 2.
		$this->wpdb->queue( 'get_results', [ $this->row( 1, '**', 'everything' ), $this->row( 2, 'wp/v2/posts/**', 'type:post' ) ] );

		$this->assertSame( 2, $this->repo->match( '/wp/v2/posts/3' )['id'] ?? null, 'the specific rule wins although it comes second' );
		$this->assertSame( 2, $this->repo->match( '/wp/v2/posts' )['id'] ?? null, 'and it covers the collection route' );
		$this->assertSame( 1, $this->repo->match( '/wp/v2/pages' )['id'] ?? null, 'only ** matches pages' );
	}

	public function test_match_skips_a_disabled_specific_rule(): void {
		$this->wpdb->queue( 'get_results', [ $this->row( 1, '**' ), $this->row( 2, 'wp/v2/posts/**', '', 0 ) ] );

		$this->assertSame( 1, $this->repo->match( '/wp/v2/posts/3' )['id'] ?? null, 'a disabled rule never matches, however specific' );

		wp_cache_delete( CacheRuleRepository::CACHE_KEY, 'bfag' );
		$this->wpdb->queue( 'get_results', [ $this->row( 1, '**' ), $this->row( 2, 'wp/v2/posts/**', '', 1 ) ] );
		$this->assertSame( 2, $this->repo->match( '/wp/v2/posts/3' )['id'] ?? null, 'the same rule enabled wins' );
	}

	public function test_match_breaks_a_specificity_tie_by_lowest_id(): void {
		$this->wpdb->queue( 'get_results', [ $this->row( 7, 'wp/v2/*' ), $this->row( 3, 'wp/v?/*' ), $this->row( 5, 'wp/v2/*' ) ] );

		$this->assertSame( 5, $this->repo->match( '/wp/v2/posts' )['id'] ?? null, 'ids 5 and 7 tie at 6 literals; 5 wins; id 3 has only 5 literals' );
	}

	public function test_sort_by_specificity_orders_most_specific_first_then_lowest_id(): void {
		$rules  = [ $this->row( 1, '**' ), $this->row( 2, 'wp/v2/posts/**' ), $this->row( 4, 'wc/v3/*' ), $this->row( 3, 'wc/v3/*' ) ];
		$sorted = $this->repo->sort_by_specificity( $rules );
		$this->assertSame( [ '2', '3', '4', '1' ], array_column( $sorted, 'id' ) );
		$this->assertSame( [], $this->repo->sort_by_specificity( [] ) );
	}

	public function test_match_ignores_rules_with_an_empty_glob(): void {
		wp_cache_set( CacheRuleRepository::CACHE_KEY, [ [ 'id' => 1, 'route_glob' => '' ] ], 'bfag' );
		$this->assertNull( $this->repo->match( '/anything' ) );
	}

	public function test_tags_of_splits_and_sanitises(): void {
		$this->assertSame( [ 'products', 'posts' ], $this->repo->tags_of( [ 'tags' => 'products, Posts ,products,' ] ) );
		$this->assertSame( [], $this->repo->tags_of( [ 'tags' => '' ] ) );
		$this->assertSame( [], $this->repo->tags_of( [] ) );
	}
}
