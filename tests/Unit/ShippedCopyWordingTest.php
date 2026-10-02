<?php
/**
 * WPMAP-140: the copy a buyer reads says "block", never "ban" — and
 * (WPMAP-142) never that the free plugin is held to fewer rows than Pro.
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
	 * WPMAP-142: a pre-1.0.1 claim that the free plugin is held to fewer rules
	 * or webhooks than Pro, or that Pro raises a limit.
	 */
	private const SOLD_LIMIT = '/free plugin allows|\\bPro (?:allows|raises|lifts|removes)\\b|\\(free: |one of each|\\bup to (?:three|3) (?:signed |HMAC-signed )?(?:outbound )?webhooks\\b|\\b(?:one|1) (?:cache |rate-limit )?rules? in the free plugin\\b|`1 of 1`|`N of 3`/i';

	/**
	 * Every file a buyer reads, and every PHP file whose strings reach the
	 * screen, says the limits are the same with or without Pro. readme.txt's
	 * Changelog and Upgrade Notice are history (the 1.0.0 entry rightly says
	 * three webhooks) and are left out; so are docs/ARCHITECTURE.md and
	 * docs/COMPARISON.md, which state the rule.
	 */
	public function test_no_customer_facing_text_sells_a_row_limit(): void {
		$files = array_merge( self::COPY, [] );
		foreach ( [ 'includes', 'admin' ] as $dir ) {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( BFAG_DIR . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( 'php' === $file->getExtension() ) {
					$files[] = substr( (string) $file->getPathname(), strlen( BFAG_DIR ) );
				}
			}
		}
		$this->assertGreaterThan( 40, count( $files ), 'denominator: the copy files and every PHP file under includes/ and admin/' );

		$hits = [];
		foreach ( $files as $file ) {
			$text = (string) file_get_contents( BFAG_DIR . $file );
			if ( 'readme.txt' === $file ) {
				$cut = strpos( $text, '== Changelog ==' );
				$this->assertNotFalse( $cut, 'control: the readme has a Changelog to leave out' );
				$text = substr( $text, 0, $cut );
			}
			foreach ( preg_split( '/\R/', $text ) ?: [] as $n => $line ) {
				if ( 1 === preg_match( self::SOLD_LIMIT, $line ) ) {
					$hits[] = $file . ':' . ( $n + 1 ) . ': ' . trim( $line );
				}
			}
		}
		$this->assertSame( [], $hits );
	}

	/**
	 * The instrument: SOLD_LIMIT finds every pre-1.0.1 phrasing, and none of
	 * the 1.0.1 ones.
	 */
	public function test_the_sold_limit_pattern_finds_the_old_copy_and_not_the_new(): void {
		foreach (
			[
				'The free plugin allows 1 cache rule — Pro allows up to 500',
				'Cache rule not added: the free plugin allows one rule.',
				'Up to 500 cache rules and rate-limit rules (free: one of each)',
				'Up to 500 webhooks with automatic retries and failure alerts (free: three, single attempt)',
				'This plugin caches, rate-limits and signs webhooks. Pro allows up to 500 rules and webhooks',
				'One cache rule in the free plugin (seeded as `**`, "All routes", 60 s).',
				'One rule in the free plugin (seeded as 300 requests per 60 s on `**`',
				'**Signed outbound webhooks.** Up to three webhooks, each subscribing to one event:',
				'<li>Up to three HMAC-signed outbound webhooks — post, user, comment</li>',
				'One cache rule, one rate-limit rule and three webhooks. Pro raises each to 500',
				'The counter beside the heading reads `1 of 1` in',
				'The counter reads `N of 3`: three webhooks is the free',
			] as $old
		) {
			$this->assertSame( 1, preg_match( self::SOLD_LIMIT, $old ), $old );
		}
		foreach (
			[
				'Up to 500 cache rules, each matched by route glob',
				'This site allows 500 cache rules and all of them are in use. Edit or delete one to add another.',
				'Maximum number of cache rules: 500 by default, with or without Pro (1 before 1.0.1).',
				'on, with one seeded rule each; webhooks wait for you to add one.',
				'Rate limiting counts by IP address; Pro adds per-user and per-API-key counting.',
				'Activation creates six database tables, seeds one cache rule and one rate-limit rule',
			] as $new
		) {
			$this->assertSame( 0, preg_match( self::SOLD_LIMIT, $new ), $new );
		}
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
