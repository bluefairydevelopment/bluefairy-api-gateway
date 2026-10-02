<?php
/**
 * CRUD for the `bfag_cache_rules` table.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BlueFairy\ApiGateway\Logger;
use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Support\GlobMatcher;
use BlueFairy\ApiGateway\Support\Limits;

/**
 * Cache rules: which routes are cached, for how long, under which tags, and
 * with which extra response headers.
 *
 * Rows are associative arrays with the table's columns; `id`, `enabled` and
 * `ttl_seconds` are ints, `tags` is a comma-separated string. The enabled
 * set is read on every REST request, so it is object-cached under
 * `cache_rules_enabled` in group `bfag` and every write invalidates it.
 *
 * A site may keep Limits::DEFAULT_LIMIT rules (500), with or without the
 * Pro add-on; the `bfag_cache_rule_limit` filter lowers or raises that.
 */
final class CacheRuleRepository {

	/**
	 * Object-cache key of the enabled-rules list.
	 */
	public const CACHE_KEY = 'cache_rules_enabled';

	/**
	 * Object-cache group.
	 */
	private const CACHE_GROUP = 'bfag';

	/**
	 * Object-cache TTL in seconds.
	 */
	private const CACHE_TTL = 300;

	/**
	 * Bounds.
	 */
	private const MAX_TAGS         = 20;
	private const MAX_LABEL_LENGTH = 100;
	private const MIN_TTL          = 1;
	private const MAX_TTL          = 86400;

	/**
	 * Formats, per column, for wpdb.
	 *
	 * @var array<string, string>
	 */
	private const FORMATS = [
		'label'       => '%s',
		'route_glob'  => '%s',
		'ttl_seconds' => '%d',
		'tags'        => '%s',
		'headers'     => '%s',
		'enabled'     => '%d',
	];

	/**
	 * Every enabled rule, ordered by id. Object-cached for five minutes.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function all_enabled(): array {
		$found  = false;
		$cached = wp_cache_get( self::CACHE_KEY, self::CACHE_GROUP, false, $found );
		if ( $found && is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table; the result is object-cached below.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bfag_cache_rules WHERE enabled = %d ORDER BY id ASC LIMIT %d", 1, Limits::MAX_ROWS ), ARRAY_A );

		$rules = $this->normalise_rows( $rows );
		wp_cache_set( self::CACHE_KEY, $rules, self::CACHE_GROUP, self::CACHE_TTL );
		return $rules;
	}

	/**
	 * Every rule, enabled or not, ordered by id.
	 *
	 * @param int $limit Maximum rows, 1..Limits::MAX_ROWS (WPMAP-138).
	 * @return list<array<string, mixed>>
	 */
	public function all( int $limit = Limits::MAX_ROWS ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin listing of a plugin table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bfag_cache_rules ORDER BY id ASC LIMIT %d", max( 1, min( Limits::MAX_ROWS, $limit ) ) ), ARRAY_A );
		return $this->normalise_rows( $rows );
	}

	/**
	 * One rule by id.
	 *
	 * @param int $id Rule id.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- single-row admin read of a plugin table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bfag_cache_rules WHERE id = %d LIMIT %d", $id, 1 ), ARRAY_A );
		return is_array( $row ) ? $this->normalise_row( $row ) : null;
	}

	/**
	 * Number of rules, enabled or not.
	 */
	public function count(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the limit guard must see the live count.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bfag_cache_rules WHERE id > %d", 0 ) );
	}

	/**
	 * Insert a rule. Returns the new id, or false when the limit is reached
	 * or the data is invalid (both logged).
	 *
	 * @param array<string, mixed> $data label, route_glob, ttl_seconds, tags (array or CSV), headers (JSON object or array), enabled.
	 */
	public function create( array $data ): int|false {
		$limit = $this->limit();
		$count = $this->count();
		if ( $count >= $limit ) {
			Logger::log(
				'cache rule not created: limit reached',
				[
					'count' => $count,
					'limit' => $limit,
				]
			);
			return false;
		}

		$clean = $this->sanitize( $data, true );
		if ( null === $clean ) {
			return false;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- write to a plugin table; cache invalidated below.
		$result = $wpdb->insert( $wpdb->prefix . 'bfag_cache_rules', $clean, $this->formats( $clean ) );
		$this->invalidate();

		if ( false === $result ) {
			Logger::log( 'cache rule insert failed', [ 'error' => $wpdb->last_error ] );
			return false;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update the given columns of a rule.
	 *
	 * @param int                  $id   Rule id.
	 * @param array<string, mixed> $data Columns to change (same keys as create()).
	 */
	public function update( int $id, array $data ): bool {
		$clean = $this->sanitize( $data, false );
		if ( null === $clean || [] === $clean ) {
			return false;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- write to a plugin table; cache invalidated below.
		$result = $wpdb->update( $wpdb->prefix . 'bfag_cache_rules', $clean, [ 'id' => $id ], $this->formats( $clean ), [ '%d' ] );
		$this->invalidate();
		return false !== $result;
	}

	/**
	 * Delete a rule.
	 *
	 * @param int $id Rule id.
	 */
	public function delete( int $id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- write to a plugin table; cache invalidated below.
		$result = $wpdb->delete( $wpdb->prefix . 'bfag_cache_rules', [ 'id' => $id ], [ '%d' ] );
		$this->invalidate();
		return false !== $result && $result > 0;
	}

	/**
	 * Enable or disable a rule.
	 *
	 * @param int  $id      Rule id.
	 * @param bool $enabled New state.
	 */
	public function set_enabled( int $id, bool $enabled ): bool {
		return $this->update( $id, [ 'enabled' => $enabled ] );
	}

	/**
	 * Maximum number of rules: Limits::DEFAULT_LIMIT unless a filter says
	 * otherwise, and never more than Limits::MAX_ROWS — the most all() and
	 * all_enabled() read (WPMAP-138).
	 */
	public function limit(): int {
		/**
		 * Filters the maximum number of cache rules. Clamped to
		 * 0..Limits::MAX_ROWS.
		 *
		 * @since 1.0.0
		 * @since 1.0.1 Defaults to Limits::DEFAULT_LIMIT (500); was 1.
		 *
		 * @param int $limit Default Limits::DEFAULT_LIMIT.
		 */
		return Limits::clamp( apply_filters( 'bfag_cache_rule_limit', Limits::DEFAULT_LIMIT ) );
	}

	/**
	 * The most specific enabled rule whose glob matches the route, or null.
	 *
	 * Specificity is GlobMatcher::specificity() — the count of literal
	 * characters in the glob — so a `wp/v2/posts/**` rule beats the seeded
	 * `**` whatever order they were created in. A tie goes to the lowest id.
	 *
	 * @param string $route REST route, with or without the leading slash.
	 * @return array<string, mixed>|null
	 */
	public function match( string $route ): ?array {
		$best       = null;
		$best_score = -1;
		foreach ( $this->all_enabled() as $rule ) {
			$glob = (string) ( $rule['route_glob'] ?? '' );
			if ( '' === $glob || 1 !== (int) ( $rule['enabled'] ?? 1 ) || ! GlobMatcher::matches( $glob, $route ) ) {
				continue;
			}
			$score = GlobMatcher::specificity( $glob );
			if ( $score > $best_score || ( $score === $best_score && (int) ( $rule['id'] ?? 0 ) < (int) ( $best['id'] ?? 0 ) ) ) {
				$best       = $rule;
				$best_score = $score;
			}
		}
		return $best;
	}

	/**
	 * Rules in match order — most specific first, ties by lowest id — for
	 * the admin table, so the order shown is the order match() applies.
	 *
	 * @param list<array<string, mixed>> $rules Rule rows.
	 * @return list<array<string, mixed>>
	 */
	public function sort_by_specificity( array $rules ): array {
		usort(
			$rules,
			static function ( array $a, array $b ): int {
				$score = GlobMatcher::specificity( (string) ( $b['route_glob'] ?? '' ) ) <=> GlobMatcher::specificity( (string) ( $a['route_glob'] ?? '' ) );
				return 0 !== $score ? $score : ( (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 ) );
			}
		);
		return $rules;
	}

	/**
	 * A rule's tags as a list.
	 *
	 * @param array<string, mixed> $rule A rule row.
	 * @return string[]
	 */
	public function tags_of( array $rule ): array {
		return $this->sanitize_tags( $rule['tags'] ?? '' );
	}

	/**
	 * Forget the cached enabled-rules list.
	 */
	private function invalidate(): void {
		wp_cache_delete( self::CACHE_KEY, self::CACHE_GROUP );
	}

	/**
	 * Validate and coerce input. For a create every column is filled from
	 * defaults; for an update only the keys present are returned. Null means
	 * the input was rejected (logged).
	 *
	 * @param array<string, mixed> $data   Raw input.
	 * @param bool                 $create Fill defaults for missing keys.
	 * @return array<string, mixed>|null
	 */
	private function sanitize( array $data, bool $create ): ?array {
		$clean = [];

		if ( $create || array_key_exists( 'label', $data ) ) {
			$clean['label'] = substr( sanitize_text_field( $data['label'] ?? '' ), 0, self::MAX_LABEL_LENGTH );
		}

		if ( $create || array_key_exists( 'route_glob', $data ) ) {
			$glob = ltrim( trim( is_string( $data['route_glob'] ?? null ) ? $data['route_glob'] : ( $create ? '**' : '' ) ), '/' );
			if ( ! GlobMatcher::is_valid( $glob ) ) {
				Logger::log( 'cache rule rejected: invalid route glob', [ 'route_glob' => $glob ] );
				return null;
			}
			$clean['route_glob'] = $glob;
		}

		if ( $create || array_key_exists( 'ttl_seconds', $data ) ) {
			$ttl                  = (int) ( $data['ttl_seconds'] ?? Settings::DEFAULTS['default_cache_ttl'] );
			$clean['ttl_seconds'] = max( self::MIN_TTL, min( self::MAX_TTL, $ttl ) );
		}

		if ( $create || array_key_exists( 'tags', $data ) ) {
			$clean['tags'] = implode( ',', $this->sanitize_tags( $data['tags'] ?? '' ) );
		}

		if ( $create || array_key_exists( 'headers', $data ) ) {
			$clean['headers'] = $this->sanitize_headers( $data['headers'] ?? null );
		}

		if ( $create || array_key_exists( 'enabled', $data ) ) {
			$clean['enabled'] = Settings::to_bool( $data['enabled'] ?? true ) ? 1 : 0;
		}

		return $clean;
	}

	/**
	 * Accept an array or a comma-separated string; keep `[a-z0-9_:-]`
	 * (CacheStore::sanitize_tag), lower-cased, unique, at most 20.
	 *
	 * @param mixed $raw Raw tags.
	 * @return string[]
	 */
	private function sanitize_tags( mixed $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( $raw as $tag ) {
			if ( ! is_string( $tag ) ) {
				continue;
			}
			$tag = CacheStore::sanitize_tag( $tag );
			if ( '' === $tag || in_array( $tag, $out, true ) ) {
				continue;
			}
			$out[] = $tag;
			if ( count( $out ) >= self::MAX_TAGS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * A JSON object of header name => string value, re-encoded; null when
	 * empty or unusable. Names must be token characters; values must not
	 * contain CR/LF; Set-Cookie is dropped because a cached response must
	 * never carry one.
	 *
	 * @param mixed $raw JSON string or array.
	 */
	private function sanitize_headers( mixed $raw ): ?string {
		if ( is_string( $raw ) ) {
			$raw = '' === trim( $raw ) ? [] : json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$out = [];
		foreach ( $raw as $name => $value ) {
			if ( ! is_string( $name ) || ! is_scalar( $value ) || 1 !== preg_match( '/^[A-Za-z0-9-]+$/', $name ) ) {
				continue;
			}
			if ( 'set-cookie' === strtolower( $name ) ) {
				continue;
			}
			$value = (string) $value;
			if ( str_contains( $value, "\r" ) || str_contains( $value, "\n" ) ) {
				continue;
			}
			$out[ $name ] = $value;
		}
		if ( [] === $out ) {
			return null;
		}
		$encoded = wp_json_encode( $out );
		return is_string( $encoded ) ? $encoded : null;
	}

	/**
	 * The wpdb formats matching the columns present, in order.
	 *
	 * @param array<string, mixed> $columns Sanitised columns.
	 * @return list<string>
	 */
	private function formats( array $columns ): array {
		$formats = [];
		foreach ( array_keys( $columns ) as $column ) {
			$formats[] = self::FORMATS[ $column ] ?? '%s';
		}
		return $formats;
	}

	/**
	 * Normalise a result set.
	 *
	 * @param mixed $rows wpdb result.
	 * @return list<array<string, mixed>>
	 */
	private function normalise_rows( mixed $rows ): array {
		$out = [];
		if ( ! is_array( $rows ) ) {
			return $out;
		}
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = $this->normalise_row( $row );
			}
		}
		return $out;
	}

	/**
	 * Cast one row to the documented shape.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private function normalise_row( array $row ): array {
		return [
			'id'          => (int) ( $row['id'] ?? 0 ),
			'label'       => (string) ( $row['label'] ?? '' ),
			'route_glob'  => (string) ( $row['route_glob'] ?? '' ),
			'ttl_seconds' => (int) ( $row['ttl_seconds'] ?? 0 ),
			'tags'        => (string) ( $row['tags'] ?? '' ),
			'headers'     => is_string( $row['headers'] ?? null ) ? $row['headers'] : null,
			'enabled'     => (int) ( $row['enabled'] ?? 0 ),
			'created_at'  => (string) ( $row['created_at'] ?? '' ),
		];
	}
}
