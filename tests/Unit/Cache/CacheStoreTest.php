<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Cache;

use BlueFairy\ApiGateway\Cache\CacheStore;
use BlueFairy\ApiGateway\Installer;
use BlueFairy\ApiGateway\Tests\TestCase;

final class CacheStoreTest extends TestCase {

	private const ENTRY = [
		'status'  => 200,
		'headers' => [ 'Content-Type' => 'application/json' ],
		'data'    => [ 'id' => 1 ],
	];

	public function test_default_install_with_no_drop_in_uses_the_transient_backend(): void {
		$this->assertNull( wp_using_ext_object_cache(), 'core leaves $_wp_using_ext_object_cache null when there is no object-cache.php drop-in' );
		$store = new CacheStore();

		// Every backend call must survive a null answer, not raise a TypeError.
		$this->assertNull( $store->get( 'k0' ) );
		$store->set( 'k0', self::ENTRY, 60 );
		$this->assertSame( [ 'id' => 1 ], $store->get( 'k0' )['data'] ?? null, 'round-trips through transients' );
		$this->assertArrayHasKey( 'bfag_resp_k0', $GLOBALS['bfag_test_transients'] );
		$this->assertArrayNotHasKey( CacheStore::GROUP, $GLOBALS['bfag_test_cache'], 'a null answer means no persistent object cache' );
		$store->delete( 'k0' );
		$this->assertNull( $store->get( 'k0' ) );

		// A real drop-in sets it to true, and only then does the object-cache group take over.
		wp_using_ext_object_cache( true );
		$store->set( 'k0', self::ENTRY, 60 );
		$this->assertArrayHasKey( 'k0', $GLOBALS['bfag_test_cache'][ CacheStore::GROUP ] );
	}

	public function test_transient_backend_round_trips_without_an_external_object_cache(): void {
		wp_using_ext_object_cache( false );
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

		$this->assertSame( 4, (int) get_option( 'bfag_cache_generation' ) );
		$this->assertSame( 4, $store->generation(), 'the memo was invalidated' );
		$this->assertArrayNotHasKey( CacheStore::GROUP, $GLOBALS['bfag_test_cache'], 'group flushed' );
		$this->assertNull( $store->get( 'k8' ) );
	}

	public function test_flush_all_creates_the_option_when_missing(): void {
		$store = new CacheStore();
		$store->flush_all();
		$this->assertSame( 2, (int) get_option( 'bfag_cache_generation' ) );
	}

	public function test_tag_generation_defaults_to_one_and_bump_tags_increments_only_the_named_tags(): void {
		$store = new CacheStore();
		$this->assertSame( 1, $store->tag_generation( 'products' ) );
		$this->assertSame( 1, $store->tag_generation( 'posts' ) );

		$store->bump_tags( [ 'products' ] );
		$this->assertSame( 2, $store->tag_generation( 'products' ) );
		$this->assertSame( 1, $store->tag_generation( 'posts' ), 'unrelated tag untouched' );
		$this->assertSame( 1, $store->generation(), 'global generation untouched' );
		$this->assertSame( [ 'products' => 2 ], get_option( CacheStore::TAG_GENERATIONS_OPTION ), 'the option holds exactly the bumped tags' );
		$this->assertSame( 'bfag_tag_generations', CacheStore::TAG_GENERATIONS_OPTION );

		$store->bump_tags( [ 'products', 'products', 'posts' ] );
		$this->assertSame( 3, $store->tag_generation( 'products' ), 'a duplicate in one call bumps once' );
		$this->assertSame( 2, $store->tag_generation( 'posts' ) );
		$this->assertSame(
			[
				'products' => 3,
				'posts'    => 2,
			],
			get_option( CacheStore::TAG_GENERATIONS_OPTION )
		);
	}

	public function test_tag_generations_live_in_one_option_never_in_transients_and_the_object_cache_copy_is_dropped_on_bump(): void {
		$store = new CacheStore();
		$store->bump_tags( [ 'products' ] );
		$store->bump_tags( [ 'type:post', 'all' ] );

		$this->assertSame( [], $GLOBALS['bfag_test_transients'], 'no transient is written for a tag generation' );
		$this->assertSame( [ 'bfag_tag_generations' ], array_keys( $GLOBALS['bfag_test_options'] ), 'one option, and the only option written' );
		$this->assertCount( 3, get_option( CacheStore::TAG_GENERATIONS_OPTION ) );
		$this->assertContains( 'bfag_tag_generations', Installer::OPTIONS, 'so uninstall deletes it by name' );

		// A read populates the object-cache copy; a bump drops it.
		$this->assertSame( 2, $store->tag_generation( 'all' ) );
		$this->assertArrayHasKey( 'tag_generations', $GLOBALS['bfag_test_cache']['bfag'] ?? [] );
		$this->assertSame( get_option( CacheStore::TAG_GENERATIONS_OPTION ), wp_cache_get( 'tag_generations', 'bfag' ) );
		$store->bump_tags( [ 'all' ] );
		$this->assertArrayNotHasKey( 'tag_generations', $GLOBALS['bfag_test_cache']['bfag'] ?? [], 'the object-cache entry is deleted on bump' );
		$this->assertSame( 3, $store->tag_generation( 'all' ) );

		// A copy left in the object cache by a previous request is honoured over the option.
		$fresh = new CacheStore();
		wp_cache_set( 'tag_generations', [ 'all' => 9 ], 'bfag' );
		$this->assertSame( 9, $fresh->tag_generation( 'all' ) );
		$this->assertSame( 1, $fresh->tag_generation( 'products' ), 'and only that copy is consulted' );

		// Garbage in the option is ignored, not fatal.
		wp_cache_delete( 'tag_generations', 'bfag' );
		update_option( CacheStore::TAG_GENERATIONS_OPTION, 'garbage' );
		$this->assertSame( 1, ( new CacheStore() )->tag_generation( 'all' ) );
	}

	public function test_tags_are_sanitised_and_empty_tags_ignored(): void {
		$store = new CacheStore();
		$store->bump_tags( [ ' Prod ucts! ', '', '***' ] );
		$this->assertSame( 2, $store->tag_generation( 'products' ) );
		$this->assertSame( 2, $store->tag_generation( 'PRODUCTS' ), 'lookup is sanitised the same way' );
		$this->assertSame( 1, $store->tag_generation( '' ) );
		$this->assertSame( [ 'products' => 2 ], get_option( CacheStore::TAG_GENERATIONS_OPTION ), 'only the one real tag is stored' );
		$store->bump_tags( [ '', '!!' ] );
		$this->assertSame( [ 'products' => 2 ], get_option( CacheStore::TAG_GENERATIONS_OPTION ), 'nothing to bump: nothing written' );
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

	public function test_an_object_cache_entry_expires_after_its_ttl(): void {
		bfag_test_set_time( 1_700_000_000 );
		wp_using_ext_object_cache( true );
		$store = new CacheStore();
		$store->set( 'k9', self::ENTRY, 60 );
		$this->assertNotNull( $store->get( 'k9' ) );

		bfag_test_set_time( 1_700_000_060 );
		$this->assertNotNull( $store->get( 'k9' ), 'alive at the TTL boundary, as a transient is' );

		bfag_test_set_time( 1_700_000_061 );
		$this->assertNull( $store->get( 'k9' ), 'gone past the TTL' );
		$this->assertArrayNotHasKey( 'k9', $GLOBALS['bfag_test_cache'][ CacheStore::GROUP ] ?? [] );

		// Control: an entry with a longer TTL set at the same moment is still there.
		bfag_test_set_time( 1_700_000_000 );
		$store->set( 'k10', self::ENTRY, 120 );
		bfag_test_set_time( 1_700_000_061 );
		$this->assertNotNull( $store->get( 'k10' ) );
	}
}
