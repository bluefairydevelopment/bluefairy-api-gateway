<?php
/**
 * Webhook-module test stubs: WooCommerce doubles.
 *
 * `WC_Order` and `WC_Product` are plain doubles exposing only the getters
 * EventRegistry reads. `WooCommerce` itself is declared on demand by
 * bfag_test_enable_woocommerce() so the "events absent without WooCommerce"
 * assertions can run in the same suite (the enabling test runs in its own
 * process).
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions, Squiz.Commenting, Generic.Files.OneObjectStructurePerFile, Universal.Files.SeparateFunctionsFromOO

if ( ! class_exists( 'WC_Order', false ) ) {
	class WC_Order {
		/**
		 * @param array<string, mixed> $data Field overrides.
		 */
		public function __construct( private array $data = [] ) {}
		public function get_id(): int {
			return (int) ( $this->data['id'] ?? 0 );
		}
		public function get_status(): string {
			return (string) ( $this->data['status'] ?? 'pending' );
		}
		public function get_currency(): string {
			return (string) ( $this->data['currency'] ?? 'USD' );
		}
		public function get_total(): string {
			return (string) ( $this->data['total'] ?? '0' );
		}
		public function get_customer_id(): int {
			return (int) ( $this->data['customer_id'] ?? 0 );
		}
		public function get_billing_email(): string {
			return (string) ( $this->data['email'] ?? '' );
		}
		public function get_date_created(): ?DateTimeInterface {
			return $this->data['date_created'] ?? null;
		}
	}
}

if ( ! class_exists( 'WC_Product', false ) ) {
	class WC_Product {
		/**
		 * @param array<string, mixed> $data Field overrides.
		 */
		public function __construct( private array $data = [] ) {}
		public function get_id(): int {
			return (int) ( $this->data['id'] ?? 0 );
		}
		public function get_name(): string {
			return (string) ( $this->data['name'] ?? '' );
		}
		public function get_sku(): string {
			return (string) ( $this->data['sku'] ?? '' );
		}
		public function get_status(): string {
			return (string) ( $this->data['status'] ?? 'publish' );
		}
		public function get_price(): string {
			return (string) ( $this->data['price'] ?? '0' );
		}
		public function get_type(): string {
			return (string) ( $this->data['type'] ?? 'simple' );
		}
	}
}

if ( ! function_exists( 'get_comment' ) ) {
	/**
	 * As core: the stored comment, or null.
	 *
	 * @param mixed $comment Comment id.
	 */
	function get_comment( mixed $comment = null ): ?object {
		return $GLOBALS['bfag_test_comments'][ (int) $comment ] ?? null;
	}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	/**
	 * As WooCommerce: the stored order, or false.
	 *
	 * @param mixed $order Order id.
	 */
	function wc_get_order( mixed $order = false ): WC_Order|false {
		return $GLOBALS['bfag_test_wc_orders'][ (int) $order ] ?? false;
	}
}

if ( ! function_exists( 'wc_get_product' ) ) {
	/**
	 * As WooCommerce: the stored product, or null.
	 *
	 * @param mixed $product Product id.
	 */
	function wc_get_product( mixed $product = false ): ?WC_Product {
		return $GLOBALS['bfag_test_wc_products'][ (int) $product ] ?? null;
	}
}

/**
 * Declare the `WooCommerce` class so `class_exists( 'WooCommerce' )` is true
 * for the rest of the process. Call it only from a test that runs in its own
 * process (#[RunInSeparateProcess]); a class cannot be undeclared.
 */
function bfag_test_enable_woocommerce(): void {
	if ( class_exists( 'WooCommerce', false ) ) {
		return;
	}
	final class WooCommerce {}
}

if ( ! function_exists( 'fastcgi_finish_request' ) ) {
	/**
	 * PHP-FPM only; absent under the CLI runner. Records, per call, how many
	 * HTTP requests had been sent when the response was finished, so a test
	 * can assert the order. Reset it yourself: bfag_test_reset() does not
	 * know this global.
	 */
	function fastcgi_finish_request(): bool {
		$GLOBALS['bfag_test_finished'][] = count( $GLOBALS['bfag_test_http_calls'] ?? [] );
		return true;
	}
}
