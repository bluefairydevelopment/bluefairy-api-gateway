<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Cache;

use BlueFairy\ApiGateway\Cache\CacheKeyGenerator;
use BlueFairy\ApiGateway\Cache\CacheManager;
use BlueFairy\ApiGateway\Cache\CacheRuleRepository;
use BlueFairy\ApiGateway\Cache\CacheStatsRepository;
use BlueFairy\ApiGateway\Cache\CacheStore;
use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Tests\TestCase;

final class CacheManagerTest extends TestCase {

	private CacheStore $store;
	private \WP_REST_Server $server;

	protected function setUp(): void {
		parent::setUp();
		$this->store  = new CacheStore();
		$this->server = new \WP_REST_Server();
	}

	/**
	 * A manager over the given settings; rules are primed straight into the
	 * object cache so no DB answer is needed.
	 *
	 * @param array<string, mixed>              $settings Settings overrides.
	 * @param list<array<string, mixed>>|null   $rules    Enabled rules; null for the seeded `**` rule.
	 */
	private function manager( array $settings = [], ?array $rules = null ): CacheManager {
		update_option( 'bfag_settings', $settings );
		$rules ??= [ $this->rule() ];
		wp_cache_set( CacheRuleRepository::CACHE_KEY, $rules, 'bfag' );
		$repo = new CacheRuleRepository();
		return new CacheManager( $this->store, new CacheKeyGenerator( $this->store ), $repo, new CacheStatsRepository(), new Settings() );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function rule( string $glob = '**', int $ttl = 120, string $tags = '', ?string $headers = null ): array {
		return [
			'id'          => 1,
			'label'       => 'r',
			'route_glob'  => $glob,
			'ttl_seconds' => $ttl,
			'tags'        => $tags,
			'headers'     => $headers,
			'enabled'     => 1,
			'created_at'  => '',
		];
	}

	/**
	 * @param array<string, string> $headers Response headers.
	 */
	private function response( int $status = 200, array $headers = [], mixed $data = [ 'ok' => true ] ): \WP_REST_Response {
		return new \WP_REST_Response( $data, $status, $headers );
	}

	/**
	 * @return list<string>
	 */
	private function stats_queries(): array {
		return $this->wpdb->queries_matching( 'bfag_cache_stats' );
	}

	public function test_a_bare_wp_http_response_passes_through_untouched_and_is_not_stored(): void {
		$manager = $this->manager();
		$request = $this->request( 'GET', '/wp/v2/posts' );
		$plain   = new \WP_HTTP_Response( [ 'ok' => true ], 200, [ 'Content-Type' => 'application/json' ] );

		$result = $manager->maybe_store( $plain, $this->server, $request );

		$this->assertSame( $plain, $result );
		$this->assertArrayNotHasKey( 'X-BF-Cache', $result->get_headers(), 'not even labelled' );
		$this->assertSame( [], $GLOBALS['bfag_test_transients'], 'nothing stored' );
		$this->assertSame( [], $this->stats_queries(), 'no stats recorded' );
	}

	public function test_anonymous_get_is_stored_as_miss_then_served_as_hit(): void {
		$manager = $this->manager();
		$request = $this->request( 'GET', '/wp/v2/posts', [ 'Accept' => 'application/json' ], [ 'per_page' => 2 ] );

		$this->assertNull( $manager->maybe_serve( null, $this->server, $request ), 'nothing stored yet' );

		$fresh  = $this->response( 200, [ 'Content-Type' => 'application/json' ], [ [ 'id' => 1 ] ] );
		$stored = $manager->maybe_store( $fresh, $this->server, $request );
		$this->assertSame( 'MISS', $stored->get_headers()['X-BF-Cache'] );
		$this->assertCount( 1, $GLOBALS['bfag_test_transients'] );
		$this->assertStringContainsString( 'misses = misses + 1', $this->stats_queries()[0] );
		$this->assertCount( 1, $this->stats_queries() );

		$transient = array_values( $GLOBALS['bfag_test_transients'] )[0];
		$this->assertSame( bfag_test_now() + 120, $transient['expires'], 'rule TTL' );
		$this->assertArrayNotHasKey( 'X-BF-Cache', $transient['value']['headers'], 'the outcome header is not stored' );

		$hit = $manager->maybe_serve( null, $this->server, $this->request( 'GET', '/wp/v2/posts', [ 'Accept' => 'application/json' ], [ 'per_page' => 2 ] ) );
		$this->assertInstanceOf( \WP_REST_Response::class, $hit );
		$this->assertSame( 'HIT', $hit->get_headers()['X-BF-Cache'] );
		$this->assertSame( 'application/json', $hit->get_headers()['Content-Type'] );
		$this->assertSame( 200, $hit->get_status() );
		$this->assertSame( [ [ 'id' => 1 ] ], $hit->get_data() );
		$this->assertStringContainsString( 'hits = hits + 1', $this->stats_queries()[1] );

		$this->assertNull( $manager->maybe_serve( null, $this->server, $this->request( 'GET', '/wp/v2/posts', [ 'Accept' => 'application/json' ], [ 'per_page' => 3 ] ) ), 'different params, different key' );
	}

	public function test_a_served_hit_passing_through_maybe_store_is_not_relabelled_or_stored_again(): void {
		$manager = $this->manager();
		$request = $this->request( 'GET', '/wp/v2/posts' );
		$manager->maybe_store( $this->response(), $this->server, $request );
		$hit = $manager->maybe_serve( null, $this->server, $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $hit );

		$again = $manager->maybe_store( $hit, $this->server, $request );
		$this->assertSame( 'HIT', $again->get_headers()['X-BF-Cache'] );
		$this->assertCount( 2, $this->stats_queries(), 'one miss, one hit, nothing more' );
	}

	public function test_object_cache_backend_round_trips_through_the_manager(): void {
		wp_using_ext_object_cache( true );
		$manager = $this->manager();
		$request = $this->request( 'GET', '/wp/v2/posts' );
		$manager->maybe_store( $this->response(), $this->server, $request );
		$this->assertCount( 1, $GLOBALS['bfag_test_cache'][ CacheStore::GROUP ] );
		$this->assertSame( [], $GLOBALS['bfag_test_transients'] );
		$hit = $manager->maybe_serve( null, $this->server, $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $hit );
		$this->assertSame( 'HIT', $hit->get_headers()['X-BF-Cache'] );
	}

	public function test_maybe_serve_passes_an_earlier_non_null_result_through(): void {
		$manager = $this->manager();
		$request = $this->request( 'GET', '/wp/v2/posts' );
		$manager->maybe_store( $this->response(), $this->server, $request );

		$earlier = $this->response( 429 );
		$this->assertSame( $earlier, $manager->maybe_serve( $earlier, $this->server, $request ) );
		$this->assertCount( 1, $this->stats_queries(), 'no hit recorded' );
	}

	/**
	 * @return array<string, array{0: array<string, string>}>
	 */
	public static function private_headers(): array {
		return [
			'Authorization' => [ [ 'Authorization' => 'Bearer x' ] ],
			'X-BF-API-Key'  => [ [ 'X-BF-API-Key' => 'k' ] ],
			'X-WP-Nonce'    => [ [ 'X-WP-Nonce' => 'n' ] ],
		];
	}

	/**
	 * @dataProvider private_headers
	 * @param array<string, string> $headers Request headers.
	 */
	public function test_a_request_carrying_a_credential_header_is_bypassed_even_when_an_entry_exists( array $headers ): void {
		$manager = $this->manager();
		$manager->maybe_store( $this->response(), $this->server, $this->request( 'GET', '/wp/v2/posts' ) );

		$request = $this->request( 'GET', '/wp/v2/posts', $headers );
		$this->assertFalse( $manager->is_cacheable_request( $request ) );
		$this->assertNull( $manager->maybe_serve( null, $this->server, $request ) );
		$response = $manager->maybe_store( $this->response(), $this->server, $request );
		$this->assertSame( 'BYPASS', $response->get_headers()['X-BF-Cache'] );
		$this->assertCount( 1, $GLOBALS['bfag_test_transients'], 'not stored again' );
		$this->assertCount( 1, $this->stats_queries() );
	}

	public function test_logged_in_users_are_bypassed(): void {
		$manager = $this->manager();
		$request = $this->request( 'GET', '/wp/v2/posts' );
		$this->assertTrue( $manager->is_cacheable_request( $request ) );
		wp_set_current_user( 5 );
		$this->assertFalse( $manager->is_cacheable_request( $request ) );
		$this->assertSame( 'BYPASS', $manager->maybe_store( $this->response(), $this->server, $request )->get_headers()['X-BF-Cache'] );
		$this->assertSame( [], $GLOBALS['bfag_test_transients'] );
	}

	public function test_only_get_and_head_are_cacheable(): void {
		$manager = $this->manager();
		$this->assertTrue( $manager->is_cacheable_request( $this->request( 'GET', '/wp/v2/posts' ) ) );
		$this->assertTrue( $manager->is_cacheable_request( $this->request( 'HEAD', '/wp/v2/posts' ) ) );
		$this->assertFalse( $manager->is_cacheable_request( $this->request( 'POST', '/wp/v2/posts' ) ) );
		$this->assertFalse( $manager->is_cacheable_request( $this->request( 'DELETE', '/wp/v2/posts/1' ) ) );
		$this->assertSame( 'BYPASS', $manager->maybe_store( $this->response( 201 ), $this->server, $this->request( 'POST', '/wp/v2/posts' ) )->get_headers()['X-BF-Cache'] );
	}

	public function test_cache_enabled_setting_switches_everything_off(): void {
		$manager = $this->manager( [ 'cache_enabled' => false ] );
		$request = $this->request( 'GET', '/wp/v2/posts' );
		$this->assertFalse( $manager->is_cacheable_request( $request ) );
		$this->assertSame( 'BYPASS', $manager->maybe_store( $this->response(), $this->server, $request )->get_headers()['X-BF-Cache'] );
		$this->assertSame( [], $GLOBALS['bfag_test_transients'] );

		$this->assertTrue( $this->manager( [ 'cache_enabled' => true ] )->is_cacheable_request( $request ) );
	}

	public function test_the_plugins_own_namespace_is_never_cached(): void {
		$manager = $this->manager();
		$this->assertFalse( $manager->is_cacheable_request( $this->request( 'GET', '/bfag/v1/status' ) ) );
		$this->assertFalse( $manager->is_cacheable_request( $this->request( 'GET', 'bfag/v1/status' ) ) );
		$this->assertTrue( $manager->is_cacheable_request( $this->request( 'GET', '/bfagx/v1/status' ) ), 'prefix match is on the namespace, not the characters' );
		$this->assertSame( 'BYPASS', $manager->maybe_store( $this->response(), $this->server, $this->request( 'GET', '/bfag/v1/status' ) )->get_headers()['X-BF-Cache'] );
	}

	public function test_bypass_filter_receives_the_request_and_wins(): void {
		$manager = $this->manager();
		$request = $this->request( 'GET', '/wp/v2/posts' );
		$seen    = null;
		add_filter(
			'bfag_cache_bypass',
			static function ( bool $bypass, \WP_REST_Request $r ) use ( &$seen ): bool {
				$seen = $r;
				return '/wp/v2/posts' === $r->get_route();
			},
			10,
			2
		);
		$this->assertFalse( $manager->is_cacheable_request( $request ) );
		$this->assertSame( $request, $seen );
		$this->assertTrue( $manager->is_cacheable_request( $this->request( 'GET', '/wp/v2/pages' ) ) );
	}

	public function test_a_route_no_enabled_rule_matches_is_bypassed(): void {
		$manager = $this->manager( [], [ $this->rule( 'wp/v2/posts' ) ] );
		$this->assertSame( 'MISS', $manager->maybe_store( $this->response(), $this->server, $this->request( 'GET', '/wp/v2/posts' ) )->get_headers()['X-BF-Cache'] );
		$this->assertSame( 'BYPASS', $manager->maybe_store( $this->response(), $this->server, $this->request( 'GET', '/wc/v3/products' ) )->get_headers()['X-BF-Cache'] );
		$this->assertCount( 1, $GLOBALS['bfag_test_transients'] );

		$none = $this->manager( [], [] );
		$this->assertSame( 'BYPASS', $none->maybe_store( $this->response(), $this->server, $this->request( 'GET', '/wp/v2/posts' ) )->get_headers()['X-BF-Cache'] );
		$this->assertNull( $none->maybe_serve( null, $this->server, $this->request( 'GET', '/wp/v2/posts' ) ) );
	}

	/**
	 * @return array<string, array{0: int, 1: array<string, string>, 2: bool}>
	 */
	public static function responses(): array {
		return [
			'200 plain'             => [ 200, [], true ],
			'201 created'           => [ 201, [], true ],
			'299 edge'              => [ 299, [], true ],
			'public cache-control'  => [ 200, [ 'Cache-Control' => 'public, max-age=60' ], true ],
			'json content-type'     => [ 200, [ 'Content-Type' => 'application/json; charset=UTF-8' ], true ],
			'199'                   => [ 199, [], false ],
			'301'                   => [ 301, [], false ],
			'404'                   => [ 404, [], false ],
			'500'                   => [ 500, [], false ],
			'event stream'          => [ 200, [ 'Content-Type' => 'text/event-stream' ], false ],
			'set-cookie'            => [ 200, [ 'Set-Cookie' => 'a=b' ], false ],
			'set-cookie lowercase'  => [ 200, [ 'set-cookie' => 'a=b' ], false ],
			'no-store'              => [ 200, [ 'Cache-Control' => 'no-store' ], false ],
			'private'               => [ 200, [ 'cache-control' => 'Private, max-age=0' ], false ],
		];
	}

	/**
	 * @dataProvider responses
	 * @param array<string, string> $headers Response headers.
	 */
	public function test_is_cacheable_response( int $status, array $headers, bool $expected ): void {
		$manager = $this->manager();
		$this->assertSame( $expected, $manager->is_cacheable_response( $this->response( $status, $headers ) ) );
	}

	public function test_an_uncacheable_response_is_labelled_bypass_and_not_stored(): void {
		$manager = $this->manager();
		$request = $this->request( 'GET', '/wp/v2/posts' );
		$error   = $manager->maybe_store( $this->response( 500, [], [ 'code' => 'boom' ] ), $this->server, $request );
		$this->assertSame( 'BYPASS', $error->get_headers()['X-BF-Cache'] );
		$this->assertSame( [], $GLOBALS['bfag_test_transients'] );
		$this->assertSame( [], $this->stats_queries(), 'neither a hit nor a miss' );
		$this->assertNull( $manager->maybe_serve( null, $this->server, $request ) );

		$cookie = $manager->maybe_store( $this->response( 200, [ 'Set-Cookie' => 'x=1' ] ), $this->server, $request );
		$this->assertSame( 'BYPASS', $cookie->get_headers()['X-BF-Cache'] );
		$this->assertSame( [], $GLOBALS['bfag_test_transients'] );
	}

	public function test_rule_headers_are_added_stored_and_served(): void {
		$manager = $this->manager( [], [ $this->rule( '**', 60, '', '{"X-Robots-Tag":"noindex","X-BF-Cache":"spoof","bad name":"x"}' ) ] );
		$request = $this->request( 'GET', '/wp/v2/posts' );

		$stored = $manager->maybe_store( $this->response(), $this->server, $request );
		$this->assertSame( 'noindex', $stored->get_headers()['X-Robots-Tag'] );
		$this->assertSame( 'MISS', $stored->get_headers()['X-BF-Cache'], 'a rule cannot forge the outcome header' );
		$this->assertArrayNotHasKey( 'bad name', $stored->get_headers() );

		$hit = $manager->maybe_serve( null, $this->server, $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $hit );
		$this->assertSame( 'noindex', $hit->get_headers()['X-Robots-Tag'] );
		$this->assertSame( 'HIT', $hit->get_headers()['X-BF-Cache'] );
	}

	public function test_a_rule_header_that_makes_the_response_uncacheable_is_applied_but_blocks_storage(): void {
		$manager = $this->manager( [], [ $this->rule( '**', 60, '', '{"Cache-Control":"no-store"}' ) ] );
		$response = $manager->maybe_store( $this->response(), $this->server, $this->request( 'GET', '/wp/v2/posts' ) );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertSame( 'BYPASS', $response->get_headers()['X-BF-Cache'] );
		$this->assertSame( [], $GLOBALS['bfag_test_transients'] );
	}

	public function test_a_rules_tags_are_part_of_the_key_so_bumping_one_misses(): void {
		$manager = $this->manager( [], [ $this->rule( '**', 60, 'products,posts' ) ] );
		$request = $this->request( 'GET', '/wc/v3/products' );
		$manager->maybe_store( $this->response(), $this->server, $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $manager->maybe_serve( null, $this->server, $request ) );

		$this->store->bump_tags( [ 'unrelated' ] );
		$this->assertInstanceOf( \WP_REST_Response::class, $manager->maybe_serve( null, $this->server, $request ), 'an unrelated tag bump keeps the entry' );

		$this->store->bump_tags( [ 'products' ] );
		$this->assertNull( $manager->maybe_serve( null, $this->server, $request ), 'bumping one of the rule tags orphans the entry' );
	}

	public function test_flush_all_makes_an_old_entry_miss(): void {
		$manager = $this->manager();
		$request = $this->request( 'GET', '/wp/v2/posts' );
		$manager->maybe_store( $this->response(), $this->server, $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $manager->maybe_serve( null, $this->server, $request ) );

		$this->store->flush_all();
		$this->assertNull( $manager->maybe_serve( null, $this->server, $request ) );

		$this->assertSame( 'MISS', $manager->maybe_store( $this->response(), $this->server, $request )->get_headers()['X-BF-Cache'], 'and is repopulated under the new generation' );
		$this->assertInstanceOf( \WP_REST_Response::class, $manager->maybe_serve( null, $this->server, $request ) );
	}

	public function test_ttl_for_prefers_the_rule_then_the_default_setting_and_clamps(): void {
		$manager = $this->manager( [ 'default_cache_ttl' => 300 ] );
		$this->assertSame( 120, $manager->ttl_for( $this->rule( '**', 120 ) ) );
		$this->assertSame( 300, $manager->ttl_for( null ) );
		$this->assertSame( 300, $manager->ttl_for( $this->rule( '**', 0 ) ), 'a zero rule TTL falls back' );
		$this->assertSame( 86400, $manager->ttl_for( $this->rule( '**', 999999 ) ) );
		$this->assertSame( Settings::DEFAULTS['default_cache_ttl'], $this->manager()->ttl_for( null ) );
	}

	public function test_stored_ttl_falls_back_to_the_setting_when_the_rule_has_none(): void {
		bfag_test_set_time( 1_700_000_000 );
		$manager = $this->manager( [ 'default_cache_ttl' => 45 ], [ $this->rule( '**', 0 ) ] );
		$manager->maybe_store( $this->response(), $this->server, $this->request( 'GET', '/wp/v2/posts' ) );
		$this->assertSame( 1_700_000_045, array_values( $GLOBALS['bfag_test_transients'] )[0]['expires'] );
	}

	public function test_register_hooks_binds_the_dispatcher_filters_at_priority_10(): void {
		$manager = $this->manager();
		$this->assertFalse( has_filter( 'rest_pre_dispatch' ) );
		$manager->register_hooks();
		$this->assertSame( 10, has_filter( 'rest_pre_dispatch', [ $manager, 'maybe_serve' ] ) );
		$this->assertSame( 10, has_filter( 'rest_post_dispatch', [ $manager, 'maybe_store' ] ) );

		$request = $this->request( 'GET', '/wp/v2/posts' );
		$this->assertNull( apply_filters( 'rest_pre_dispatch', null, $this->server, $request ) );
		$stored = apply_filters( 'rest_post_dispatch', $this->response(), $this->server, $request );
		$this->assertSame( 'MISS', $stored->get_headers()['X-BF-Cache'] );
		$hit = apply_filters( 'rest_pre_dispatch', null, $this->server, $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $hit );
		$this->assertSame( 'HIT', $hit->get_headers()['X-BF-Cache'] );
	}
}
