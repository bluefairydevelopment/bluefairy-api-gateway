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
	 * Every line must describe what Pro ships: a 500-row cap (never
	 * "unlimited") and Salesforce/HubSpot/Mailchimp presets only.
	 */
	public function test_the_feature_list_claims_only_what_pro_ships(): void {
		$upsell = new ProUpsell();
		$html   = bfag_test_render( [ $upsell, 'render' ] );

		foreach (
			[
				'Up to 500 cache rules and rate-limit rules (free: one of each)',
				'Up to 500 webhooks with automatic retries and failure alerts (free: three, single attempt)',
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
		$this->assertStringContainsString( 'Pro allows up to 500 rules and webhooks and adds the parts integrations ask for:', $html, 'NF-18: the ceiling, not "removes the limits"' );
		$this->assertDoesNotMatchRegularExpression( '/\bbans?\b|\bbanned\b|removes the limit/i', $html, 'NF-16/NF-18' );
		foreach ( [ 'Unlimited', 'unlimited', 'Slack', 'Zapier' ] as $false_claim ) {
			$this->assertStringNotContainsString( $false_claim, $html, "the panel must not promise \"{$false_claim}\"" );
		}
	}

	public function test_limit_line_names_the_allowance(): void {
		$this->assertSame( 'The free plugin allows 1 cache rule — Pro allows up to 500', ( new ProUpsell() )->limit_line( '1 cache rule' ), 'NF-18: Pro has a ceiling too' );
	}
}
