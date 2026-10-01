<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Admin;

use BlueFairy\ApiGateway\Admin\Assets;
use BlueFairy\ApiGateway\Tests\TestCase;

final class AssetsTest extends TestCase {

	public function test_nothing_is_enqueued_off_the_plugin_screen(): void {
		$assets = new Assets();
		bfag_test_set_screen( null );
		$assets->enqueue( 'index.php' );
		bfag_test_set_screen( 'settings_page_other' );
		$assets->enqueue( 'settings_page_other' );
		$this->assertSame( [], $GLOBALS['bfag_test_enqueued'] );
		$this->assertFalse( $assets->is_our_screen() );
	}

	public function test_style_script_and_bfagadmin_are_enqueued_on_the_plugin_screen(): void {
		$assets = new Assets();
		bfag_test_set_screen( 'settings_page_bfag-gateway' );
		$this->assertTrue( $assets->is_our_screen() );
		$assets->enqueue( 'settings_page_bfag-gateway' );

		$this->assertSame( [ 'bfag-admin-css' ], $GLOBALS['bfag_test_enqueued']['style'] );
		$this->assertSame( [ 'bfag-admin-js' ], $GLOBALS['bfag_test_enqueued']['script'] );
		$l10n = $GLOBALS['bfag_test_enqueued']['localize']['bfag-admin-js']['bfagAdmin'];
		$this->assertSame( 'https://example.com/wp-admin/admin-ajax.php', $l10n['ajaxUrl'] );
		$this->assertSame(
			[
				'purge'   => 'nonce-bfag_ajax_purge_cache',
				'refresh' => 'nonce-bfag_ajax_refresh_kpis',
				'retry'   => 'nonce-bfag_ajax_retry_webhook',
				'toggle'  => 'nonce-bfag_ajax_toggle_rule',
				'dismiss' => 'nonce-bfag_ajax_dismiss_notice',
			],
			$l10n['nonces']
		);
		foreach ( [ 'confirmDelete', 'confirmPurge', 'working', 'purged', 'refreshed', 'retried', 'retryFailed', 'copied', 'error', 'enabled', 'disabled', 'cold' ] as $key ) {
			$this->assertArrayHasKey( $key, $l10n['i18n'] );
		}
	}

	public function test_the_asset_files_exist_and_the_script_reads_bfagadmin(): void {
		$this->assertFileExists( BFAG_DIR . 'assets/css/admin.css' );
		$this->assertFileExists( BFAG_DIR . 'assets/js/admin.js' );
		$js = (string) file_get_contents( BFAG_DIR . 'assets/js/admin.js' );
		$this->assertStringContainsString( 'window.bfagAdmin', $js );
		$this->assertStringNotContainsString( 'jQuery(', $js );
		$this->assertStringNotContainsString( '$(', $js );
		foreach ( [ 'bfag_ajax_purge_cache', 'bfag_ajax_refresh_kpis', 'bfag_ajax_retry_webhook', 'bfag_ajax_toggle_rule', 'data-bfag-copy', 'data-bfag-preset-config', 'data-bfag-confirm' ] as $needle ) {
			$this->assertStringContainsString( $needle, $js );
		}
	}
}
