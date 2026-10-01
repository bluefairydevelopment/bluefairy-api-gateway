<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Support;

use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Support\RequestContext;
use BlueFairy\ApiGateway\Tests\TestCase;

final class RequestContextTest extends TestCase {

	private function context( array $proxies = [] ): RequestContext {
		update_option( 'bfag_settings', [ 'trusted_proxies' => $proxies ] );
		return new RequestContext( new Settings() );
	}

	public function test_remote_addr_is_used_when_no_proxies_configured(): void {
		$_SERVER['REMOTE_ADDR']          = '203.0.113.10';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9';
		$this->assertSame( '203.0.113.10', $this->context()->client_ip(), 'XFF must be ignored without a trusted proxy' );
	}

	public function test_forwarded_header_is_honoured_only_from_a_trusted_proxy(): void {
		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9';
		$this->assertSame( '198.51.100.9', $this->context( [ '10.0.0.0/8' ] )->client_ip() );
		$this->assertSame( '10.0.0.5', $this->context( [ '172.16.0.0/12' ] )->client_ip() );
	}

	public function test_cf_connecting_ip_wins_over_xff(): void {
		$_SERVER['REMOTE_ADDR']           = '10.0.0.5';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.1';
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '198.51.100.2';
		$this->assertSame( '198.51.100.1', $this->context( [ '10.0.0.5' ] )->client_ip() );
	}

	public function test_walks_xff_from_the_right_skipping_trusted_proxies(): void {
		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 198.51.100.9, 10.0.0.6';
		$this->assertSame( '198.51.100.9', $this->context( [ '10.0.0.0/8' ] )->client_ip(), 'the spoofable left-most entry is not used' );
	}

	public function test_invalid_forwarded_value_falls_back_to_remote_addr(): void {
		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = 'evil, 10.0.0.1';
		$this->assertSame( '10.0.0.5', $this->context( [ '10.0.0.0/8' ] )->client_ip() );
	}

	public function test_all_proxies_in_chain_falls_back_to_remote_addr(): void {
		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1, 10.0.0.2';
		$this->assertSame( '10.0.0.5', $this->context( [ '10.0.0.0/8' ] )->client_ip() );
	}

	public function test_invalid_remote_addr_yields_empty_string(): void {
		$_SERVER['REMOTE_ADDR'] = 'garbage';
		$this->assertSame( '', $this->context()->client_ip() );
	}

	public function test_result_is_cached_until_reset(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
		$ctx                    = $this->context();
		$this->assertSame( '203.0.113.10', $ctx->client_ip() );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.11';
		$this->assertSame( '203.0.113.10', $ctx->client_ip() );
		$ctx->reset();
		$this->assertSame( '203.0.113.11', $ctx->client_ip() );
	}

	public function test_normalize_route_strips_leading_slash(): void {
		$this->assertSame( 'wp/v2/posts', RequestContext::normalize_route( ' /wp/v2/posts ' ) );
		$this->assertSame( '', RequestContext::normalize_route( '/' ) );
	}
}
