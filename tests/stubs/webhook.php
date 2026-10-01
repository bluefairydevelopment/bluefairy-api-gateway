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
