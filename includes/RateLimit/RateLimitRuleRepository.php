<?php
/**
 * CRUD and matching for the rate-limit rules table.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\RateLimit;

use BlueFairy\ApiGateway\Logger;
use BlueFairy\ApiGateway\Support\GlobMatcher;

/**
 * Rows of `bfag_rate_limit_rules` as associative arrays with the table's
 * columns; `id`, `max_requests`, `window_seconds`, `penalty_seconds` and
 * `enabled` are cast to int.
 *
 * The hot-path read, all_enabled(), is object-cached in group `bfag` under
 * `rl_rules_enabled` for 300 seconds and every write deletes that key, so the
 * middleware never pays for a query a rule change did not make necessary.
 *
 * The free plugin allows one rule and one subject type (`ip`); the Pro add-on
 * raises both through `bfag_rate_limit_rule_limit` and
 * `bfag_rate_limit_subject_types`. create() reads those filters at call time,
 * never at construction, so a limit raised on `bfag_loaded` is honoured.
 */
final class RateLimitRuleRepository {

	public const CACHE_GROUP = 'bfag';
	public const CACHE_KEY   = 'rl_rules_enabled';
	public const CACHE_TTL   = 300;

	/**
	 * Bounds for the integer columns, inclusive. Values outside are rejected,
	 * not clamped: a rule that silently became "1 request per day" would be a
	 * worse surprise than a refused save.
	 *
	 * @var array<string, array{int, int}>
	 */
	private const BOUNDS = [
		'max_requests'    => [ 1, 65535 ],
		'window_seconds'  => [ 1, 86400 ],
		'penalty_seconds' => [ 0, 2592000 ],
	];

	/**
	 * Column defaults applied when create() is not given a value.
	 *
	 * @var array<string, int|string>
	 */
	private const CREATE_DEFAULTS = [
		'label'           => '',
		'subject_type'    => 'ip',
		'route_glob'      => '**',
		'max_requests'    => 60,
		'window_seconds'  => 60,
		'penalty_seconds' => 0,
		'enabled'         => 1,
	];

	/**
	 * The `$wpdb` format specifier of each writable column.
	 *
	 * @var array<string, string>
	 */
	private const FORMATS = [
		'label'           => '%s',
		'subject_type'    => '%s',
		'route_glob'      => '%s',
		'max_requests'    => '%d',
		'window_seconds'  => '%d',
		'penalty_seconds' => '%d',
		'enabled'         => '%d',
	];

	/**
	 * Every enabled rule, ordered by id, from the object cache when warm.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function all_enabled(): array {
		$cached = wp_cache_get( self::CACHE_KEY, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return array_values( $cached );
		}
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table, prefix interpolation; cached above.
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bfag_rate_limit_rules WHERE enabled = %d ORDER BY id ASC LIMIT %d", 1, 200 ), ARRAY_A );
		$rules = $this->normalize_all( $rows );
		wp_cache_set( self::CACHE_KEY, $rules, self::CACHE_GROUP, self::CACHE_TTL );
		return $rules;
	}

	/**
	 * Every rule, enabled or not, ordered by id.
	 *
	 * @param int $limit Maximum rows.
	 * @return list<array<string, mixed>>
	 */
	public function all( int $limit = 200 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table, prefix interpolation; admin listing.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bfag_rate_limit_rules ORDER BY id ASC LIMIT %d", max( 1, $limit ) ), ARRAY_A );
		return $this->normalize_all( $rows );
	}

	/**
	 * One rule by id.
	 *
	 * @param int $id Rule id.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table, prefix interpolation; admin edit form.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bfag_rate_limit_rules WHERE id = %d LIMIT 1", $id ), ARRAY_A );
		return is_array( $row ) ? $this->normalize( $row ) : null;
	}

	/**
	 * Number of rules, enabled or not.
	 */
	public function count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table, prefix interpolation, no values to prepare.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bfag_rate_limit_rules" );
	}

	/**
	 * Insert a rule. Returns the new id, or false (and logs why) when the
	 * rule limit is reached or a value is invalid.
	 *
	 * @param array<string, mixed> $data Column => value.
	 * @return int|false
	 */
	public function create( array $data ): int|false {
		if ( $this->count() >= $this->limit() ) {
			Logger::log( 'rate-limit rule refused: limit reached', [ 'limit' => $this->limit() ] );
			return false;
		}
		$clean = $this->validate( array_merge( self::CREATE_DEFAULTS, $data ) );
		if ( null === $clean ) {
			return false;
		}
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table write; cache invalidated below.
		$inserted = $wpdb->insert( $wpdb->prefix . 'bfag_rate_limit_rules', $clean, $this->formats_for( $clean ) );
		$this->invalidate();
		if ( false === $inserted ) {
			Logger::log( 'rate-limit rule insert failed', [ 'error' => $wpdb->last_error ] );
			return false;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update the given columns of a rule. Unknown columns are ignored; an
	 * invalid value refuses the whole update.
	 *
	 * @param int                  $id   Rule id.
	 * @param array<string, mixed> $data Column => value.
	 */
	public function update( int $id, array $data ): bool {
		$clean = $this->validate( array_intersect_key( $data, self::FORMATS ) );
		if ( null === $clean || [] === $clean ) {
			return false;
		}
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table write; cache invalidated below.
		$updated = $wpdb->update( $wpdb->prefix . 'bfag_rate_limit_rules', $clean, [ 'id' => $id ], $this->formats_for( $clean ), [ '%d' ] );
		$this->invalidate();
		return false !== $updated;
	}

	/**
	 * Delete a rule.
	 *
	 * @param int $id Rule id.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table write; cache invalidated below.
		$deleted = $wpdb->delete( $wpdb->prefix . 'bfag_rate_limit_rules', [ 'id' => $id ], [ '%d' ] );
		$this->invalidate();
		return false !== $deleted && $deleted > 0;
	}

	/**
	 * Switch a rule on or off.
	 *
	 * @param int  $id      Rule id.
	 * @param bool $enabled New state.
	 */
	public function set_enabled( int $id, bool $enabled ): bool {
		return $this->update( $id, [ 'enabled' => $enabled ? 1 : 0 ] );
	}

	/**
	 * Maximum number of rules. One in the free plugin.
	 */
	public function limit(): int {
		/**
		 * Filters the maximum number of rate-limit rules.
		 *
		 * @since 1.0.0
		 *
		 * @param int $limit Default 1.
		 */
		return (int) apply_filters( 'bfag_rate_limit_rule_limit', 1 );
	}

	/**
	 * Subject types a rule may use. `ip` only in the free plugin.
	 *
	 * @return list<string>
	 */
	public function subject_types(): array {
		/**
		 * Filters the subject types a rate-limit rule may count by.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $types Default [ 'ip' ].
		 */
		$types = apply_filters( 'bfag_rate_limit_subject_types', [ 'ip' ] );
		if ( ! is_array( $types ) ) {
			return [ 'ip' ];
		}
		$out = [];
		foreach ( $types as $type ) {
			if ( is_string( $type ) && '' !== $type ) {
				$out[] = $type;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Every enabled rule whose glob matches the route, ordered by id.
	 *
	 * @param string $route REST route.
	 * @return list<array<string, mixed>>
	 */
	public function matching( string $route ): array {
		$out = [];
		foreach ( $this->all_enabled() as $rule ) {
			if ( 1 !== (int) ( $rule['enabled'] ?? 0 ) ) {
				continue;
			}
			if ( GlobMatcher::matches( (string) $rule['route_glob'], $route ) ) {
				$out[] = $rule;
			}
		}
		return $out;
	}

	/**
	 * The largest window among enabled rules — how long a hit row must be
	 * kept before the pruner may remove it. 60 when there are no rules.
	 */
	public function max_window(): int {
		$max = 0;
		foreach ( $this->all_enabled() as $rule ) {
			$max = max( $max, (int) $rule['window_seconds'] );
		}
		return $max > 0 ? $max : 60;
	}

	/**
	 * Forget the cached enabled-rule list. Called after every write.
	 */
	private function invalidate(): void {
		wp_cache_delete( self::CACHE_KEY, self::CACHE_GROUP );
	}

	/**
	 * Coerce and bound the writable columns present in $data. Returns null
	 * (after logging) on the first invalid value.
	 *
	 * @param array<string, mixed> $data Column => raw value.
	 * @return array<string, int|string>|null
	 */
	private function validate( array $data ): ?array {
		$clean = [];
		foreach ( $data as $column => $value ) {
			switch ( $column ) {
				case 'label':
					$clean['label'] = substr( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ), 0, 100 );
					break;
				case 'subject_type':
					$type = sanitize_key( is_scalar( $value ) ? (string) $value : '' );
					if ( ! in_array( $type, $this->subject_types(), true ) ) {
						Logger::log( 'rate-limit rule refused: subject type not available', [ 'subject_type' => $type ] );
						return null;
					}
					$clean['subject_type'] = $type;
					break;
				case 'route_glob':
					$glob = trim( is_scalar( $value ) ? (string) $value : '' );
					if ( ! GlobMatcher::is_valid( $glob ) ) {
						Logger::log( 'rate-limit rule refused: invalid route glob', [ 'route_glob' => $glob ] );
						return null;
					}
					$clean['route_glob'] = $glob;
					break;
				case 'max_requests':
				case 'window_seconds':
				case 'penalty_seconds':
					if ( ! is_numeric( $value ) ) {
						Logger::log( 'rate-limit rule refused: not a number', [ $column => $value ] );
						return null;
					}
					$int           = (int) $value;
					[ $min, $max ] = self::BOUNDS[ $column ];
					if ( $int < $min || $int > $max ) {
						Logger::log( 'rate-limit rule refused: out of range', [ $column => $int ] );
						return null;
					}
					$clean[ $column ] = $int;
					break;
				case 'enabled':
					$clean['enabled'] = $this->to_flag( $value );
					break;
				default:
					break;
			}
		}
		return $clean;
	}

	/**
	 * Checkbox-tolerant 0/1 coercion.
	 *
	 * @param mixed $value Raw value.
	 */
	private function to_flag( mixed $value ): int {
		if ( is_bool( $value ) ) {
			return $value ? 1 : 0;
		}
		if ( is_int( $value ) ) {
			return 0 !== $value ? 1 : 0;
		}
		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), [ '1', 'on', 'true', 'yes' ], true ) ? 1 : 0;
		}
		return 0;
	}

	/**
	 * `$wpdb` format specifiers in the order of $clean's keys.
	 *
	 * @param array<string, int|string> $clean Validated columns.
	 * @return list<string>
	 */
	private function formats_for( array $clean ): array {
		$formats = [];
		foreach ( array_keys( $clean ) as $column ) {
			$formats[] = self::FORMATS[ $column ];
		}
		return $formats;
	}

	/**
	 * Normalise whatever get_results() returned into a list of rule arrays.
	 *
	 * @param mixed $rows Raw result.
	 * @return list<array<string, mixed>>
	 */
	private function normalize_all( mixed $rows ): array {
		if ( ! is_array( $rows ) ) {
			return [];
		}
		$out = [];
		foreach ( $rows as $row ) {
			if ( is_object( $row ) ) {
				$row = get_object_vars( $row );
			}
			if ( is_array( $row ) ) {
				$out[] = $this->normalize( $row );
			}
		}
		return $out;
	}

	/**
	 * Cast the integer columns of one row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private function normalize( array $row ): array {
		foreach ( [ 'id', 'max_requests', 'window_seconds', 'penalty_seconds', 'enabled' ] as $column ) {
			$row[ $column ] = (int) ( $row[ $column ] ?? 0 );
		}
		$row['label']        = (string) ( $row['label'] ?? '' );
		$row['subject_type'] = (string) ( $row['subject_type'] ?? 'ip' );
		$row['route_glob']   = (string) ( $row['route_glob'] ?? '**' );
		return $row;
	}
}
