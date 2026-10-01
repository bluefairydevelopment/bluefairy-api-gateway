<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Support;

use BlueFairy\ApiGateway\Support\GlobMatcher;
use BlueFairy\ApiGateway\Tests\TestCase;

final class GlobMatcherTest extends TestCase {

	/**
	 * @dataProvider globs
	 */
	public function test_matches( string $glob, string $route, bool $expected ): void {
		$this->assertSame( $expected, GlobMatcher::matches( $glob, $route ) );
	}

	/** @return array<string, array{string, string, bool}> */
	public static function globs(): array {
		return [
			'star one segment'        => [ 'wc/v3/products/*', '/wc/v3/products/12', true ],
			'star stops at slash'     => [ 'wc/v3/products/*', '/wc/v3/products/12/variations', false ],
			'trailing star collection' => [ 'wc/v3/products/*', '/wc/v3/products', true ],
			'trailing star not sibling' => [ 'wc/v3/products/*', '/wc/v3/product', false ],
			'double star crosses'     => [ 'wc/v3/products/**', '/wc/v3/products/12/variations', true ],
			'trailing dstar collection' => [ 'wp/v2/posts/**', '/wp/v2/posts', true ],
			'trailing dstar child'    => [ 'wp/v2/posts/**', '/wp/v2/posts/1', true ],
			'trailing dstar deep'     => [ 'wp/v2/posts/**', '/wp/v2/posts/1/revisions', true ],
			'trailing dstar not sibling' => [ 'wp/v2/posts/**', '/wp/v2/postsX', false ],
			'mid star not optional'   => [ 'wp/v2/*/1', '/wp/v2/1', false ],
			'mid star one segment'    => [ 'wp/v2/*/1', '/wp/v2/posts/1', true ],
			'mid dstar not optional'  => [ 'wp/**/1', '/wp/1', false ],
			'mid dstar crosses'       => [ 'wp/**/1', '/wp/v2/posts/1', true ],
			'double star alone deep'  => [ '**', '/wp/v2/posts/1/revisions', true ],
			'double star alone empty' => [ '**', '', true ],
			'double star alone'       => [ '**', '/anything/at/all', true ],
			'single star alone'       => [ '*', '/wp/v2/posts', false ],
			'single star one segment' => [ '*', '/posts', true ],
			'question mark'           => [ 'wp/v?/posts', '/wp/v2/posts', true ],
			'question mark not slash' => [ 'wp/v?/posts', '/wp//posts', false ],
			'exact'                   => [ 'wp/v2/posts', '/wp/v2/posts', true ],
			'exact no leading slash'  => [ 'wp/v2/posts', 'wp/v2/posts', true ],
			'prefix not enough'       => [ 'wp/v2/posts', '/wp/v2/posts/1', false ],
			'regex chars literal'     => [ 'wp/v2/posts.(json)', '/wp/v2/posts.(json)', true ],
			'regex chars not special' => [ 'wp/v2/posts.(json)', '/wp/v2/postsX(json)', false ],
			'leading slash in glob'   => [ '/wp/v2/*', '/wp/v2/posts', true ],
			'empty route'             => [ '*', '', true ],
		];
	}

	public function test_compile_is_anchored_and_cached(): void {
		$first  = GlobMatcher::compile( 'a/*' );
		$second = GlobMatcher::compile( 'a/*' );
		$this->assertSame( $first, $second );
		$this->assertSame( '~^a(?:/[^/]*)?$~', $first, 'a trailing /* is optional' );
		$this->assertSame( '~^a(?:/.*)?$~', GlobMatcher::compile( 'a/**' ), 'a trailing /** is optional' );
		$this->assertSame( '~^a/[^/]*/b$~', GlobMatcher::compile( 'a/*/b' ), 'a mid-pattern * is not' );
		$this->assertSame( '~^.*$~', GlobMatcher::compile( '**' ), '** alone is everything, not an optional suffix' );
		$this->assertSame( '~^[^/]*$~', GlobMatcher::compile( '/*' ), '/* alone is one segment, not an optional suffix' );
	}

	public function test_specificity_counts_literal_characters_only(): void {
		$this->assertSame( 0, GlobMatcher::specificity( '**' ) );
		$this->assertSame( 0, GlobMatcher::specificity( '*' ) );
		$this->assertSame( 0, GlobMatcher::specificity( '?' ) );
		$this->assertSame( 12, GlobMatcher::specificity( 'wp/v2/posts/**' ) );
		$this->assertSame( 12, GlobMatcher::specificity( '/wp/v2/posts/**' ), 'the ignored leading slash is not counted' );
		$this->assertSame( 11, GlobMatcher::specificity( 'wp/v2/posts' ) );
		$this->assertSame( 11, GlobMatcher::specificity( 'wp/v?/posts/*' ), 'wp/v + /posts/ = 11 literals' );
		$this->assertGreaterThan( GlobMatcher::specificity( 'wp/v2/*' ), GlobMatcher::specificity( 'wp/v2/posts/*' ) );
	}

	public function test_compile_never_produces_nested_quantifiers(): void {
		$pattern = GlobMatcher::compile( '(a+)+$' );
		$this->assertStringNotContainsString( '+)+', $pattern );
		$this->assertSame( '~^\(a\+\)\+\$$~', $pattern );
		// And a pathological input cannot blow up the matcher.
		$this->assertFalse( GlobMatcher::matches( '(a+)+$', str_repeat( 'a', 5000 ) . '!' ) );
	}

	public function test_is_valid(): void {
		$this->assertTrue( GlobMatcher::is_valid( 'wc/v3/orders/*' ) );
		$this->assertTrue( GlobMatcher::is_valid( '**' ) );
		$this->assertFalse( GlobMatcher::is_valid( '' ) );
		$this->assertFalse( GlobMatcher::is_valid( '   ' ) );
		$this->assertFalse( GlobMatcher::is_valid( "wp/v2\nposts" ) );
		$this->assertFalse( GlobMatcher::is_valid( 'wp/v2/<script>' ) );
		$this->assertFalse( GlobMatcher::is_valid( str_repeat( 'a', 501 ) ) );
	}
}
