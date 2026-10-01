<?php
/**
 * Performance review instrument: how many $wpdb calls one anonymous GET
 * costs through the real rest_pre_dispatch / rest_post_dispatch chain.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit;

use BlueFairy\ApiGateway\Cache\CacheKeyGenerator;
use BlueFairy\ApiGateway\Cache\CacheManager;
use BlueFairy\ApiGateway\Cache\CacheRuleRepository;
use BlueFairy\ApiGateway\Cache\CacheStatsRepository;
use BlueFairy\ApiGateway\Cache\CacheStore;
use BlueFairy\ApiGateway\RateLimit\HitCounter;
use BlueFairy\ApiGateway\RateLimit\RateLimitMiddleware;
use BlueFairy\ApiGateway\RateLimit\RateLimitRuleRepository;
use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Support\RequestContext;
use BlueFairy\ApiGateway\Tests\TestCase;

/**
 * Boots the real middleware classes exactly as Plugin::boot() wires them
 * (rate limit at priority 5, cache at 10) and drives them through the stub
 * hook dispatcher, then reads the FakeWpdb call log.
 *
 * Only $wpdb traffic is counted. Option and transient reads are array
 * lookups in the test bootstrap; they are enumerated in the review report
 * from core's behaviour instead.
 */
final class HotPathQueryCountTest extends TestCase {

	private CacheStore $store;
	private CacheKeyGenerator $keys;
	private \WP_REST_Server $server;

	protected function setUp(): void {
		parent::setUp();
		$_SERVER['REMOTE_ADDR']            = '203.0.113.9';
		$GLOBALS['bfag_test_serving_rest'] = true; // TF4: the top-level REST request (the harness default is false, as core).
		update_option( 'bfag_settings', Settings::DEFAULTS );
		$this->store  = new CacheStore();
		$this->keys   = new CacheKeyGenerator( $this->store );
		$this->server = new \WP_REST_Server();
		$settings     = new Settings();
		( new RateLimitMiddleware( new RateLimitRuleRepository(), new HitCounter(), $settings, new RequestContext( $settings ) ) )->register_hooks();
		( new CacheManager( $this->store, $this->keys, new CacheRuleRepository(), new CacheStatsRepository(), $settings ) )->register_hooks();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function rl_rule( int $id = 1, string $glob = '**' ): array {
		return [
			'id'              => $id,
			'label'           => 'Global per-IP limit',
			'subject_type'    => 'ip',
			'route_glob'      => $glob,
			'max_requests'    => 60,
			'window_seconds'  => 60,
			'penalty_seconds' => 0,
			'enabled'         => 1,
			'created_at'      => '',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function cache_rule(): array {
		return [
			'id'          => 1,
			'label'       => 'All routes',
			'route_glob'  => '**',
			'ttl_seconds' => 60,
			'tags'        => '',
			'headers'     => null,
			'enabled'     => 1,
			'created_at'  => '',
		];
	}

	/**
	 * Cold = no persistent object cache, every per-request cache empty (what
	 * every request looks like on a plain install).
	 *
	 * @param list<array<string, mixed>> $rl_rules Rate-limit rules the DB answers with.
	 */
	private function cold( array $rl_rules, int $count = 1 ): void {
		$this->wpdb->queue( 'get_results', $rl_rules );      // RateLimitRuleRepository::all_enabled().
		$this->wpdb->always( 'insert', 1 );                   // HitCounter::consume() INSERT.
		$this->wpdb->always( 'get_var', (string) $count );    // HitCounter::count().
		$this->wpdb->queue( 'get_results', [ $this->cache_rule() ] ); // CacheRuleRepository::all_enabled().
		$this->wpdb->always( 'query', 1 );                    // CacheStatsRepository upsert.
	}

	/**
	 * Warm = persistent object cache present and every bfag key already in it.
	 */
	private function warm(): void {
		wp_using_ext_object_cache( true );
		wp_cache_set( RateLimitRuleRepository::CACHE_KEY, [ $this->rl_rule() ], 'bfag' );
		wp_cache_set( CacheRuleRepository::CACHE_KEY, [ $this->cache_rule() ], 'bfag' );
		wp_cache_set( 'generation', 1, 'bfag' );
		wp_cache_set( 'tag_generations', [], 'bfag' );
		$this->wpdb->always( 'insert', 1 );
		$this->wpdb->always( 'get_var', '1' );
		$this->wpdb->always( 'query', 1 );
	}

	/**
	 * One full request through both filters; returns the final response.
	 */
	private function drive( \WP_REST_Request $request ): \WP_HTTP_Response {
		$pre = apply_filters( 'rest_pre_dispatch', null, $this->server, $request );
		$response = $pre instanceof \WP_HTTP_Response ? $pre : new \WP_REST_Response( [ 'ok' => true ], 200, [ 'Content-Type' => 'application/json' ] );
		$out = apply_filters( 'rest_post_dispatch', $response, $this->server, $request );
		self::assertInstanceOf( \WP_HTTP_Response::class, $out );
		return $out;
	}

	/**
	 * Method name plus the plugin table each call touched.
	 *
	 * @return list<string>
	 */
	private function trace(): array {
		$out = [];
		foreach ( $this->wpdb->calls as $call ) {
			$text = (string) ( $call['sql'] ?? $call['table'] ?? '' );
			preg_match( '/bfw_(bfagp?_[a-z_]+)/', $text, $m );
			$out[] = $call['method'] . ':' . ( $m[1] ?? '?' );
		}
		return $out;
	}

	public function test_miss_cold_costs_five_queries(): void {
		$this->cold( [ $this->rl_rule() ] );
		$out = $this->drive( $this->request( 'GET', '/wp/v2/posts' ) );

		self::assertSame( 'MISS', $out->get_headers()['X-BF-Cache'] );
		self::assertSame(
			[
				'get_results:bfag_rate_limit_rules',
				'insert:bfag_rate_limit_hits',
				'get_var:bfag_rate_limit_hits',
				'get_results:bfag_cache_rules',
				'query:bfag_cache_stats',
			],
			$this->trace()
		);
	}

	/**
	 * Without a persistent object cache nothing here changes: the counters
	 * need a store that outlives the request. P1 (transient cleanup) is a
	 * cron, not a hot-path saving.
	 */
	public function test_hit_cold_still_costs_five_queries_two_of_them_writes(): void {
		$this->cold( [ $this->rl_rule() ] );
		$request = $this->request( 'GET', '/wp/v2/posts' );
		// Seed the stored response under the exact key the generator will compute.
		$this->store->set( $this->keys->for_request( $request ), [ 'status' => 200, 'headers' => [ 'Content-Type' => 'application/json' ], 'data' => [ 'cached' => true ] ], 60 );
		$this->wpdb->calls = [];

		$out = $this->drive( $request );

		self::assertSame( 'HIT', $out->get_headers()['X-BF-Cache'] );
		self::assertSame( [ 'cached' => true ], $out->get_data() );
		self::assertSame(
			[
				'get_results:bfag_rate_limit_rules',
				'insert:bfag_rate_limit_hits',
				'get_var:bfag_rate_limit_hits',
				'get_results:bfag_cache_rules',
				'query:bfag_cache_stats',
			],
			$this->trace()
		);
		self::assertCount( 2, array_filter( $this->trace(), static fn( string $t ): bool => str_starts_with( $t, 'insert:' ) || str_starts_with( $t, 'query:' ) ), 'a cache HIT performs two row writes' );
	}

	public function test_bypass_cold_costs_three_queries_all_rate_limit(): void {
		$this->cold( [ $this->rl_rule() ] );
		wp_set_current_user( 7 );
		$out = $this->drive( $this->request( 'GET', '/wp/v2/posts' ) );

		self::assertSame( 'BYPASS', $out->get_headers()['X-BF-Cache'] );
		self::assertSame(
			[
				'get_results:bfag_rate_limit_rules',
				'insert:bfag_rate_limit_hits',
				'get_var:bfag_rate_limit_hits',
			],
			$this->trace()
		);
	}

	/**
	 * P3: with a persistent object cache the rate-limit COUNT is a
	 * wp_cache_incr() and the cache-stats upsert is a counter the KPI cron
	 * flushes, so a MISS costs the one INSERT the hit log needs (was three).
	 */
	public function test_miss_warm_costs_one_query_the_hit_insert(): void {
		$this->warm();
		$out = $this->drive( $this->request( 'GET', '/wp/v2/posts' ) );

		self::assertSame( 'MISS', $out->get_headers()['X-BF-Cache'] );
		self::assertSame( [ 'insert:bfag_rate_limit_hits' ], $this->trace() );
		self::assertSame( 1, wp_cache_get( CacheStatsRepository::counter_key( time() - ( time() % 300 ), 'misses' ), 'bfag' ), 'the miss went to the counter' );
		self::assertSame( 1, wp_cache_get( HitCounter::window_key( '203.0.113.9', 'rule:1', 60 ), 'bfag' ), 'the hit went to the window counter' );
	}

	/**
	 * P3: a warm HIT is one query (the INSERT) and zero stats queries (was
	 * three, two of them writes).
	 */
	public function test_hit_warm_costs_one_query_and_no_stats_write(): void {
		$this->warm();
		$request = $this->request( 'GET', '/wp/v2/posts' );
		$this->store->set( $this->keys->for_request( $request ), [ 'status' => 200, 'headers' => [], 'data' => [ 'cached' => true ] ], 60 );
		$this->wpdb->calls = [];

		$out = $this->drive( $request );

		self::assertSame( 'HIT', $out->get_headers()['X-BF-Cache'] );
		self::assertSame( [ 'insert:bfag_rate_limit_hits' ], $this->trace() );
		self::assertSame( [], $this->wpdb->queries_matching( 'bfag_cache_stats' ), 'zero stats queries on a HIT' );
	}

	public function test_bypass_warm_costs_one_query(): void {
		$this->warm();
		wp_set_current_user( 7 );
		$this->drive( $this->request( 'GET', '/wp/v2/posts' ) );

		self::assertSame( [ 'insert:bfag_rate_limit_hits' ], $this->trace() );
	}

	/**
	 * P3 negative control: without a persistent object cache the warm
	 * seeding changes nothing — the COUNT and the stats upsert are still
	 * issued (the object cache is per request there, so a counter would
	 * forget everything on the next request).
	 */
	public function test_without_a_persistent_object_cache_the_count_and_stats_queries_stay(): void {
		$this->warm();
		$GLOBALS['bfag_test_ext_cache'] = null; // Back to "no drop-in" (a null argument is a read, as in core).
		$this->wpdb->queue( 'get_results', [ $this->rl_rule() ] );
		$this->wpdb->queue( 'get_results', [ $this->cache_rule() ] );
		$this->drive( $this->request( 'GET', '/wp/v2/posts' ) );

		self::assertContains( 'get_var:bfag_rate_limit_hits', $this->trace() );
		self::assertContains( 'query:bfag_cache_stats', $this->trace() );
	}

	/**
	 * Planted control for the instrument: a second matching rule must add
	 * exactly one more INSERT and one more COUNT (N rules x 2 queries), and
	 * a rule that does not match must add nothing.
	 */
	public function test_each_matching_rule_adds_an_insert_and_a_count(): void {
		$this->cold( [ $this->rl_rule( 1, '**' ), $this->rl_rule( 2, 'wp/v2/posts/**' ), $this->rl_rule( 3, 'wc/v3/**' ) ] );
		$this->drive( $this->request( 'GET', '/wp/v2/posts' ) );

		$trace = $this->trace();
		self::assertSame( 2, count( array_keys( $trace, 'insert:bfag_rate_limit_hits', true ) ) );
		self::assertSame( 2, count( array_keys( $trace, 'get_var:bfag_rate_limit_hits', true ) ) );
		self::assertCount( 7, $trace );
	}

	/**
	 * P4: the request that crosses the limit pays its INSERT + COUNT and
	 * leaves a marker; from then on, for the rest of the window, a limited
	 * client gets its 429 from the marker alone. Under a 5/60 rule the 6th
	 * request breaches and the 7th issues zero hits-table queries. (The
	 * cache layer still reads its rules for the 429 it refuses to store;
	 * the no-object-cache marker is a transient, an options-table read the
	 * FakeWpdb does not see — count it from core's behaviour.)
	 */
	public function test_the_seventh_request_of_a_five_per_minute_rule_issues_no_hits_table_query(): void {
		$rule = [ 'max_requests' => 5 ] + $this->rl_rule();
		$this->cold( [ $rule ], 0 );
		for ( $n = 1; $n <= 6; $n++ ) {
			$this->wpdb->queue( 'get_var', (string) $n ); // COUNT after the n-th INSERT.
		}
		$statuses = [];
		for ( $n = 1; $n <= 7; $n++ ) {
			$before     = count( array_filter( $this->trace(), static fn( string $t ): bool => str_ends_with( $t, ':bfag_rate_limit_hits' ) ) );
			$statuses[] = $this->drive( $this->request( 'GET', '/wp/v2/posts' ) )->get_status();
			$after      = count( array_filter( $this->trace(), static fn( string $t ): bool => str_ends_with( $t, ':bfag_rate_limit_hits' ) ) );
			$hits_queries[ $n ] = $after - $before;
		}

		self::assertSame( [ 200, 200, 200, 200, 200, 429, 429 ], $statuses );
		self::assertSame( [ 1 => 2, 2, 2, 2, 2, 2, 0 ], $hits_queries, 'INSERT + COUNT through the breach; nothing on the 7th' );
		self::assertNotFalse( get_transient( 'bfag_limited_' . RateLimitMiddleware::limited_key( '203.0.113.9', 1, 5, 60 ) ), 'the marker is a transient without a persistent object cache' );
		self::assertSame( 1, count( array_keys( $this->trace(), 'get_results:bfag_cache_rules', true ) ) + 0 * count( $this->trace() ), 'cache rules read once (object-cached after)' );
	}

	/**
	 * P4 with a persistent object cache: the marker is an object-cache key
	 * and the 7th request issues no query at all.
	 */
	public function test_with_a_persistent_object_cache_a_limited_client_costs_zero_queries(): void {
		$this->warm();
		wp_cache_set( RateLimitRuleRepository::CACHE_KEY, [ [ 'max_requests' => 5 ] + $this->rl_rule() ], 'bfag' );
		for ( $n = 1; $n <= 7; $n++ ) {
			$this->wpdb->calls = [];
			$status            = $this->drive( $this->request( 'GET', '/wp/v2/posts' ) )->get_status();
			self::assertSame( $n <= 5 ? 200 : 429, $status, "request {$n}" );
			self::assertSame( $n <= 6 ? [ 'insert:bfag_rate_limit_hits' ] : [], $this->trace(), "request {$n}" );
		}
		self::assertNotFalse( wp_cache_get( RateLimitMiddleware::limited_key( '203.0.113.9', 1, 5, 60 ), 'bfag' ) );
		self::assertSame( [], $GLOBALS['bfag_test_transients'], 'no transient when the object cache is persistent' );
	}

	/**
	 * P4 negative control: when the marker expires the client is counted
	 * again (INSERT + COUNT).
	 */
	public function test_an_expired_marker_counts_the_client_again(): void {
		bfag_test_set_time( 1_700_000_000 );
		$this->cold( [ [ 'max_requests' => 5 ] + $this->rl_rule() ], 99 );
		self::assertSame( 429, $this->drive( $this->request( 'GET', '/wp/v2/posts' ) )->get_status() );
		$this->wpdb->calls = [];
		self::assertSame( 429, $this->drive( $this->request( 'GET', '/wp/v2/posts' ) )->get_status() );
		self::assertSame( [], array_filter( $this->trace(), static fn( string $t ): bool => str_ends_with( $t, ':bfag_rate_limit_hits' ) ), 'marked' );

		bfag_test_set_time( 1_700_000_061 );
		$this->wpdb->calls = [];
		$this->drive( $this->request( 'GET', '/wp/v2/posts' ) );
		self::assertSame( [ 'insert:bfag_rate_limit_hits', 'get_var:bfag_rate_limit_hits' ], array_values( array_filter( $this->trace(), static fn( string $t ): bool => str_ends_with( $t, ':bfag_rate_limit_hits' ) ) ), 'the window passed: counted again' );
	}

	public function test_negative_control_rate_limit_disabled_removes_the_hit_queries(): void {
		update_option( 'bfag_settings', [ 'rate_limit_enabled' => false ] + Settings::DEFAULTS );
		$this->cold( [ $this->rl_rule() ] );
		// The rl rules get_results is never consumed; the cache rules queue entry is now first.
		$this->wpdb->calls = [];
		$this->drive( $this->request( 'GET', '/wp/v2/posts' ) );

		self::assertNotContains( 'insert:bfag_rate_limit_hits', $this->trace() );
		self::assertNotContains( 'get_var:bfag_rate_limit_hits', $this->trace() );
	}
}
