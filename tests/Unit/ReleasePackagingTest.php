<?php
/**
 * WPMAP-142: the release metadata agrees, the shipped copy points only at
 * files the zip contains, and readme.txt meets the WordPress.org rules.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit;

use BlueFairy\ApiGateway\Tests\TestCase;

/**
 * Packaging checks that read the repository, not the runtime.
 *
 * The zip is `git archive` of HEAD with `.gitattributes` export-ignore rules,
 * so "shipped" means "not export-ignored". A shipped file that tells the
 * reader to look in an export-ignored one sends them to a file they do not
 * have — which is what readme.txt and README.md did with FEATURES.md once it
 * left the zip.
 */
final class ReleasePackagingTest extends TestCase {

	/**
	 * Customer-readable files that ship.
	 *
	 * @var list<string>
	 */
	private const SHIPPED_COPY = [ 'readme.txt', 'README.md', 'CHANGELOG.md', 'bluefairy-api-gateway.php' ];

	private static function read( string $file ): string {
		$text = file_get_contents( BFAG_DIR . $file );
		self::assertIsString( $text, $file );
		return $text;
	}

	/**
	 * Paths the export-ignore rules keep out of the zip, without the leading slash.
	 *
	 * @return list<string>
	 */
	private static function export_ignored(): array {
		$paths = [];
		foreach ( preg_split( '/\R/', self::read( '.gitattributes' ) ) ?: [] as $line ) {
			if ( 1 === preg_match( '#^/(\S+)\s+export-ignore\s*$#', $line, $m ) ) {
				$paths[] = $m[1];
			}
		}
		return $paths;
	}

	/**
	 * The version in the header, BFAG_VERSION, the readme's Stable tag, the
	 * readme's newest changelog and upgrade-notice entries and CHANGELOG.md's
	 * newest entry are one number.
	 */
	public function test_every_version_marker_names_the_same_release(): void {
		$main   = self::read( 'bluefairy-api-gateway.php' );
		$readme = self::read( 'readme.txt' );
		$found  = [];

		preg_match( '/^ \* Version:\s+(\S+)$/m', $main, $m );
		$found['header Version'] = $m[1] ?? '';
		preg_match( "/define\( 'BFAG_VERSION', '([^']+)' \);/", $main, $m );
		$found['BFAG_VERSION'] = $m[1] ?? '';
		preg_match( '/^Stable tag:\s*(\S+)$/m', $readme, $m );
		$found['readme Stable tag'] = $m[1] ?? '';
		preg_match( '/^== Changelog ==\s+= ([^=\s]+) =/m', $readme, $m );
		$found['readme changelog'] = $m[1] ?? '';
		preg_match( '/^== Upgrade Notice ==\s+= ([^=\s]+) =/m', $readme, $m );
		$found['readme upgrade notice'] = $m[1] ?? '';
		preg_match( '/^## \[([^\]]+)\]/m', self::read( 'CHANGELOG.md' ), $m );
		$found['CHANGELOG.md'] = $m[1] ?? '';

		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', $found['header Version'] );
		$this->assertSame( array_fill_keys( array_keys( $found ), $found['header Version'] ), $found );
	}

	public function test_the_zip_keeps_out_features_md_and_the_listing_assets_but_ships_the_guide(): void {
		$ignored = self::export_ignored();
		$this->assertContains( 'FEATURES.md', $ignored, 'Plugin Check warns on unexpected markdown in the plugin root' );
		$this->assertContains( 'wp-org-assets', $ignored, 'banners, icons and screenshots belong in SVN assets/, not the zip' );
		foreach ( $ignored as $path ) {
			$this->assertFalse( str_starts_with( 'docs/ApiGatewayFeaturesGuide.pdf', $path ), "the guide is where FEATURES.md's content reaches users; '$path' would drop it" );
		}
		$this->assertFileExists( BFAG_DIR . 'docs/ApiGatewayFeaturesGuide.pdf' );
	}

	/**
	 * No shipped copy names an export-ignored document (Markdown, text or
	 * PDF) as somewhere to read more.
	 */
	public function test_shipped_copy_names_no_document_the_zip_leaves_out(): void {
		$documents = array_values( array_filter( self::export_ignored(), static fn( string $p ): bool => 1 === preg_match( '/\.(md|txt|pdf)$/i', $p ) ) );
		$this->assertContains( 'FEATURES.md', $documents, 'instrument: the list holds the file this guards' );

		$hits = [];
		foreach ( self::SHIPPED_COPY as $file ) {
			foreach ( preg_split( '/\R/', self::read( $file ) ) ?: [] as $n => $line ) {
				foreach ( $documents as $doc ) {
					if ( str_contains( $line, basename( $doc ) ) ) {
						$hits[] = "$file:" . ( $n + 1 ) . " names $doc";
					}
				}
			}
		}
		$this->assertSame( [], $hits );
	}

	/**
	 * The parts of the WordPress.org readme rules the validator and a
	 * reviewer check by hand.
	 */
	public function test_readme_meets_the_wordpress_org_rules(): void {
		$readme = self::read( 'readme.txt' );
		$main   = self::read( 'bluefairy-api-gateway.php' );

		$this->assertMatchesRegularExpression( '/^Contributors: bluefairydevelopment$/m', $readme );
		$this->assertMatchesRegularExpression( '/^Tested up to: \d+\.\d+$/m', $readme );
		$this->assertMatchesRegularExpression( '/^License: GPLv2 or later$/m', $readme );

		preg_match( '/^Tags:\s*(.+)$/m', $readme, $m );
		$tags = array_filter( array_map( 'trim', explode( ',', $m[1] ?? '' ) ) );
		$this->assertGreaterThan( 0, count( $tags ) );
		$this->assertLessThanOrEqual( 5, count( $tags ), 'WordPress.org shows at most five tags' );

		// The short description is the first non-blank line after the header block.
		preg_match( '/^License URI:.*\R\R(.+)$/m', $readme, $m );
		$short = trim( $m[1] ?? '' );
		$this->assertNotSame( '', $short );
		$this->assertLessThanOrEqual( 150, mb_strlen( $short ), 'WordPress.org truncates a short description over 150 characters' );

		$this->assertMatchesRegularExpression( '/^== External services ==$/m', $readme, 'the outbound webhooks must be disclosed' );
		$this->assertDoesNotMatchRegularExpression( '/^\s*\*\s*Update URI:/mi', $main, 'a WordPress.org plugin is updated by WordPress.org' );

		// One caption per numbered screenshot, numbered from 1 without gaps.
		preg_match( '/^== Screenshots ==\R(.*?)^== /ms', $readme, $m );
		preg_match_all( '/^(\d+)\. \S/m', $m[1] ?? '', $n );
		$this->assertNotSame( [], $n[1] );
		$this->assertSame( range( 1, count( $n[1] ) ), array_map( 'intval', $n[1] ) );
	}
}
