<?php
/**
 * Admin stylesheet and script.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Admin;

/**
 * Enqueues `assets/css/admin.css` and `assets/js/admin.js` on the plugin's
 * own screen only, and localises `bfagAdmin` with the AJAX URL, one nonce per
 * AJAX action and the strings the script shows.
 */
final class Assets {

	public const SCREEN_ID    = 'settings_page_bfag-gateway';
	public const STYLE_HANDLE = 'bfag-admin-css';
	public const JS_HANDLE    = 'bfag-admin-js';

	/**
	 * Hooked to admin_enqueue_scripts.
	 *
	 * @param string $hook_suffix The current admin page hook (unused; the screen id is authoritative).
	 */
	public function enqueue( string $hook_suffix = '' ): void {
		unset( $hook_suffix );
		if ( ! $this->is_our_screen() ) {
			return;
		}

		wp_enqueue_style( self::STYLE_HANDLE, BFAG_URL . 'assets/css/admin.css', [], BFAG_VERSION );
		wp_enqueue_script( self::JS_HANDLE, BFAG_URL . 'assets/js/admin.js', [], BFAG_VERSION, true );
		wp_localize_script( self::JS_HANDLE, 'bfagAdmin', $this->localized() );
	}

	/**
	 * Is the current screen the plugin's settings page?
	 */
	public function is_our_screen(): bool {
		$screen = get_current_screen();
		return is_object( $screen ) && self::SCREEN_ID === $screen->id;
	}

	/**
	 * The `bfagAdmin` object.
	 *
	 * @return array<string, mixed>
	 */
	public function localized(): array {
		return [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonces'  => [
				'purge'   => wp_create_nonce( 'bfag_ajax_purge_cache' ),
				'refresh' => wp_create_nonce( 'bfag_ajax_refresh_kpis' ),
				'retry'   => wp_create_nonce( 'bfag_ajax_retry_webhook' ),
				'toggle'  => wp_create_nonce( 'bfag_ajax_toggle_rule' ),
			],
			'i18n'    => [
				'confirmDelete' => __( 'Delete this item? This cannot be undone.', 'bluefairy-api-gateway' ),
				'confirmPurge'  => __( 'Purge every cached response?', 'bluefairy-api-gateway' ),
				'working'       => __( 'Working…', 'bluefairy-api-gateway' ),
				'purged'        => __( 'Cache purged.', 'bluefairy-api-gateway' ),
				'refreshed'     => __( 'Updated.', 'bluefairy-api-gateway' ),
				'retried'       => __( 'Delivered.', 'bluefairy-api-gateway' ),
				'retryFailed'   => __( 'Delivery failed again.', 'bluefairy-api-gateway' ),
				'copied'        => __( 'Copied.', 'bluefairy-api-gateway' ),
				'error'         => __( 'Something went wrong. Reload the page and try again.', 'bluefairy-api-gateway' ),
				'enabled'       => __( 'Enabled', 'bluefairy-api-gateway' ),
				'disabled'      => __( 'Disabled', 'bluefairy-api-gateway' ),
				'cold'          => '—',
			],
		];
	}
}
