<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Support;

use BlueFairy\ApiGateway\Support\SealedValue;
use BlueFairy\ApiGateway\Tests\TestCase;

/**
 * WPMAP-140: the one-time webhook secret is sealed in its transient, so the
 * options table or a shared object cache holds ciphertext only.
 */
final class SealedValueTest extends TestCase {

	private const SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

	public function test_a_sealed_value_opens_under_its_context_and_does_not_carry_the_secret(): void {
		$sealed = SealedValue::seal( self::SECRET, 'bfag_new_secret_5' );
		$this->assertStringStartsWith( SealedValue::PREFIX, $sealed );
		$this->assertStringNotContainsString( self::SECRET, $sealed );
		$this->assertStringNotContainsString( '0123456789abcdef', $sealed, 'not even a fragment of the secret' );
		$this->assertStringNotContainsString( base64_encode( self::SECRET ), $sealed, 'not merely encoded' );
		$this->assertSame( self::SECRET, SealedValue::open( $sealed, 'bfag_new_secret_5' ) );
	}

	public function test_each_seal_uses_a_fresh_nonce(): void {
		$a = SealedValue::seal( self::SECRET, 'ctx' );
		$b = SealedValue::seal( self::SECRET, 'ctx' );
		$this->assertNotSame( $a, $b );
		$this->assertSame( self::SECRET, SealedValue::open( $a, 'ctx' ) );
		$this->assertSame( self::SECRET, SealedValue::open( $b, 'ctx' ) );
	}

	public function test_another_context_does_not_open_it(): void {
		$sealed = SealedValue::seal( self::SECRET, 'bfag_new_secret_5' );
		$this->assertNull( SealedValue::open( $sealed, 'bfag_new_secret_6' ), 'a value copied into another user\'s slot stays shut' );
		$this->assertNull( SealedValue::open( $sealed, '' ) );
	}

	public function test_a_tampered_truncated_or_foreign_value_is_null(): void {
		$sealed = SealedValue::seal( self::SECRET, 'ctx' );
		$raw    = (string) base64_decode( substr( $sealed, strlen( SealedValue::PREFIX ) ), true );
		$flip   = $raw;
		$flip[ strlen( $flip ) - 1 ] = chr( ord( $flip[ strlen( $flip ) - 1 ] ) ^ 1 );

		$this->assertNull( SealedValue::open( SealedValue::PREFIX . base64_encode( $flip ), 'ctx' ), 'one flipped bit' );
		$this->assertNull( SealedValue::open( SealedValue::PREFIX . base64_encode( substr( $raw, 0, 30 ) ), 'ctx' ), 'shorter than nonce + MAC' );
		$this->assertNull( SealedValue::open( SealedValue::PREFIX . '!!not base64!!', 'ctx' ) );
		$this->assertNull( SealedValue::open( self::SECRET, 'ctx' ), 'a plaintext value (an older format, or planted) is not trusted' );
		$this->assertNull( SealedValue::open( false, 'ctx' ), 'an absent transient' );
		$this->assertNull( SealedValue::open( [ 'value' => $sealed ], 'ctx' ) );
		$this->assertSame( self::SECRET, SealedValue::open( $sealed, 'ctx' ), 'control: the untouched value opens' );
	}

	/**
	 * The key is bound to the site's salts. The suite's wp_salt() stub is a
	 * constant, so this runs in a bare `php` child whose wp_salt() answers
	 * from a global: seal under one salt, open under it and under another.
	 */
	public function test_a_value_sealed_under_other_salts_does_not_open(): void {
		$script = <<<'PHP'
<?php
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/' );
function wp_salt( string $scheme = 'auth' ): string { return $GLOBALS['salt'] . $scheme; }
require $argv[1];
$GLOBALS['salt'] = 'site-a';
$sealed          = \BlueFairy\ApiGateway\Support\SealedValue::seal( 'secret', 'ctx' );
$same            = \BlueFairy\ApiGateway\Support\SealedValue::open( $sealed, 'ctx' );
$GLOBALS['salt'] = 'site-b';
$other           = \BlueFairy\ApiGateway\Support\SealedValue::open( $sealed, 'ctx' );
echo json_encode( [ 'same' => $same, 'other' => $other ] );
PHP;
		$path = tempnam( sys_get_temp_dir(), 'bfag-salt-' );
		$this->assertNotFalse( $path );
		file_put_contents( $path, $script );
		try {
			exec( implode( ' ', array_map( 'escapeshellarg', [ PHP_BINARY, $path, BFAG_DIR . 'includes/Support/SealedValue.php' ] ) ) . ' 2>&1', $lines, $code );
		} finally {
			unlink( $path );
		}
		$out = implode( "\n", $lines );
		$this->assertSame( 0, $code, $out );
		$this->assertSame( [ 'same' => 'secret', 'other' => null ], json_decode( $out, true ), $out );
	}

	public function test_an_empty_secret_round_trips(): void {
		$this->assertSame( '', SealedValue::open( SealedValue::seal( '', 'ctx' ), 'ctx' ) );
	}

	public function test_the_base_format_is_its_own(): void {
		$this->assertSame( 'bfag1:', SealedValue::PREFIX, 'not the Pro add-on\'s bfagp1: — the two copies never open each other\'s values' );
	}
}
