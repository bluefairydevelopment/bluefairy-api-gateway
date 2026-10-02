<?php
/**
 * The Pro side panel.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A right-column panel on every tab listing what the Pro add-on adds, hidden
 * the moment `bfag_is_pro` says the add-on is running — an advert for
 * something the admin already owns makes the product look like it does not
 * know its own state.
 *
 * Every feature named here ships in Pro today and is absent from this
 * plugin; nothing aspirational, and no limit — this plugin and Pro share the
 * same row limits (WPMAP-142, WordPress.org guideline 5).
 */
final class ProUpsell {

	public const PRODUCT_URL = 'https://wordpress.bluefairydevelopment.com/product/bluefairy-api-gateway-pro/';

	/**
	 * Is the Pro add-on active?
	 */
	public function is_pro(): bool {
		/**
		 * Whether the Pro add-on is active. Hides the upsell and is reported
		 * by `GET /bfag/v1/status`.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $is_pro Default false.
		 */
		return (bool) apply_filters( 'bfag_is_pro', false );
	}

	/**
	 * The features the panel lists.
	 *
	 * @return list<string>
	 */
	public function features(): array {
		return [
			__( 'Purges narrowed to the tags a change touches', 'bluefairy-api-gateway' ),
			__( 'Rate limits per user and per API key, with DDoS auto-block', 'bluefairy-api-gateway' ),
			__( 'Automatic webhook retries and failure alert emails', 'bluefairy-api-gateway' ),
			__( 'Salesforce, HubSpot and Mailchimp presets and a verified incoming-webhook endpoint', 'bluefairy-api-gateway' ),
			__( 'API keys with scopes for integrations', 'bluefairy-api-gateway' ),
			__( 'WPGraphQL query caching', 'bluefairy-api-gateway' ),
		];
	}

	/**
	 * Print the panel, unless Pro is active.
	 */
	public function render(): void {
		if ( $this->is_pro() ) {
			return;
		}
		Page::include_view(
			'upsell',
			[
				'product_url' => self::PRODUCT_URL,
				'features'    => $this->features(),
			]
		);
	}
}
