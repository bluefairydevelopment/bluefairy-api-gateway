<?php
/**
 * composer.json must not carry a "version" field, and
 * composer.lock's content-hash must match composer.json.
 *
 * A "version" field feeds the lock's content-hash, so every version bump left
 * the lock stale. The plugin's version lives in the plugin header and
 * BFAG_VERSION only. The hash is recomputed here the way Composer's
 * Locker::getContentHash() does, so the check needs no composer binary.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ComposerManifestTest extends TestCase {

	/**
	 * Reads and decodes a JSON file at the repository root.
	 *
	 * @return array<string, mixed>
	 */
	private static function read_json( string $file ): array {
		$raw = file_get_contents( dirname( __DIR__, 2 ) . '/' . $file );
		self::assertIsString( $raw, $file . ' is readable' );
		$data = json_decode( $raw, true, 512, JSON_THROW_ON_ERROR );
		self::assertIsArray( $data, $file . ' decodes to an object' );
		return $data;
	}

	/**
	 * Composer's Locker::getContentHash(), for the keys Composer hashes.
	 *
	 * @param array<string, mixed> $manifest Decoded composer.json.
	 */
	private static function content_hash( array $manifest ): string {
		$relevant = array();
		foreach (
			array(
				'name', 'version', 'require', 'require-dev', 'conflict', 'replace', 'provide',
				'minimum-stability', 'prefer-stable', 'repositories', 'extra',
			) as $key
		) {
			if ( array_key_exists( $key, $manifest ) ) {
				$relevant[ $key ] = $manifest[ $key ];
			}
		}
		if ( is_array( $manifest['config'] ?? null ) && isset( $manifest['config']['platform'] ) ) {
			$relevant['config']['platform'] = $manifest['config']['platform'];
		}
		ksort( $relevant );
		return md5( (string) json_encode( $relevant ) );
	}

	public function test_composer_json_has_no_version_field(): void {
		self::assertArrayNotHasKey(
			'version',
			self::read_json( 'composer.json' ),
			'composer.json must not carry "version" (WPMAP-374): it makes composer.lock stale at every bump. '
			. 'The version lives in the plugin header and BFAG_VERSION.'
		);
	}

	public function test_lock_content_hash_matches_composer_json(): void {
		$lock = self::read_json( 'composer.lock' );
		self::assertSame(
			$lock['content-hash'] ?? null,
			self::content_hash( self::read_json( 'composer.json' ) ),
			'composer.lock is stale: run composer update --lock.'
		);
	}
}
