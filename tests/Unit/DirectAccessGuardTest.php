<?php
/**
 * Plugin Check: every PHP file under admin/ and includes/ must refuse direct
 * access with a `defined( 'ABSPATH' )` guard near the top.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit;

use BlueFairy\ApiGateway\Tests\TestCase;

/**
 * A scan test. The scanner is first shown a planted specimen without a guard
 * (and one with the guard too late) so a green sweep is a green from an
 * instrument seen to go red.
 */
final class DirectAccessGuardTest extends TestCase {

	private const MAX_LINE = 40;

	/**
	 * Every PHP file under admin/ and includes/, recursively.
	 *
	 * @return list<string>
	 */
	private static function files(): array {
		$files = [];
		foreach ( [ 'admin', 'includes' ] as $dir ) {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( BFAG_DIR . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
					$files[] = $file->getPathname();
				}
			}
		}
		sort( $files );
		return $files;
	}

	/**
	 * The 1-based line of the first `defined( 'ABSPATH' )` within the first
	 * MAX_LINE lines of $source, or null.
	 */
	private static function guard_line( string $source ): ?int {
		$lines = array_slice( explode( "\n", $source ), 0, self::MAX_LINE );
		foreach ( $lines as $i => $line ) {
			if ( str_contains( $line, "defined( 'ABSPATH' )" ) ) {
				return $i + 1;
			}
		}
		return null;
	}

	public function test_the_scanner_flags_a_file_without_a_guard_and_one_whose_guard_is_too_late(): void {
		$this->assertNull( self::guard_line( "<?php\ndeclare(strict_types=1);\nnamespace X;\nfinal class Y {}\n" ), 'no guard at all' );
		$this->assertNull( self::guard_line( "<?php\n" . str_repeat( "// filler\n", self::MAX_LINE ) . "if ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\n" ), 'a guard after line 40 does not count' );
		$this->assertNull( self::guard_line( "<?php\nif ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {\n\texit;\n}\n" ), 'another constant is not this guard' );
		$this->assertSame( 4, self::guard_line( "<?php\ndeclare(strict_types=1);\nnamespace X;\nif ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\n" ) );
		$this->assertSame( 3, self::guard_line( "<?php\n\ndefined( 'ABSPATH' ) || exit;\n" ), 'the short form counts too' );
	}

	public function test_every_php_file_under_admin_and_includes_guards_against_direct_access(): void {
		$files = self::files();
		$this->assertGreaterThanOrEqual( 47, count( $files ), 'the sweep must see the whole tree (11 views + 36 classes at the time of writing)' );

		$missing = [];
		foreach ( $files as $file ) {
			if ( null === self::guard_line( (string) file_get_contents( $file ) ) ) {
				$missing[] = substr( $file, strlen( BFAG_DIR ) );
			}
		}
		$this->assertSame( [], $missing, sprintf( 'files without a defined( \'ABSPATH\' ) guard in their first %d lines', self::MAX_LINE ) );
	}
}
