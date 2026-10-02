<?php
/**
 * Base test case: resets all stub state before every test.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Every unit test extends this so no state leaks between tests.
 */
abstract class TestCase extends PHPUnitTestCase {

	protected FakeWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		bfag_test_reset();
		$this->wpdb = $GLOBALS['wpdb'];
		// T5: the base suite models core — only a registered user exists. A
		// suite from another namespace (the Pro add-on's) keeps the unscripted
		// default until it registers its users.
		if ( str_starts_with( static::class, 'BlueFairy\\ApiGateway\\Tests\\' ) ) {
			$GLOBALS['bfag_test_users'] = [];
		}
	}

	protected function tearDown(): void {
		bfag_test_reset();
		parent::tearDown();
	}

	/**
	 * Fire a hook registered through the stub dispatcher and collect nothing.
	 */
	protected function fire( string $hook, mixed ...$args ): void {
		do_action( $hook, ...$args );
	}

	/**
	 * Build a REST request with headers given as 'Header-Name' => value.
	 *
	 * @param array<string, string> $headers Headers.
	 * @param array<string, mixed>  $query   Query params.
	 */
	protected function request( string $method, string $route, array $headers = [], array $query = [] ): \WP_REST_Request {
		$request = new \WP_REST_Request( $method, $route );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		$request->set_query_params( $query );
		return $request;
	}
}
