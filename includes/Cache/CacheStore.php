<?php
/**
 * Storage for serialised REST responses.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores serialised REST responses in the WP object cache (group
 * `bfag_responses`) when a persistent object cache is present, otherwise in
 * transients named `bfag_resp_<key>`.
 *
 * Purging is O(1) on both backends through generations: a key embeds the
 * global generation and the generation of every tag the matching rule
 * carries (see CacheKeyGenerator), so bumping a generation orphans the old
 * entries. They are never enumerated or deleted one by one — they simply
 * expire by TTL.
 *
 * The global generation is option `bfag_cache_generation`; every tag
 * generation lives in ONE option, `bfag_tag_generations` (tag => int), so
 * uninstall can delete them by name. Transients were tried first and
 * rejected: in an external object cache they live in the `transient` group,
 * which uninstall.php cannot enumerate, so they outlived the plugin.
 */
final class CacheStore {

	/**
	 * Object-cache group for stored responses.
	 */
	public const GROUP = 'bfag_responses';

	/**
	 * Transient name prefix used when there is no persistent object cache.
	 */
	public const TRANSIENT_PREFIX = 'bfag_resp_';

	/**
	 * Option holding the global generation.
	 */
	public const GENERATION_OPTION = 'bfag_cache_generation';

	/**
	 * Option holding every tag generation, as tag => int.
	 */
	public const TAG_GENERATIONS_OPTION = 'bfag_tag_generations';

	/**
	 * Object-cache group and keys for the memoised generations.
	 */
	private const META_GROUP = 'bfag';
	private const META_KEY   = 'generation';
	private const TAGS_KEY   = 'tag_generations';
	private const META_TTL   = 300;

	/**
	 * Per-request memo of the tag generations (one store per request), so a
	 * key with twenty tags costs one option read, not twenty.
	 *
	 * @var array<string, int>|null
	 */
	private ?array $tag_generations = null;

	/**
	 * Longest tag name stored in a generation key.
	 */
	private const MAX_TAG_LENGTH = 50;

	/**
	 * A stored entry, or null when absent, expired or malformed.
	 *
	 * @param string $key Cache key from CacheKeyGenerator.
	 * @return array{status:int, headers:array<string, string>, data:mixed, stored_at:int}|null
	 */
	public function get( string $key ): ?array {
		$raw = $this->use_object_cache()
			? wp_cache_get( $key, self::GROUP )
			: get_transient( self::TRANSIENT_PREFIX . $key );

		if ( ! is_array( $raw ) || ! array_key_exists( 'data', $raw ) || ! isset( $raw['status'], $raw['headers'], $raw['stored_at'] ) || ! is_array( $raw['headers'] ) ) {
			return null;
		}

		return [
			'status'    => (int) $raw['status'],
			'headers'   => $raw['headers'],
			'data'      => $raw['data'],
			'stored_at' => (int) $raw['stored_at'],
		];
	}

	/**
	 * Store an entry. Only status, headers, data and stored_at are kept;
	 * stored_at defaults to now.
	 *
	 * @param string               $key   Cache key.
	 * @param array<string, mixed> $entry Entry with status, headers, data and optionally stored_at.
	 * @param int                  $ttl   Seconds to live (minimum 1).
	 */
	public function set( string $key, array $entry, int $ttl ): void {
		$record = [
			'status'    => (int) ( $entry['status'] ?? 200 ),
			'headers'   => is_array( $entry['headers'] ?? null ) ? $entry['headers'] : [],
			'data'      => $entry['data'] ?? null,
			'stored_at' => (int) ( $entry['stored_at'] ?? time() ),
		];
		$ttl    = max( 1, $ttl );

		if ( $this->use_object_cache() ) {
			wp_cache_set( $key, $record, self::GROUP, $ttl );
			return;
		}
		set_transient( self::TRANSIENT_PREFIX . $key, $record, $ttl );
	}

	/**
	 * Remove one entry from the active backend.
	 *
	 * @param string $key Cache key.
	 */
	public function delete( string $key ): void {
		if ( $this->use_object_cache() ) {
			wp_cache_delete( $key, self::GROUP );
			return;
		}
		delete_transient( self::TRANSIENT_PREFIX . $key );
	}

	/**
	 * The global generation (option `bfag_cache_generation`, default 1),
	 * memoised in object-cache group `bfag` under key `generation`.
	 */
	public function generation(): int {
		$found  = false;
		$cached = wp_cache_get( self::META_KEY, self::META_GROUP, false, $found );
		if ( $found && is_numeric( $cached ) ) {
			return max( 1, (int) $cached );
		}
		$generation = max( 1, (int) get_option( self::GENERATION_OPTION, 1 ) );
		wp_cache_set( self::META_KEY, $generation, self::META_GROUP, self::META_TTL );
		return $generation;
	}

	/**
	 * Orphan every stored response by bumping the global generation, and
	 * clear the response group outright where the object cache supports it.
	 */
	public function flush_all(): void {
		$next = $this->generation() + 1;
		update_option( self::GENERATION_OPTION, $next, false );
		wp_cache_delete( self::META_KEY, self::META_GROUP );

		if ( wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( self::GROUP );
		}
	}

	/**
	 * The generation of one tag (default 1).
	 *
	 * @param string $tag Tag name; sanitised to `[a-z0-9_:-]`.
	 */
	public function tag_generation( string $tag ): int {
		$tag = self::sanitize_tag( $tag );
		if ( '' === $tag ) {
			return 1;
		}
		return $this->tag_generations()[ $tag ] ?? 1;
	}

	/**
	 * Bump the generation of each tag, orphaning every entry keyed on it.
	 * One option write however many tags; the memo and the object-cache
	 * copy are dropped so the next read sees the new generations.
	 *
	 * @param string[] $tags Tag names.
	 */
	public function bump_tags( array $tags ): void {
		$generations = $this->tag_generations();
		$changed     = false;
		$seen        = [];
		foreach ( $tags as $tag ) {
			$tag = self::sanitize_tag( is_string( $tag ) ? $tag : '' );
			if ( '' === $tag || isset( $seen[ $tag ] ) ) {
				continue;
			}
			$seen[ $tag ]        = true;
			$generations[ $tag ] = ( $generations[ $tag ] ?? 1 ) + 1;
			$changed             = true;
		}
		if ( ! $changed ) {
			return;
		}
		update_option( self::TAG_GENERATIONS_OPTION, $generations, false );
		wp_cache_delete( self::TAGS_KEY, self::META_GROUP );
		$this->tag_generations = null;
	}

	/**
	 * Every tag generation: the per-request memo, else object-cache group
	 * `bfag` key `tag_generations`, else option `bfag_tag_generations`.
	 *
	 * @return array<string, int>
	 */
	private function tag_generations(): array {
		if ( null !== $this->tag_generations ) {
			return $this->tag_generations;
		}
		$found  = false;
		$cached = wp_cache_get( self::TAGS_KEY, self::META_GROUP, false, $found );
		if ( ! $found || ! is_array( $cached ) ) {
			$cached = get_option( self::TAG_GENERATIONS_OPTION, [] );
			$cached = is_array( $cached ) ? $cached : [];
			wp_cache_set( self::TAGS_KEY, $cached, self::META_GROUP, self::META_TTL );
		}
		$clean = [];
		foreach ( $cached as $tag => $generation ) {
			if ( is_string( $tag ) && is_numeric( $generation ) ) {
				$clean[ $tag ] = max( 1, (int) $generation );
			}
		}
		$this->tag_generations = $clean;
		return $clean;
	}

	/**
	 * Is a persistent object cache the active backend?
	 *
	 * Core's wp_using_ext_object_cache() returns null, not false, on an
	 * install without an object-cache.php drop-in; only a strict true means
	 * the object cache is persistent.
	 */
	private function use_object_cache(): bool {
		return true === wp_using_ext_object_cache();
	}

	/**
	 * Lower-case, `[a-z0-9_:-]` only, at most 50 characters. The one tag
	 * sanitiser: every purge path (REST, AJAX, rules) must keep the `:` of
	 * `post:42`, which `sanitize_key()` strips.
	 *
	 * @param string $tag Raw tag.
	 */
	public static function sanitize_tag( string $tag ): string {
		$tag = preg_replace( '/[^a-z0-9_:-]/', '', strtolower( trim( $tag ) ) ) ?? '';
		return substr( $tag, 0, self::MAX_TAG_LENGTH );
	}
}
