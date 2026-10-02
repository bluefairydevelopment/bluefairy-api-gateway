<?php
/**
 * U-22 / WPMAP-111: a response whose REST callback called PHP setcookie()
 * is never stored.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Cache;

use BlueFairy\ApiGateway\Cache\CacheKeyGenerator;
use BlueFairy\ApiGateway\Cache\CacheManager;
use BlueFairy\ApiGateway\Cache\CacheRuleRepository;
use BlueFairy\ApiGateway\Cache\CacheStatsRepository;
use BlueFairy\ApiGateway\Cache\CacheStore;
use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Tests\TestCase;

/**
 * Core and WooCommerce set cookies with setcookie(), which never reaches the
 * WP_REST_Response headers (core's WP_HTTP_Response holds only what its
 * constructor, set_headers() and header() were given). Verified before the
 * fix by the SAPI test below: the cookie-setting response was labelled MISS
 * and stored.
 *
 * Two instruments: the header seam (the CLI SAPI PHPUnit runs under records
 * no headers at all, so the seam is the only way to plant one in-process),
 * and a real `php -S` run of the default path, headers_list() included.
 */
final class PhpSetCookieTest extends TestCase {

	private CacheStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->store = new CacheStore();
		update_option( 'bfag_settings', Settings::DEFAULTS );
		wp_cache_set(
			CacheRuleRepository::CACHE_KEY,
			[
				[
					'id'          => 1,
					'route_glob'  => '**',
					'ttl_seconds' => 60,
					'tags'        => '',
					'headers'     => null,
					'enabled'     => 1,
				],
			],
			'bfag'
		);
	}

	/**
	 * @param list<string> $php_headers What PHP has queued.
	 */
	private function manager( array $php_headers ): CacheManager {
		return new CacheManager( $this->store, new CacheKeyGenerator( $this->store ), new CacheRuleRepository(), new CacheStatsRepository(), new Settings(), static fn(): array => $php_headers );
	}

	private function store_through( CacheManager $manager ): string {
		$response = new \WP_REST_Response( [ 'cart' => 'guest-1' ], 200, [ 'Content-Type' => 'application/json' ] );
		$out      = $manager->maybe_store( $response, new \WP_REST_Server(), $this->request( 'GET', '/wc/store/v1/cart' ) );
		return (string) ( $out->get_headers()['X-BF-Cache'] ?? '' );
	}

	public function test_a_php_set_cookie_header_makes_the_response_uncacheable(): void {
		foreach ( [ 'Set-Cookie: wp_woocommerce_session_abc=guest-1; path=/', 'set-cookie: a=b', ' Set-Cookie:x=1' ] as $line ) {
			$this->store = new CacheStore();
			$GLOBALS['bfag_test_transients'] = [];
			$manager     = $this->manager( [ 'X-Powered-By: PHP', $line ] );
			$this->assertFalse( $manager->is_cacheable_response( new \WP_REST_Response( [], 200 ) ), $line );
			$this->assertSame( 'BYPASS', $this->store_through( $manager ), $line );
			$this->assertSame( [], $GLOBALS['bfag_test_transients'], "{$line}: nothing stored" );
		}
	}

	/**
	 * Twin: other PHP headers — including one that merely mentions a cookie
	 * — leave the response cacheable.
	 */
	public function test_other_php_headers_leave_the_response_cacheable(): void {
		$manager = $this->manager( [ 'X-Powered-By: PHP', 'Content-Type: application/json', 'X-Set-Cookie-Policy: none' ] );
		$this->assertTrue( $manager->is_cacheable_response( new \WP_REST_Response( [], 200 ) ) );
		$this->assertSame( 'MISS', $this->store_through( $manager ) );
		$this->assertCount( 1, $GLOBALS['bfag_test_transients'], 'stored' );
	}

	/**
	 * The default path (no seam) under a SAPI that records headers: the
	 * fixture router is served by `php -S` and drives the real CacheManager
	 * with headers_list().
	 */
	public function test_under_a_web_sapi_a_setcookie_response_is_bypassed_and_a_plain_one_stored(): void {
		$server = $this->start_server();
		try {
			$cookie = $this->fetch( $server['port'], '/?cookie=1' );
			$plain  = $this->fetch( $server['port'], '/?cookie=0' );
		} finally {
			proc_terminate( $server['process'] );
			proc_close( $server['process'] );
		}

		$this->assertSame( 'cli-server', $cookie['sapi'] );
		$this->assertSame( [ 'Set-Cookie: wp_woocommerce_session_abc=guest-1-session; path=/' ], $cookie['set_cookies'], 'control: the SAPI recorded the cookie' );
		$this->assertSame( 'BYPASS', $cookie['label'] );
		$this->assertSame( 0, $cookie['stored'], 'one guest\'s cart is never stored' );

		$this->assertSame( [], $plain['set_cookies'] );
		$this->assertSame( 'MISS', $plain['label'], 'twin: without a cookie the same response is stored' );
		$this->assertSame( 1, $plain['stored'] );
	}

	/**
	 * @return array{process: resource, port: int}
	 */
	private function start_server(): array {
		$router = dirname( __DIR__, 2 ) . '/fixtures/set-cookie-router.php';
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$port    = random_int( 20000, 45000 );
			$process = proc_open(
				[ PHP_BINARY, '-S', '127.0.0.1:' . $port, $router ],
				[
					0 => [ 'file', '/dev/null', 'r' ],
					1 => [ 'file', '/dev/null', 'w' ],
					2 => [ 'file', '/dev/null', 'w' ],
				],
				$pipes
			);
			$this->assertIsResource( $process );
			for ( $wait = 0; $wait < 50; $wait++ ) {
				$socket = @fsockopen( '127.0.0.1', $port, $errno, $errstr, 0.1 ); // phpcs:ignore
				if ( false !== $socket ) {
					fclose( $socket );
					return [
						'process' => $process,
						'port'    => $port,
					];
				}
				if ( ! proc_get_status( $process )['running'] ) {
					break; // Port taken: try another.
				}
				usleep( 100000 );
			}
			proc_terminate( $process );
			proc_close( $process );
		}
		$this->fail( 'could not start php -S' );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fetch( int $port, string $path ): array {
		$body = file_get_contents( 'http://127.0.0.1:' . $port . $path, false, stream_context_create( [ 'http' => [ 'timeout' => 10 ] ] ) );
		$this->assertIsString( $body );
		$json = json_decode( $body, true );
		$this->assertIsArray( $json, $body );
		return $json;
	}
}
