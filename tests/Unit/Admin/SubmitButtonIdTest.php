<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Admin;

use BlueFairy\ApiGateway\Admin\CachePage;
use BlueFairy\ApiGateway\Admin\ProUpsell;
use BlueFairy\ApiGateway\Admin\RateLimitPage;
use BlueFairy\ApiGateway\Admin\SettingsPage;
use BlueFairy\ApiGateway\Admin\WebhookPage;
use BlueFairy\ApiGateway\Cache\CacheRuleRepository;
use BlueFairy\ApiGateway\Cache\CacheStore;
use BlueFairy\ApiGateway\Capabilities;
use BlueFairy\ApiGateway\RateLimit\RateLimitRuleRepository;
use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Tests\TestCase;
use BlueFairy\ApiGateway\Webhook\DeliveryRepository;
use BlueFairy\ApiGateway\Webhook\EventRegistry;
use BlueFairy\ApiGateway\Webhook\WebhookDispatcher;
use BlueFairy\ApiGateway\Webhook\WebhookRepository;

/**
 * D9: the Pro add-on renders its own submit_button() on the same tab, so
 * no base form may use core's default `id="submit"` — two elements with one
 * id is invalid HTML and breaks label/for and the admin JS.
 */
final class SubmitButtonIdTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		bfag_test_grant( Capabilities::MANAGE );
	}

	/**
	 * Render every base form (each add form shown under its limit).
	 *
	 * @return array<string, string> tab => html.
	 */
	private function render_all(): array {
		$html = [];

		$this->wpdb->queue( 'get_results', [] );
		$this->wpdb->queue( 'get_var', 0 );
		$html['cache'] = bfag_test_render( [ new CachePage( new CacheRuleRepository(), new CacheStore(), new ProUpsell() ), 'render' ] );

		$this->wpdb->queue( 'get_results', [] );
		$this->wpdb->queue( 'get_var', 0 );
		$html['ratelimit'] = bfag_test_render( [ new RateLimitPage( new RateLimitRuleRepository(), new ProUpsell() ), 'render' ] );

		$settings = new Settings();
		$delivs   = new DeliveryRepository( $settings );
		$this->wpdb->queue( 'get_results', [] );
		$this->wpdb->queue( 'get_var', 0 );
		$html['webhooks'] = bfag_test_render( [ new WebhookPage( new WebhookRepository(), $delivs, new WebhookDispatcher( $delivs, $settings ), new EventRegistry(), new ProUpsell() ), 'render' ] );

		$html['settings'] = bfag_test_render( [ new SettingsPage( $settings ), 'render' ] );

		return $html;
	}

	/**
	 * The ids of every submit button in $html.
	 *
	 * @return list<string>
	 */
	private static function submit_ids( string $html ): array {
		preg_match_all( '/<button type="submit"[^>]*\sid="([^"]*)"/', $html, $m );
		return $m[1];
	}

	public function test_the_scanner_sees_a_default_submit_id(): void {
		$this->assertSame( [ 'submit' ], self::submit_ids( bfag_test_render( static fn() => submit_button( 'Go' ) ) ), 'the stub renders core\'s default id, so a regression is visible' );
	}

	public function test_every_form_has_its_own_submit_id_and_none_is_the_default(): void {
		$html = $this->render_all();
		$ids  = [];
		foreach ( $html as $tab => $out ) {
			$found = self::submit_ids( $out );
			$this->assertCount( 1, $found, "$tab renders exactly one submit button" );
			$this->assertStringNotContainsString( 'id="submit"', $out, "$tab must not use the default id" );
			$this->assertStringContainsString( 'name="bfag_save"', $out, "$tab names its button bfag_save" );
			$ids[ $tab ] = $found[0];
		}
		$this->assertSame(
			[
				'cache'     => 'bfag-cache-save',
				'ratelimit' => 'bfag-ratelimit-save',
				'webhooks'  => 'bfag-webhook-save',
				'settings'  => 'bfag-settings-save',
			],
			$ids
		);
		$this->assertSame( count( $ids ), count( array_unique( $ids ) ), 'unique per form' );
	}
}
