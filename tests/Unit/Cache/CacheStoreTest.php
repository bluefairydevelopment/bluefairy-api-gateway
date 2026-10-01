<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Cache;

use BlueFairy\ApiGateway\Cache\CacheStore;
use BlueFairy\ApiGateway\Tests\TestCase;

final class CacheStoreTest extends TestCase {

	private const ENTRY = [
		'status'  => 200,
		'headers' => [ 'Content-Type' => 'application/json' ],
		'data'    => [ 'id' => 1 ],
	];

	public function test_transient_backend_round_trips_without_an_external_object_cache(): void {
		$this->assertFalse( wp_using_ext_object_cache() );
		$store = new CacheStore();

		$this->assertNull( $store->get( 'k1' ), 'nothing stored yet' );
		$store->set( 'k1', self::ENTRY, 60 );

		$entry = $store->get( 'k1' );
		$this->assertNotNull( $entry );
		$this->assertSame( 200, $entry['status'] );
		$this->assertSame( [ 'Content-Type' => 'application/json' ], $entry['headers'] );
		$this->assertSame( [ 'id' => 1 ], $entry['data'] );
		$this->assertArrayHasKey( 'bfag_resp_k1', $GLOBALS['bfag_test_transients'] );
		$this->assertArrayNotHasKey( CacheStore::GROUP, $GLOBALS['bfag_test_cache'], 'transient backend must not touch the object cache group' );
	}

	public function test_object_cache_backend_round_trips_with_an_external_object_cache(): void {
		wp_using_ext_object_cache( true );
		$store = new CacheStore();

		$this->assertNull( $store->get( 'k2' ) );
		$store->set( 'k2', self::ENTRY, 60 );

		$entry = $store->get( 'k2' );
		$this->assertNotNull( $entry );
		$this->assertSame( [ 'id' => 1 ], $entry['data'] );
		$this->assertArrayHasKey( 'k2', $GLOBALS['bfag_test_cache'][ CacheStore::GROUP ] );
		$this->assertArrayNotHasKey( 'bfag_resp_k2', $GLOBALS['bfag_test_transients'], 'object-cache backend must not write a transient' );
	}

	public function test_backends_do_not_see_each_other(): void {
		$store = new CacheStore();
		$store->set( 'k3', self::ENTRY, 60 );
		wp_using_ext_object_cache( true );
		$this->assertNull( $store->get( 'k3' ), 'an entry written as a transient is invisible to the object-cache backend' );
	}

	public function test_set_stores_status_headers_data_and_stored_at_and_drops_the_rest(): void {
		$store = new CacheStore();
		$store->set(
			'k4',
			[
				'status'  => '201',
				'headers' => [ 'X-A' => '1' ],
				'data'    => null,
				'extra'   => 'dropped',
			],
			60
		);
		$stored = $GLOBALS['bfag_test_transients']['bfag_resp_k4']['value'];
		$this->assertSame( [ 'status', 'headers', 'data', 'stored_at' ], array_keys( $stored ) );
		$this->assertSame( 201, $stored['status'] );
		$this->assertEqualsWithDelta( time(), $stored['stored_at'], 2 );

		$entry = $store->get( 'k4' );
		$this->assertNotNull( $entry, 'null data is still a stored entry' );
		$this->assertNull( $entry['data'] );
		$this->assertSame( 201, $entry['status'] );
	}

	public function test_set_honours_the_ttl(): void {
		bfag_test_set_time( 1_700_000_000 );
		$store = new CacheStore();
		$store->set( 'k5', self::ENTRY, 60 );

		bfag_test_set_time( 1_700_000_059 );
		$this->assertNotNull( $store->get( 'k5' ), 'inside the TTL' );
		bfag_test_set_time( 1_700_000_061 );
		$this->assertNull( $store->get( 'k5' ), 'past the TTL' );
	}

	public function test_get_rejects_malformed_entries(): void {
		$store = new CacheStore();
		set_transient( 'bfag_resp_bad1', 'a string', 60 );
		set_transient( 'bfag_resp_bad2', [ 'status' => 200, 'headers' => [], 'stored_at' => 1 ], 60 );
		set_transient( 'bfag_resp_bad3', [ 'status' => 200, 'headers' => 'nope', 'data' => 1, 'stored_at' => 1 ], 60 );
		set_transient( 'bfag_resp_good', [ 'status' => 200, 'headers' => [], 'data' => 1, 'stored_at' => 1 ], 60 );

		$this->assertNull( $store->get( 'bad1' ), 'not an array' );
		$this->assertNull( $store->get( 'bad2' ), 'missing data key' );
		$this->assertNull( $store->get( 'bad3' ), 'headers not an array' );
		$this->assertNotNull( $store->get( 'good' ) );
	}

	public function test_delete_removes_from_the_active_backend(): void {
		$store = new CacheStore();
		$store->set( 'k6', self::ENTRY, 60 );
		$this->assertNotNull( $store->get( 'k6' ) );
		$store->delete( 'k6' );
		$this->assertNull( $store->get( 'k6' ) );

		wp_using_ext_object_cache( true );
		$store->set( 'k7', self::ENTRY, 60 );
		$this->assertNotNull( $store->get( 'k7' ) );
		$store->delete( 'k7' );
		$this->assertNull( $store->get( 'k7' ) );
	}

	public function test_generation_defaults_to_one_reads_the_option_and_memoises_in_the_bfag_group(): void {
		$store = new CacheStore();
		$this->assertSame( 1, $store->generation(), 'no option yet' );
		$this->assertSame( 1, wp_cache_get( 'generation', 'bfag' ) );

		update_option( 'bfag_cache_generation', 7 );
		$this->assertSame( 1, $store->generation(), 'memoised: the option is not re-read' );

		wp_cache_delete( 'generation', 'bfag' );
		$this->assertSame( 7, $store->generation() );
	}

	public function test_flush_all_increments_the_generation_and_flushes_the_response_group(): void {
		update_option( 'bfag_cache_generation', 3 );
		wp_using_ext_object_cache( true );
		$store = new CacheStore();
		$store->set( 'k8', self::ENTRY, 60 );
		$this->assertSame( 3, $store->generation() );

		$store->flush_all();

		$this->assertSame( 4, get_option( 'bfag_cache_generation' ) );
		$this->assertSame( 4, $store->generation(), 'the memo was invalidated' );
		$this->assertArrayNotHasKey( CacheStore::GROUP, $GLOBALS['bfag_test_cache'], 'group flushed' );
		$this->assertNull( $store->get( 'k8' ) );
	}

	public function test_flush_all_creates_the_option_when_missing(): void {
		$store = new CacheStore();
		$store->flush_all();
		$this->assertSame( 2, get_option( 'bfag_cache_generation' ) );
	}

	public function test_tag_generation_defaults_to_one_and_bump_tags_increments_only_the_named_tags(): void {
		$store = new CacheStore();
		$this->assertSame( 1, $store->tag_generation( 'products' ) );
		$this->assertSame( 1, $store->tag_generation( 'posts' ) );

		$store->bump_tags( [ 'products' ] );
		$this->assertSame( 2, $store->tag_generation( 'products' ) );
		$this->assertSame( 1, $store->tag_generation( 'posts' ), 'unrelated tag untouched' );
		$this->assertSame( 1, $store->generation(), 'global generation untouched' );
		$this->assertArrayHasKey( 'bfag_tag_gen_products', $GLOBALS['bfag_test_transients'] );

		$store->bump_tags( [ 'products', 'products', 'posts' ] );
		$this->assertSame( 3, $store->tag_generation( 'products' ), 'a duplicate in one call bumps once' );
		$this->assertSame( 2, $store->tag_generation( 'posts' ) );
	}

	public function test_tags_are_sanitised_and_empty_tags_ignored(): void {
		$store = new CacheStore();
		$store->bump_tags( [ ' Prod ucts! ', '', '***' ] );
		$this->assertSame( 2, $store->tag_generation( 'products' ) );
		$this->assertSame( 2, $store->tag_generation( 'PRODUCTS' ), 'lookup is sanitised the same way' );
		$this->assertSame( 1, $store->tag_generation( '' ) );
		$this->assertCount( 1, array_filter( array_keys( $GLOBALS['bfag_test_transients'] ), static fn( string $k ): bool => str_starts_with( $k, 'bfag_tag_gen_' ) ) );
		$store->bump_tags( [ 'post:7' ] );
		$this->assertSame( 2, $store->tag_generation( 'post:7' ), 'colons survive so post:7 is a distinct tag' );
		$this->assertSame( 1, $store->tag_generation( 'post7' ), 'post7 is not post:7' );
	}

	public function test_sanitize_tag_is_the_shared_static_sanitiser(): void {
		$this->assertSame( 'post:42', CacheStore::sanitize_tag( ' Post:42 ' ) );
		$this->assertSame( 'ab_c-d', CacheStore::sanitize_tag( 'a.b_c-d!' ) );
		$this->assertSame( '', CacheStore::sanitize_tag( '***' ) );
		$this->assertSame( str_repeat( 'x', 50 ), CacheStore::sanitize_tag( str_repeat( 'x', 51 ) ) );
	}
}
