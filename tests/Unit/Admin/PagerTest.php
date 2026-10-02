<?php
/**
 * WPMAP-144: the one pager every uncapped admin list uses.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Admin;

use BlueFairy\ApiGateway\Admin\Pager;
use BlueFairy\ApiGateway\Tests\TestCase;

final class PagerTest extends TestCase {

	private function rendered( int $paged, int $pages ): string {
		ob_start();
		Pager::render( 'demo_page', 'https://example.com/wp-admin/options-general.php?page=x', $paged, $pages, '1,005 things' );
		return (string) ob_get_clean();
	}

	public function test_window_pages_the_total_and_clamps_the_asked_page(): void {
		$this->assertSame( [ 'paged' => 1, 'pages' => 1, 'offset' => 0, 'per_page' => 50 ], Pager::window( 'demo_page', 0, 50 ), 'no rows is one empty page' );
		$this->assertSame( [ 'paged' => 1, 'pages' => 3, 'offset' => 0, 'per_page' => 50 ], Pager::window( 'demo_page', 101, 50 ) );
		foreach ( [ '3' => 3, '2' => 2, '99' => 3, '0' => 1, 'abc' => 1, '-2' => 2 ] as $asked => $paged ) {
			$_GET['demo_page'] = (string) $asked;
			$w                 = Pager::window( 'demo_page', 101, 50 );
			$this->assertSame( $paged, $w['paged'], "asked for {$asked}" );
			$this->assertSame( ( $paged - 1 ) * 50, $w['offset'], "offset for {$asked}" );
		}
		$_GET['demo_page'] = [ '2' ];
		$this->assertSame( 1, Pager::requested( 'demo_page' ), 'a non-scalar value is page 1' );
		$_GET = [ 'other_page' => '3' ];
		$this->assertSame( 1, Pager::requested( 'demo_page' ), 'each list reads its own argument' );
	}

	public function test_one_page_prints_nothing(): void {
		$this->assertSame( '', $this->rendered( 1, 1 ) );
	}

	public function test_the_first_middle_and_last_pages_link_where_they_should(): void {
		$first = $this->rendered( 1, 3 );
		$this->assertStringContainsString( '<span class="displaying-num">1,005 things</span>', $first );
		$this->assertStringContainsString( 'data-bfag-page="1">Page 1 of 3</span>', $first );
		$this->assertStringContainsString( 'class="next-page button" href="https://example.com/wp-admin/options-general.php?page=x&amp;demo_page=2"', $first );
		$this->assertStringContainsString( 'class="last-page button" href="https://example.com/wp-admin/options-general.php?page=x&amp;demo_page=3"', $first );
		$this->assertStringNotContainsString( 'prev-page', $first );
		$this->assertStringNotContainsString( 'first-page', $first );

		$middle = $this->rendered( 2, 3 );
		foreach ( [ 'first-page', 'prev-page', 'next-page', 'last-page' ] as $cls ) {
			$this->assertStringContainsString( $cls, $middle );
		}
		$this->assertStringContainsString( 'demo_page=1"', $middle );

		$last = $this->rendered( 3, 3 );
		$this->assertStringContainsString( 'Page 3 of 3', $last );
		$this->assertStringNotContainsString( 'next-page', $last );
		$this->assertStringContainsString( 'class="prev-page button" href="https://example.com/wp-admin/options-general.php?page=x&amp;demo_page=2"', $last );
	}

	public function test_hidden_field_carries_the_page_back(): void {
		ob_start();
		Pager::hidden_field( 'demo_page', 4 );
		$this->assertSame( '<input type="hidden" name="demo_page" value="4" />', ob_get_clean() );
	}
}
