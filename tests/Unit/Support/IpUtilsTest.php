<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Support;

use BlueFairy\ApiGateway\Support\IpUtils;
use BlueFairy\ApiGateway\Tests\TestCase;

final class IpUtilsTest extends TestCase {

	public function test_is_valid_ip(): void {
		$this->assertTrue( IpUtils::is_valid_ip( '192.0.2.1' ) );
		$this->assertTrue( IpUtils::is_valid_ip( '2001:db8::1' ) );
		$this->assertFalse( IpUtils::is_valid_ip( '999.1.1.1' ) );
		$this->assertFalse( IpUtils::is_valid_ip( '' ) );
		$this->assertFalse( IpUtils::is_valid_ip( '192.0.2.1/24' ) );
	}

	public function test_is_valid_cidr_or_ip(): void {
		$this->assertTrue( IpUtils::is_valid_cidr_or_ip( '10.0.0.0/8' ) );
		$this->assertTrue( IpUtils::is_valid_cidr_or_ip( '10.0.0.1' ) );
		$this->assertTrue( IpUtils::is_valid_cidr_or_ip( '2001:db8::/32' ) );
		$this->assertTrue( IpUtils::is_valid_cidr_or_ip( '0.0.0.0/0' ) );
		$this->assertFalse( IpUtils::is_valid_cidr_or_ip( '10.0.0.0/33' ) );
		$this->assertFalse( IpUtils::is_valid_cidr_or_ip( '2001:db8::/129' ) );
		$this->assertFalse( IpUtils::is_valid_cidr_or_ip( '10.0.0.0/x' ) );
		$this->assertFalse( IpUtils::is_valid_cidr_or_ip( 'host/8' ) );
	}

	/**
	 * @dataProvider ranges
	 */
	public function test_in_range( string $ip, string $cidr, bool $expected ): void {
		$this->assertSame( $expected, IpUtils::in_range( $ip, $cidr ) );
	}

	/** @return array<string, array{string, string, bool}> */
	public static function ranges(): array {
		return [
			'inside /8'         => [ '10.200.3.4', '10.0.0.0/8', true ],
			'outside /8'        => [ '11.0.0.1', '10.0.0.0/8', false ],
			'inside /25 low'    => [ '192.0.2.127', '192.0.2.0/25', true ],
			'outside /25 high'  => [ '192.0.2.128', '192.0.2.0/25', false ],
			'exact /32'         => [ '192.0.2.5', '192.0.2.5/32', true ],
			'other /32'         => [ '192.0.2.6', '192.0.2.5/32', false ],
			'bare ip match'     => [ '192.0.2.5', '192.0.2.5', true ],
			'bare ip mismatch'  => [ '192.0.2.5', '192.0.2.6', false ],
			'ipv6 inside'       => [ '2001:db8:0:1::7', '2001:db8::/32', true ],
			'ipv6 outside'      => [ '2001:db9::1', '2001:db8::/32', false ],
			'ipv6 /0'           => [ '::1', '::/0', true ],
			'mixed families'    => [ '192.0.2.1', '2001:db8::/32', false ],
			'invalid ip'        => [ 'nope', '10.0.0.0/8', false ],
			'invalid cidr'      => [ '10.0.0.1', '10.0.0.0/99', false ],
			'cloudflare sample' => [ '173.245.48.9', '173.245.48.0/20', true ],
		];
	}

	public function test_in_any_range(): void {
		$ranges = [ '10.0.0.0/8', '192.0.2.0/24' ];
		$this->assertTrue( IpUtils::in_any_range( '192.0.2.77', $ranges ) );
		$this->assertFalse( IpUtils::in_any_range( '198.51.100.1', $ranges ) );
		$this->assertFalse( IpUtils::in_any_range( '198.51.100.1', [] ) );
	}
}
