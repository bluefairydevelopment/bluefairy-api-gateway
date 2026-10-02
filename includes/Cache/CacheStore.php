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

use BlueFairy\ApiGateway\Logger;

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
	 * Longest a transient entry lives, whatever the rule's TTL. A purge only
	 * orphans transients — their key is an md5, so the generation they embed
	 * cannot be read back and they cannot be found to delete — and core
	 * sweeps expired transients once a day. Capping the TTL bounds how long
	 * an orphan occupies two rows of the options table.
	 */
	public const MAX_TRANSIENT_TTL = 3600;

	/**
	 * Largest serialised entry the transient backend will store. An
	 * oversized response is served and labelled MISS every time; it is not
	 * worth a MEDIUMTEXT row in the options table.
	 */
	public const MAX_TRANSIENT_BYTES = 65536;

	/**
	 * Expired transient pairs deleted per cleanup slice. 1,000: a slice of
	 * 5,000 names was a 600 KB DELETE statement taking about a second (PN7).
	 */
	public const PRUNE_BATCH = 1000;

	/**
	 * Prefix of every transient this plugin family writes: the `_transient_timeout_`
	 * rows swept by prune_expired() are matched on it, so the limited
	 * markers, the parked forms and the Pro add-on's `bfagp_` transients
	 * are swept with the responses (PN11) instead of waiting for core's
	 * daily sweep.
	 */
	public const PRUNE_PREFIX = 'bfag';

	/**
	 * Most slices one cleanup run issues.
	 */
	public const PRUNE_MAX_ITERATIONS = 20;

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
	 * Whether an oversized entry has been refused and logged this request.
	 *
	 * @var bool
	 */
	private bool $logged_oversize = false;

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
	 * stored_at defaults to now. On the transient backend the TTL is capped
	 * at MAX_TRANSIENT_TTL and an entry over MAX_TRANSIENT_BYTES is not
	 * stored at all (logged once per request); the object cache takes any
	 * size for the full TTL.
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
		$bytes = strlen( serialize( $record ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- sizing what set_transient() would write.
		if ( $bytes > self::MAX_TRANSIENT_BYTES ) {
			if ( ! $this->logged_oversize ) {
				$this->logged_oversize = true;
				Logger::log(
					'response not cached: larger than the transient limit',
					[
						'bytes' => $bytes,
						'max'   => self::MAX_TRANSIENT_BYTES,
					]
				);
			}
			return;
		}
		set_transient( self::TRANSIENT_PREFIX . $key, $record, min( $ttl, self::MAX_TRANSIENT_TTL ) );
	}

	/**
	 * Delete every expired `bfag…` transient from the options table (the
	 * responses, and every other transient of this plugin family — see
	 * PRUNE_PREFIX), both rows of each pair, PRUNE_BATCH pairs a slice until
	 * a slice finds none (or PRUNE_MAX_ITERATIONS slices have run). Nothing
	 * to do on the object cache backend, where entries expire on their own.
	 * Returns pairs deleted.
	 */
	public function prune_expired(): int {
		if ( $this->use_object_cache() ) {
			return 0;
		}
		global $wpdb;

		$deleted = 0;
		for ( $i = 0; $i < self::PRUNE_MAX_ITERATIONS; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- expired transients core will not sweep for up to a day.
			$timeouts = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d LIMIT %d",
					$wpdb->esc_like( '_transient_timeout_' . self::PRUNE_PREFIX ) . '%',
					time(),
					self::PRUNE_BATCH
				)
			);
			$timeouts = is_array( $timeouts ) ? array_values( array_filter( $timeouts, 'is_string' ) ) : [];
			if ( [] === $timeouts ) {
				break;
			}
			$names = [];
			foreach ( $timeouts as $timeout ) {
				$names[] = $timeout;
				$names[] = '_transient_' . substr( $timeout, strlen( '_transient_timeout_' ) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- deleting the pairs found above.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name IN (" . implode( ',', array_fill( 0, count( $names ), '%s' ) ) . ')',
					$names
				)
			);
			$deleted += count( $timeouts );
			if ( count( $timeouts ) < self::PRUNE_BATCH ) {
				break;
			}
		}
		return $deleted;
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
		update_option( self::GENERATION_OPTION, $next, true ); // Autoloaded: every cacheable request keys on it (P7).
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
	 * With `$keep` given (the tags enabled rules carry — CachePurger passes
	 * them), every stored generation not in it is dropped, so the option
	 * holds at most rules × tags entries instead of one per object ever
	 * saved. A dropped tag restarts at 1, so the global generation is
	 * bumped too: a rule that later carries the tag again must never meet
	 * an entry keyed on the old generation 1.
	 *
	 * @param string[]      $tags Tag names.
	 * @param string[]|null $keep Tags to retain a generation for; null keeps everything.
	 */
	public function bump_tags( array $tags, ?array $keep = null ): void {
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
		$dropped = false;
		if ( null !== $keep ) {
			$retain = array_fill_keys( array_map( [ self::class, 'sanitize_tag' ], array_filter( $keep, 'is_string' ) ), true );
			foreach ( array_keys( $generations ) as $tag ) {
				if ( ! isset( $retain[ $tag ] ) && ! isset( $seen[ $tag ] ) ) {
					unset( $generations[ $tag ] );
					$dropped = true;
				}
			}
		}
		if ( ! $changed && ! $dropped ) {
			return;
		}
		update_option( self::TAG_GENERATIONS_OPTION, $generations, false );
		wp_cache_delete( self::TAGS_KEY, self::META_GROUP );
		$this->tag_generations = null;
		if ( $dropped ) {
			$this->flush_all();
		}
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
