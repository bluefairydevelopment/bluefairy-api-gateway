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

	/**
	 * Every line must describe something Pro ships and this plugin lacks
	 * (Salesforce/HubSpot/Mailchimp presets only) — and no line may sell a
	 * limit: both plugins allow the same 500 rows (WPMAP-142, WordPress.org
	 * guideline 5).
	 */
	public function test_the_feature_list_claims_only_what_pro_ships(): void {
		$upsell = new ProUpsell();
		$html   = bfag_test_render( [ $upsell, 'render' ] );

		foreach (
			[
				'Automatic webhook retries and failure alert emails',
				'Salesforce, HubSpot and Mailchimp presets and a verified incoming-webhook endpoint',
				'Purges narrowed to the tags a change touches',
				'Rate limits per user and per API key, with DDoS auto-block',
				'API keys with scopes for integrations',
				'WPGraphQL query caching',
			] as $line
		) {
			$this->assertContains( $line, $upsell->features() );
			$this->assertStringContainsString( esc_html( $line ), $html );
		}
		$this->assertCount( 6, $upsell->features(), 'nothing beyond the six lines above' );
		$this->assertStringContainsString( 'This plugin caches, rate-limits and signs webhooks. Pro adds the parts integrations ask for:', $html );
		$this->assertDoesNotMatchRegularExpression( '/\bbans?\b|\bbanned\b|removes the limit/i', $html, 'NF-16/NF-18' );
		$this->assertDoesNotMatchRegularExpression( '/\b500\b|up to \d|\bfree:|\b(rules?|webhooks?) limits?\b|\bmore (cache |rate-limit )?(rules|webhooks)\b|one of each|single attempt|three webhooks/i', $html, 'WPMAP-142: no limit is sold' );
		foreach ( [ 'Unlimited', 'unlimited', 'Slack', 'Zapier' ] as $false_claim ) {
			$this->assertStringNotContainsString( $false_claim, $html, "the panel must not promise \"{$false_claim}\"" );
		}
	}

	public function test_there_is_no_limit_line_to_sell_any_more(): void {
		$this->assertFalse( method_exists( ProUpsell::class, 'limit_line' ), 'WPMAP-142: the at-limit line is Page::limit_line(), the same with or without Pro' );
	}
}
