<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Cache;

use BlueFairy\ApiGateway\Cache\CacheKeyGenerator;
use BlueFairy\ApiGateway\Cache\CacheStore;
use BlueFairy\ApiGateway\Tests\TestCase;

final class CacheKeyGeneratorTest extends TestCase {

	private CacheStore $store;
	private CacheKeyGenerator $keys;

	protected function setUp(): void {
		parent::setUp();
		$this->store = new CacheStore();
		$this->keys  = new CacheKeyGenerator( $this->store );
	}

	public function test_identical_requests_share_a_key_and_the_key_is_an_md5(): void {
		$a = $this->keys->for_request( $this->request( 'GET', '/wp/v2/posts', [ 'Accept' => 'application/json' ], [ 'per_page' => 5 ] ) );
		$b = $this->keys->for_request( $this->request( 'GET', '/wp/v2/posts', [ 'Accept' => 'application/json' ], [ 'per_page' => 5 ] ) );
		$this->assertSame( $a, $b );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $a );
	}

	public function test_method_route_params_and_accept_each_change_the_key(): void {
		$base = $this->keys->for_request( $this->request( 'GET', '/wp/v2/posts', [ 'Accept' => 'application/json' ], [ 'per_page' => 5 ] ) );

		$this->assertNotSame( $base, $this->keys->for_request( $this->request( 'HEAD', '/wp/v2/posts', [ 'Accept' => 'application/json' ], [ 'per_page' => 5 ] ) ), 'method' );
		$this->assertNotSame( $base, $this->keys->for_request( $this->request( 'GET', '/wp/v2/pages', [ 'Accept' => 'application/json' ], [ 'per_page' => 5 ] ) ), 'route' );
		$this->assertNotSame( $base, $this->keys->for_request( $this->request( 'GET', '/wp/v2/posts', [ 'Accept' => 'application/json' ], [ 'per_page' => 6 ] ) ), 'param value' );
		$this->assertNotSame( $base, $this->keys->for_request( $this->request( 'GET', '/wp/v2/posts', [ 'Accept' => 'application/json' ], [] ) ), 'param presence' );
		$this->assertNotSame( $base, $this->keys->for_request( $this->request( 'GET', '/wp/v2/posts', [ 'Accept' => 'text/html' ], [ 'per_page' => 5 ] ) ), 'accept' );
	}

	public function test_query_parameter_order_does_not_matter_at_any_depth(): void {
		$a = $this->keys->for_request( $this->request( 'GET', '/wp/v2/posts', [], [ 'a' => 1, 'b' => [ 'x' => 1, 'y' => 2 ] ] ) );
		$b = $this->keys->for_request( $this->request( 'GET', '/wp/v2/posts', [], [ 'b' => [ 'y' => 2, 'x' => 1 ], 'a' => 1 ] ) );
		$c = $this->keys->for_request( $this->request( 'GET', '/wp/v2/posts', [], [ 'b' => [ 'y' => 3, 'x' => 1 ], 'a' => 1 ] ) );
		$this->assertSame( $a, $b );
		$this->assertNotSame( $a, $c, 'a nested value change still changes the key' );
	}

	public function test_wpnonce_and_locale_params_are_ignored_but_other_params_are_not(): void {
		$base = $this->keys->for_request( $this->request( 'GET', '/wp/v2/posts', [], [ 'page' => 2 ] ) );
		$this->assertSame( $base, $this->keys->for_request( $this->request( 'GET', '/wp/v2/posts', [], [ 'page' => 2, '_wpnonce' => 'abc', '_locale' => 'user' ] ) ) );
		$this->assertNotSame( $base, $this->keys->for_request( $this->request( 'GET', '/wp/v2/posts', [], [ 'page' => 2, 'context' => 'edit' ] ) ) );
	}

	public function test_a_coerced_param_produces_the_same_key_as_its_string_form(): void {
		// rest_pre_dispatch sees the raw query strings; by rest_post_dispatch core has
		// sanitised them to their declared types. Both must hash to one key.
		$raw     = $this->keys->for_request( $this->request( 'GET', '/wp/v2/posts', [], [ 'per_page' => '2', 'include' => [ '3', '1' ], 'sticky' => '1' ] ) );
		$coerced = $this->keys->for_request( $this->request( 'GET', '/wp/v2/posts', [], [ 'per_page' => 2, 'include' => [ 3, 1 ], 'sticky' => true ] ) );
		$this->assertSame( $raw, $coerced );

		$this->assertNotSame( $raw, $this->keys->for_request( $this->request( 'GET', '/wp/v2/posts', [], [ 'per_page' => 3, 'include' => [ 3, 1 ], 'sticky' => true ] ) ), 'a different value is still a different key' );
		$this->assertNotSame( $raw, $this->keys->for_request( $this->request( 'GET', '/wp/v2/posts', [], [ 'per_page' => 2, 'include' => [ 1, 3 ], 'sticky' => true ] ) ), 'list order is a different value' );
		$this->assertNotSame( $raw, $this->keys->for_request( $this->request( 'GET', '/wp/v2/posts', [], [ 'per_page' => 2, 'include' => [ 3, 1 ], 'sticky' => false ] ) ), 'false is "", not "1"' );
	}

	public function test_accept_header_is_case_insensitive_and_missing_equals_empty(): void {
		$a = $this->keys->for_request( $this->request( 'GET', '/wp/v2/posts', [ 'Accept' => 'Application/JSON' ] ) );
		$b = $this->keys->for_request( $this->request( 'GET', '/wp/v2/posts', [ 'Accept' => 'application/json' ] ) );
		$c = $this->keys->for_request( $this->request( 'GET', '/wp/v2/posts' ) );
		$this->assertSame( $a, $b );
		$this->assertNotSame( $a, $c );
	}

	public function test_route_with_and_without_leading_slash_share_a_key(): void {
		$a = $this->keys->for_request( $this->request( 'GET', '/wp/v2/posts' ) );
		$b = $this->keys->for_request( $this->request( 'GET', 'wp/v2/posts' ) );
		$this->assertSame( $a, $b );
	}

	public function test_global_generation_is_part_of_the_key(): void {
		$request = $this->request( 'GET', '/wp/v2/posts' );
		$before  = $this->keys->for_request( $request );
		$this->store->flush_all();
		$this->assertNotSame( $before, $this->keys->for_request( $request ) );
	}

	public function test_tags_and_their_generations_are_part_of_the_key(): void {
		$request = $this->request( 'GET', '/wp/v2/posts' );
		$plain   = $this->keys->for_request( $request );
		$tagged  = $this->keys->for_request( $request, [ 'products' ] );
		$this->assertNotSame( $plain, $tagged, 'a rule tag changes the key' );

		$this->assertSame( $tagged, $this->keys->for_request( $request, [ 'products', 'products' ] ), 'duplicate tags collapse' );
		$this->assertSame(
			$this->keys->for_request( $request, [ 'a', 'b' ] ),
			$this->keys->for_request( $request, [ 'b', 'a' ] ),
			'tag order does not matter'
		);

		$this->store->bump_tags( [ 'posts' ] );
		$this->assertSame( $tagged, $this->keys->for_request( $request, [ 'products' ] ), 'bumping another tag leaves the key alone' );

		$this->store->bump_tags( [ 'products' ] );
		$this->assertNotSame( $tagged, $this->keys->for_request( $request, [ 'products' ] ), 'bumping the tag changes the key' );
		$this->assertSame( $plain, $this->keys->for_request( $request ), 'the untagged key is unaffected by a tag bump' );
	}

	public function test_an_untagged_request_is_keyed_on_the_implicit_tag_all(): void {
		$request  = $this->request( 'GET', '/wp/v2/posts' );
		$untagged = $this->keys->for_request( $request );
		$this->assertSame( 'all', CacheKeyGenerator::IMPLICIT_TAG );
		$this->assertSame( $untagged, $this->keys->for_request( $request, [ 'all' ] ), 'no tags means the tag all' );
		$this->assertSame( $untagged, $this->keys->for_request( $request, [ '' ] ), 'an empty tag is no tag' );
		$this->assertNotSame( $untagged, $this->keys->for_request( $request, [ 'type:product' ] ), 'a tagged rule is not keyed on all' );
		$this->assertNotSame( $this->keys->for_request( $request, [ 'all', 'type:product' ] ), $this->keys->for_request( $request, [ 'type:product' ] ), 'and all is not added to it' );

		$product = $this->keys->for_request( $request, [ 'type:product' ] );
		$this->store->bump_tags( [ 'all' ] );
		$this->assertNotSame( $untagged, $this->keys->for_request( $request ), 'bumping all changes the untagged key' );
		$this->assertSame( $product, $this->keys->for_request( $request, [ 'type:product' ] ), 'and leaves a tagged request alone' );
	}
}
