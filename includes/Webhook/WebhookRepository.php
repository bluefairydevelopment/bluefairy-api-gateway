<?php
/**
 * Persistence for webhook subscriptions (`bfag_webhooks`).
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Webhook;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BlueFairy\ApiGateway\Logger;
use BlueFairy\ApiGateway\Support\Limits;

/**
 * CRUD for the `bfag_webhooks` table.
 *
 * The repository stays dumb about events: it stores a sanitised key and the
 * admin page checks it against EventRegistry. It is strict about the URL
 * (https, and `wp_http_validate_url()` so a private address cannot be
 * registered as a target) and about `headers`, which must decode to a flat
 * object of strings — the dispatcher sanitises them again at send time.
 *
 * `all_enabled()` — summaries with no URL, secret or headers (WPMAP-117) — is
 * object-cached under `bfag/webhooks_enabled`; every write
 * clears that key. The list EventBus needs on EVERY request — which events
 * have an enabled webhook — is kept in the autoloaded option
 * `bfag_webhook_events`, rewritten from the table after every write (P7 /
 * WPMAP-106): without a persistent object cache, reading it from the table
 * was a SELECT on every page view.
 */
final class WebhookRepository {

	use \BlueFairy\ApiGateway\Support\WritesOptions;

	public const CACHE_GROUP = 'bfag';
	public const CACHE_KEY   = 'webhooks_enabled';
	public const CACHE_TTL   = 300;

	/**
	 * Autoloaded option holding the distinct event keys of the enabled
	 * webhooks (list<string>).
	 */
	public const EVENTS_OPTION = 'bfag_webhook_events';

	/**
	 * The columns all_enabled() returns and caches: what deciding WHAT to
	 * dispatch needs, and nothing a sender could use (WPMAP-117 / R1-12).
	 * The URL, the secret and the headers — a Pro preset's credentials live
	 * in `headers` — never enter the shared object cache; the dispatcher
	 * reads them with find() for the delivery it sends.
	 *
	 * @var list<string>
	 */
	public const SUMMARY_COLUMNS = [ 'id', 'label', 'event', 'preset', 'enabled', 'max_retries' ];

	/**
	 * Every webhook, newest last.
	 *
	 * @param int $limit Maximum rows, 1..Limits::MAX_ROWS (WPMAP-138).
	 * @return list<array<string, mixed>>
	 */
	public function all( int $limit = Limits::MAX_ROWS ): array {
		global $wpdb;

		$limit = max( 1, min( Limits::MAX_ROWS, $limit ) );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bfag_webhooks ORDER BY id ASC LIMIT %d", $limit ),
			ARRAY_A
		);
		return $this->normalize_rows( $rows );
	}

	/**
	 * Every enabled webhook as a SUMMARY (SUMMARY_COLUMNS only — no URL,
	 * secret or headers), object-cached for five minutes. To send, read the
	 * full row with find() (WebhookDispatcher::dispatch() does).
	 *
	 * A cached value is re-reduced on read, so an entry written by an
	 * earlier version (full rows) never hands out a credential.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function all_enabled(): array {
		global $wpdb;

		$cached = wp_cache_get( self::CACHE_KEY, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $this->summaries( $cached );
		}
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT id, label, event, preset, enabled, max_retries FROM {$wpdb->prefix}bfag_webhooks WHERE enabled = %d ORDER BY id ASC LIMIT %d", 1, Limits::MAX_ROWS ),
			ARRAY_A
		);
		$rows = $this->summaries( is_array( $rows ) ? $rows : [] );
		wp_cache_set( self::CACHE_KEY, $rows, self::CACHE_GROUP, self::CACHE_TTL );
		return $rows;
	}

	/**
	 * Reduce rows to SUMMARY_COLUMNS, cast.
	 *
	 * @param array<mixed> $rows Rows (full or already summaries).
	 * @return list<array<string, mixed>>
	 */
	private function summaries( array $rows ): array {
		$out  = [];
		$keep = array_fill_keys( self::SUMMARY_COLUMNS, true );
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$summary                = array_intersect_key( $row, $keep );
			$summary['id']          = (int) ( $summary['id'] ?? 0 );
			$summary['label']       = (string) ( $summary['label'] ?? '' );
			$summary['event']       = (string) ( $summary['event'] ?? '' );
			$summary['preset']      = (string) ( $summary['preset'] ?? '' );
			$summary['enabled']     = (int) ( $summary['enabled'] ?? 0 );
			$summary['max_retries'] = (int) ( $summary['max_retries'] ?? 3 );
			$out[]                  = $summary;
		}
		return $out;
	}

	/**
	 * One webhook by id.
	 *
	 * @param int $id Row id.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		if ( $id <= 0 ) {
			return null;
		}
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bfag_webhooks WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);
		return is_array( $row ) ? $this->normalize( $row ) : null;
	}

	/**
	 * Enabled webhooks subscribed to $event.
	 *
	 * @param string $event Event key.
	 * @return list<array<string, mixed>>
	 */
	public function for_event( string $event ): array {
		return array_values(
			array_filter(
				$this->all_enabled(),
				static fn( array $row ): bool => $row['event'] === $event
			)
		);
	}

	/**
	 * Number of webhooks, enabled or not.
	 */
	public function count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bfag_webhooks" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Distinct event keys that have at least one enabled webhook, from the
	 * autoloaded option EVENTS_OPTION (no query). When the option does not
	 * exist yet (the first boot of an install, or one upgraded from before
	 * it existed) it is built from `all_enabled()` and added — with
	 * `add_option()`, so a boot never overwrites what a concurrent write
	 * stored.
	 *
	 * @return list<string>
	 */
	public function enabled_events(): array {
		$stored = get_option( self::EVENTS_OPTION, false );
		if ( is_array( $stored ) ) {
			return array_values( array_filter( $stored, 'is_string' ) );
		}
		$events = [];
		foreach ( $this->all_enabled() as $row ) {
			$events[ (string) $row['event'] ] = true;
		}
		$events = array_keys( $events );
		add_option( self::EVENTS_OPTION, $events, '', true );
		return $events;
	}

	/**
	 * Insert a webhook. Returns the new id, or false when the limit is
	 * reached or the data is invalid (the reason is logged).
	 *
	 * @param array<string, mixed> $data label, url, event, secret, headers, preset, enabled, max_retries.
	 */
	public function create( array $data ): int|false {
		global $wpdb;

		$limit = $this->limit();
		if ( $this->count() >= $limit ) {
			Logger::log( 'webhook limit reached', [ 'limit' => $limit ] );
			return false;
		}

		$clean = $this->validate( $data, true );
		if ( null === $clean ) {
			return false;
		}
		$clean['created_at'] = gmdate( 'Y-m-d H:i:s' );

		$result = $wpdb->insert( $wpdb->prefix . 'bfag_webhooks', $clean, $this->formats( $clean ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->invalidate();
		if ( false === $result ) {
			Logger::log( 'webhook insert failed', [ 'error' => $wpdb->last_error ] );
			return false;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update the given columns of a webhook. Only keys present in $data are
	 * touched; an invalid value fails the whole update.
	 *
	 * @param int                  $id   Row id.
	 * @param array<string, mixed> $data Columns to change.
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		if ( $id <= 0 ) {
			return false;
		}
		$clean = $this->validate( $data, false, $id );
		if ( null === $clean || [] === $clean ) {
			return false;
		}
		$result = $wpdb->update( $wpdb->prefix . 'bfag_webhooks', $clean, [ 'id' => $id ], $this->formats( $clean ), [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->invalidate();
		return false !== $result;
	}

	/**
	 * Delete a webhook and its delivery log inside one transaction, so a
	 * failure half-way cannot leave orphaned deliveries (dbDelta cannot
	 * manage a foreign key, so the cascade lives here).
	 *
	 * @param int $id Row id.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		if ( $id <= 0 ) {
			return false;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'START TRANSACTION' );
		$deliveries = $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$wpdb->prefix}bfag_webhook_deliveries WHERE webhook_id = %d", $id )
		);
		$webhook    = false === $deliveries ? false : $wpdb->delete( $wpdb->prefix . 'bfag_webhooks', [ 'id' => $id ], [ '%d' ] );
		if ( false === $webhook ) {
			$wpdb->query( 'ROLLBACK' );
			$this->invalidate();
			Logger::log(
				'webhook delete rolled back',
				[
					'id'    => $id,
					'error' => $wpdb->last_error,
				]
			);
			return false;
		}
		$wpdb->query( 'COMMIT' );
		// phpcs:enable
		$this->invalidate();
		return true;
	}

	/**
	 * Enable or disable a webhook.
	 *
	 * @param int  $id      Row id.
	 * @param bool $enabled New state.
	 */
	public function set_enabled( int $id, bool $enabled ): bool {
		return $this->update( $id, [ 'enabled' => $enabled ] );
	}

	/**
	 * How many webhooks may exist: Limits::DEFAULT_LIMIT unless a filter says
	 * otherwise, with or without the Pro add-on. Never more than
	 * Limits::MAX_ROWS, the most all() and all_enabled() read (WPMAP-138).
	 */
	public function limit(): int {
		/**
		 * Filter the maximum number of webhooks.
		 *
		 * @since 1.0.0
		 * @since 1.0.1 Defaults to Limits::DEFAULT_LIMIT (500); was 3.
		 *
		 * @param int $limit Default Limits::DEFAULT_LIMIT. Clamped to 0..Limits::MAX_ROWS.
		 */
		return Limits::clamp( apply_filters( 'bfag_webhook_limit', Limits::DEFAULT_LIMIT ) );
	}

	/**
	 * The custom headers of a row as name => value. Garbage decodes to [].
	 *
	 * @param array<string, mixed> $row A webhook row.
	 * @return array<string, string>
	 */
	public function headers_of( array $row ): array {
		$raw = $row['headers'] ?? null;
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( $raw as $name => $value ) {
			if ( ! is_string( $name ) || '' === $name || ! is_scalar( $value ) ) {
				continue;
			}
			$out[ $name ] = (string) $value;
		}
		return $out;
	}

	/**
	 * Drop the object-cached enabled list and rewrite EVENTS_OPTION from the
	 * table. Called after every write (also a failed or rolled-back one: the
	 * table is the truth either way). When the option write is refused the
	 * option is deleted instead, so the next enabled_events() rebuilds it.
	 */
	public function invalidate(): void {
		global $wpdb;

		wp_cache_delete( self::CACHE_KEY, self::CACHE_GROUP );
		$events = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the option below IS the cache; this rebuilds it after a write.
			$wpdb->prepare( "SELECT DISTINCT event FROM {$wpdb->prefix}bfag_webhooks WHERE enabled = %d ORDER BY event ASC LIMIT %d", 1, Limits::MAX_ROWS )
		);
		$events = array_values( array_map( 'strval', array_filter( is_array( $events ) ? $events : [], 'is_scalar' ) ) );
		if ( ! $this->write_option( self::EVENTS_OPTION, $events, true ) ) {
			// F21: a stale list would leave an enabled webhook's event unhooked. Drop it so the next boot rebuilds it from the table.
			delete_option( self::EVENTS_OPTION );
			Logger::log( 'webhook event list not stored: the database refused the option write; it is rebuilt on the next request', [ 'option' => self::EVENTS_OPTION ] );
		}
	}

	/**
	 * Validate and coerce input. On create every required column is present
	 * (with defaults); on update only the supplied keys are returned. Null
	 * means invalid, and the reason is logged.
	 *
	 * @param array<string, mixed> $data   Raw input.
	 * @param bool                 $create Whether defaults apply.
	 * @param int                  $id     On update, the row: its stored preset decides whether a blank URL is allowed when none is supplied.
	 * @return array<string, mixed>|null
	 */
	private function validate( array $data, bool $create, int $id = 0 ): ?array {
		$clean = [];

		if ( $create || array_key_exists( 'preset', $data ) ) {
			$clean['preset'] = substr( sanitize_key( $data['preset'] ?? '' ), 0, 50 );
		}

		if ( $create || array_key_exists( 'label', $data ) ) {
			$label = sanitize_text_field( is_scalar( $data['label'] ?? null ) ? (string) $data['label'] : '' );
			if ( '' === $label || mb_strlen( $label ) > 200 ) {
				Logger::log( 'webhook rejected: label must be 1-200 characters' );
				return null;
			}
			$clean['label'] = $label;
		}

		if ( $create || array_key_exists( 'url', $data ) ) {
			$raw_url = is_scalar( $data['url'] ?? null ) ? trim( (string) $data['url'] ) : '';
			// A preset builds the request itself, so a webhook with one may have no URL (U-14). Without one, a
			// blank URL is a row no delivery could ever reach. On update the stored preset counts when none is sent.
			$preset = array_key_exists( 'preset', $clean ) ? (string) $clean['preset'] : (string) ( $this->find( $id )['preset'] ?? '' );
			if ( '' === $raw_url && '' !== $preset ) {
				$clean['url'] = '';
			} else {
				$url = $this->validate_url( $raw_url );
				if ( null === $url ) {
					return null;
				}
				$clean['url'] = $url;
			}
		}

		if ( $create || array_key_exists( 'event', $data ) ) {
			$event = strtolower( is_scalar( $data['event'] ?? null ) ? (string) $data['event'] : '' );
			$event = preg_replace( '/[^a-z0-9_.\-]/', '', $event ) ?? '';
			if ( '' === $event || strlen( $event ) > 100 ) {
				Logger::log( 'webhook rejected: invalid event key' );
				return null;
			}
			$clean['event'] = $event;
		}

		if ( $create || array_key_exists( 'secret', $data ) ) {
			$secret = is_scalar( $data['secret'] ?? null ) ? trim( (string) $data['secret'] ) : '';
			if ( '' === $secret && $create ) {
				$secret = bin2hex( random_bytes( 32 ) );
			}
			if ( '' !== $secret ) {
				if ( strlen( $secret ) > 255 ) {
					Logger::log( 'webhook rejected: secret longer than 255 characters' );
					return null;
				}
				$clean['secret'] = $secret;
			}
		}

		if ( $create || array_key_exists( 'headers', $data ) ) {
			$headers = $this->validate_headers( $data['headers'] ?? null );
			if ( null === $headers ) {
				return null;
			}
			$clean['headers'] = $headers;
		}

		if ( $create || array_key_exists( 'enabled', $data ) ) {
			$clean['enabled'] = array_key_exists( 'enabled', $data ) ? (int) \BlueFairy\ApiGateway\Settings::to_bool( $data['enabled'] ) : 1;
		}

		if ( $create || array_key_exists( 'max_retries', $data ) ) {
			$retries              = is_scalar( $data['max_retries'] ?? null ) ? (int) $data['max_retries'] : 3;
			$clean['max_retries'] = max( 0, min( 10, $retries ) );
		}

		return $clean;
	}

	/**
	 * A target URL must be well-formed, pass WordPress's own safety check
	 * and use https unless `bfag_webhook_allow_http` says otherwise.
	 *
	 * @param string $url Raw URL.
	 */
	private function validate_url( string $url ): ?string {
		if ( '' === $url || strlen( $url ) > 2000 || false === wp_http_validate_url( $url ) ) {
			Logger::log( 'webhook rejected: invalid url', [ 'url' => $url ] );
			return null;
		}
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

		/**
		 * Allow plain-http webhook targets (local development only).
		 *
		 * @since 1.0.0
		 *
		 * @param bool $allow Default false.
		 */
		$allow_http = (bool) apply_filters( 'bfag_webhook_allow_http', false );

		if ( 'https' !== $scheme && ! ( 'http' === $scheme && $allow_http ) ) {
			Logger::log( 'webhook rejected: url must be https', [ 'url' => $url ] );
			return null;
		}
		return $url;
	}

	/**
	 * Headers arrive as an array or a JSON string; both must be a flat object
	 * of scalar values. Stored as a JSON string.
	 *
	 * @param mixed $raw Raw headers.
	 */
	private function validate_headers( mixed $raw ): ?string {
		if ( null === $raw || '' === $raw || [] === $raw ) {
			return '{}';
		}
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) ) {
			Logger::log( 'webhook rejected: headers must be a JSON object' );
			return null;
		}
		$clean = [];
		foreach ( $raw as $name => $value ) {
			if ( ! is_string( $name ) || '' === $name || ! is_scalar( $value ) ) {
				Logger::log( 'webhook rejected: headers must map names to string values' );
				return null;
			}
			$clean[ $name ] = (string) $value;
		}
		$encoded = wp_json_encode( $clean );
		return is_string( $encoded ) ? $encoded : null;
	}

	/**
	 * The wpdb format strings for a column set.
	 *
	 * @param array<string, mixed> $columns Column => value.
	 * @return list<string>
	 */
	private function formats( array $columns ): array {
		$out = [];
		foreach ( array_keys( $columns ) as $column ) {
			$out[] = in_array( $column, [ 'enabled', 'max_retries' ], true ) ? '%d' : '%s';
		}
		return $out;
	}

	/**
	 * Cast a result set.
	 *
	 * @param mixed $rows Whatever get_results returned.
	 * @return list<array<string, mixed>>
	 */
	private function normalize_rows( mixed $rows ): array {
		$out = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			if ( is_array( $row ) ) {
				$out[] = $this->normalize( $row );
			}
		}
		return $out;
	}

	/**
	 * Cast one row's integer columns.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private function normalize( array $row ): array {
		$row['id']          = (int) ( $row['id'] ?? 0 );
		$row['enabled']     = (int) ( $row['enabled'] ?? 0 );
		$row['max_retries'] = (int) ( $row['max_retries'] ?? 3 );
		$row['event']       = (string) ( $row['event'] ?? '' );
		$row['url']         = (string) ( $row['url'] ?? '' );
		$row['secret']      = (string) ( $row['secret'] ?? '' );
		$row['label']       = (string) ( $row['label'] ?? '' );
		return $row;
	}
}
