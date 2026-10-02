<?php
/**
 * WPMAP-140: the copy a buyer reads says "block", never "ban".
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit;

use BlueFairy\ApiGateway\Tests\TestCase;

/**
 * The screens say block / blocked / unblock (NF-16), and docs/COMPARISON.md
 * forbids claiming "ban". The plugin header's Description said "DDoS
 * auto-ban"; this sweeps every customer-facing file the zip ships for the
 * word. docs/ARCHITECTURE.md (the developer contract) and docs/COMPARISON.md
 * (which states the rule) are not customer copy.
 */
final class ShippedCopyWordingTest extends TestCase {

	/**
	 * Files a buyer reads, relative to the plugin root.
	 *
	 * @var list<string>
	 */
	private const COPY = [ 'bluefairy-api-gateway.php', 'readme.txt', 'README.md', 'FEATURES.md', 'docs/MARKETING.txt', 'composer.json' ];

	private const BAN = '/\b(?:auto-)?(?:un)?ban(?:s|ned|ning)?\b/i';

	public function test_no_customer_facing_file_says_ban(): void {
		$hits = [];
		foreach ( self::COPY as $file ) {
			$text = file_get_contents( BFAG_DIR . $file );
			$this->assertIsString( $text, $file );
			$this->assertNotSame( '', $text, "$file is not empty" );
			foreach ( preg_split( '/\R/', $text ) ?: [] as $n => $line ) {
				if ( 1 === preg_match( self::BAN, $line ) ) {
					$hits[] = $file . ':' . ( $n + 1 ) . ': ' . trim( $line );
				}
			}
		}
		$this->assertSame( [], $hits );
	}

	public function test_the_header_description_says_auto_block(): void {
		$this->assertMatchesRegularExpression( '/^ \* Description:.*\bDDoS auto-block\b/m', (string) file_get_contents( BFAG_DIR . 'bluefairy-api-gateway.php' ) );
	}

	/**
	 * The instrument: the pattern finds each form of the word, and not a
	 * word that merely contains it.
	 */
	public function test_the_pattern_finds_the_word_and_only_the_word(): void {
		foreach ( [ 'DDoS auto-ban', 'a ban list', 'Bans expire', 'banned for an hour', 'unban it', 'banning' ] as $line ) {
			$this->assertSame( 1, preg_match( self::BAN, $line ), $line );
		}
		foreach ( [ 'banner', 'urban', 'bandwidth', 'Banff', 'auto-block' ] as $line ) {
			$this->assertSame( 0, preg_match( self::BAN, $line ), $line );
		}
	}
}
