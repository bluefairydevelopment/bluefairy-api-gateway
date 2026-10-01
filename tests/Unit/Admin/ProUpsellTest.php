<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Admin;

use BlueFairy\ApiGateway\Admin\ProUpsell;
use BlueFairy\ApiGateway\Tests\TestCase;

final class ProUpsellTest extends TestCase {

	public function test_is_pro_follows_the_filter(): void {
		$upsell = new ProUpsell();
		$this->assertFalse( $upsell->is_pro() );
		add_filter( 'bfag_is_pro', '__return_true' );
		$this->assertTrue( $upsell->is_pro() );
	}

	public function test_the_panel_renders_with_the_product_link_unless_pro(): void {
		$upsell = new ProUpsell();
		$html   = bfag_test_render( [ $upsell, 'render' ] );
		$this->assertStringContainsString( 'class="bfag-upsell"', $html );
		$this->assertStringContainsString( 'https://wordpress.bluefairydevelopment.com/product/bluefairy-api-gateway-pro/', $html );
		foreach ( $upsell->features() as $feature ) {
			$this->assertStringContainsString( esc_html( $feature ), $html );
		}

		add_filter( 'bfag_is_pro', '__return_true' );
		$this->assertSame( '', bfag_test_render( [ $upsell, 'render' ] ), 'hidden for Pro' );
	}

	public function test_limit_line_names_the_allowance(): void {
		$this->assertSame( 'The free plugin allows 1 cache rule — Pro removes the limit', ( new ProUpsell() )->limit_line( '1 cache rule' ) );
	}
}
