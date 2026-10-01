<?php
/**
 * The Pro side panel.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Admin;

/**
 * A right-column panel on every tab listing what the Pro add-on adds, hidden
 * the moment `bfag_is_pro` says the add-on is running — an advert for
 * something the admin already owns makes the product look like it does not
 * know its own state.
 *
 * Every feature named here ships in Pro today; nothing aspirational.
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
			__( 'Unlimited cache rules and rate-limit rules', 'bluefairy-api-gateway' ),
			__( 'Per-content-type cache rules with tag purging', 'bluefairy-api-gateway' ),
			__( 'Rate limits per user and per API key, with auto-ban', 'bluefairy-api-gateway' ),
			__( 'Unlimited webhooks with automatic retries and alerts', 'bluefairy-api-gateway' ),
			__( 'CRM and chat presets: HubSpot, Slack, Zapier and more', 'bluefairy-api-gateway' ),
			__( 'Scoped API keys for integrations', 'bluefairy-api-gateway' ),
		];
	}

	/**
	 * The one-line limit notice shown in place of an add form.
	 *
	 * @param string $what   What is limited, e.g. "1 cache rule".
	 */
	public function limit_line( string $what ): string {
		return sprintf(
			/* translators: %s: the free plugin's allowance, e.g. "1 cache rule" */
			__( 'The free plugin allows %s — Pro removes the limit', 'bluefairy-api-gateway' ),
			$what
		);
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
