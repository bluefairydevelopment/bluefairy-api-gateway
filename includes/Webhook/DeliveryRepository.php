<?php
/**
 * Persistence for the webhook delivery log (`bfag_webhook_deliveries`).
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Webhook;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BlueFairy\ApiGateway\Settings;

/**
 * One row per delivery attempt, outgoing (`out`) or incoming (`in`, the Pro
 * add-on's inbound endpoint).
 *
 * The payload column is bounded twice (review finding DB5): `create()` cuts
 * it at `max_payload_bytes` and flags the row `truncated`, and `prune()`
 * removes finished rows older than `retention_days` in 5,000-row slices so a
 * daily cron on a busy store never holds a lock for long.
 */
final class DeliveryRepository {

	public const STATUSES = [ 'pending', 'success', 'failed', 'retrying' ];

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Plugin settings (max_payload_bytes).
	 */
	public function __construct( private readonly Settings $settings ) {}

	/**
	 * Insert a delivery row. Returns the id or false.
	 *
	 * @param array<string, mixed> $data webhook_id, event, payload (JSON string), direction, status, attempt, http_status, response_body.
	 */
	public function create( array $data ): int|false {
		global $wpdb;

		$payload   = is_scalar( $data['payload'] ?? null ) ? (string) $data['payload'] : '';
		$max       = max( 1, (int) $this->settings->get( 'max_payload_bytes' ) );
		$truncated = 0;
		if ( strlen( $payload ) > $max ) {
			$payload   = substr( $payload, 0, $max );
			$truncated = 1;
		}
		$status    = is_string( $data['status'] ?? null ) && in_array( $data['status'], self::STATUSES, true ) ? $data['status'] : 'pending';
		$direction = 'in' === ( $data['direction'] ?? 'out' ) ? 'in' : 'out';

		$row    = [
			'webhook_id'    => (int) ( $data['webhook_id'] ?? 0 ),
			'direction'     => $direction,
			'event'         => substr( is_scalar( $data['event'] ?? null ) ? (string) $data['event'] : '', 0, 100 ),
			'payload'       => $payload,
			'truncated'     => $truncated,
			'status'        => $status,
			'http_status'   => isset( $data['http_status'] ) && is_numeric( $data['http_status'] ) ? (int) $data['http_status'] : null,
			'response_body' => isset( $data['response_body'] ) && is_scalar( $data['response_body'] ) ? substr( (string) $data['response_body'], 0, 2000 ) : null,
			'attempt'       => max( 1, (int) ( $data['attempt'] ?? 1 ) ),
			'next_retry_at' => isset( $data['next_retry_at'] ) && is_string( $data['next_retry_at'] ) ? $data['next_retry_at'] : null,
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
		];
		$result = $wpdb->insert( $wpdb->prefix . 'bfag_webhook_deliveries', $row, $this->formats( $row ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( false === $result ) {
			return false;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update columns of a delivery row.
	 *
	 * @param int                  $id   Row id.
	 * @param array<string, mixed> $data Columns to change.
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		if ( $id <= 0 ) {
			return false;
		}
		$allowed = [ 'status', 'http_status', 'response_body', 'attempt', 'next_retry_at', 'delivered_at', 'payload', 'truncated' ];
		$clean   = [];
		foreach ( $allowed as $column ) {
			if ( ! array_key_exists( $column, $data ) ) {
				continue;
			}
			$value = $data[ $column ];
			if ( 'status' === $column ) {
				if ( ! is_string( $value ) || ! in_array( $value, self::STATUSES, true ) ) {
					return false;
				}
			} elseif ( 'response_body' === $column ) {
				$value = null === $value ? null : substr( is_scalar( $value ) ? (string) $value : '', 0, 2000 );
			} elseif ( in_array( $column, [ 'http_status', 'attempt', 'truncated' ], true ) ) {
				$value = null === $value ? null : (int) $value;
			} elseif ( ! is_scalar( $value ) && null !== $value ) {
				return false;
			}
			$clean[ $column ] = $value;
		}
		if ( [] === $clean ) {
			return false;
		}
		$result = $wpdb->update( $wpdb->prefix . 'bfag_webhook_deliveries', $clean, [ 'id' => $id ], $this->formats( $clean ), [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $result;
	}

	/**
	 * One delivery by id.
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
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bfag_webhook_deliveries WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);
		return is_array( $row ) ? $this->normalize( $row ) : null;
	}

	/**
	 * Most recent deliveries, newest first.
	 *
	 * One literal statement whatever the filters: each optional filter is a
	 * `( %d = 0 OR column = %d )` pair that MySQL folds away when the value
	 * is the neutral one, so nothing is ever interpolated into the SQL.
	 *
	 * @param int                  $limit   Maximum rows.
	 * @param array<string, mixed> $filters webhook_id, status, direction.
	 * @return list<array<string, mixed>>
	 */
	public function recent( int $limit = 50, array $filters = [] ): array {
		global $wpdb;

		$webhook_id = isset( $filters['webhook_id'] ) && (int) $filters['webhook_id'] > 0 ? (int) $filters['webhook_id'] : 0;
		$status     = isset( $filters['status'] ) && is_string( $filters['status'] ) && in_array( $filters['status'], self::STATUSES, true ) ? $filters['status'] : '';
		$direction  = isset( $filters['direction'] ) && in_array( $filters['direction'], [ 'in', 'out' ], true ) ? $filters['direction'] : '';

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bfag_webhook_deliveries WHERE ( %d = 0 OR webhook_id = %d ) AND ( %s = '' OR status = %s ) AND ( %s = '' OR direction = %s ) ORDER BY created_at DESC, id DESC LIMIT %d",
				$webhook_id,
				$webhook_id,
				$status,
				$status,
				$direction,
				$direction,
				max( 1, min( 500, $limit ) )
			),
			ARRAY_A
		);
		$out  = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			if ( is_array( $row ) ) {
				$out[] = $this->normalize( $row );
			}
		}
		return $out;
	}

	/**
	 * Status of the newest outgoing delivery of each webhook, in one query:
	 * `webhook_id => status` for every id that has one.
	 *
	 * @param int[] $webhook_ids Webhook ids.
	 * @return array<int, string>
	 */
	public function last_statuses( array $webhook_ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $webhook_ids ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( [] === $ids ) {
			return [];
		}
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT webhook_id, status FROM {$wpdb->prefix}bfag_webhook_deliveries WHERE id IN ( SELECT MAX(id) FROM {$wpdb->prefix}bfag_webhook_deliveries WHERE webhook_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') AND direction = %s GROUP BY webhook_id )',
				array_merge( $ids, [ 'out' ] )
			),
			ARRAY_A
		);
		$out  = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			if ( is_array( $row ) && isset( $row['webhook_id'], $row['status'] ) && is_string( $row['status'] ) && '' !== $row['status'] ) {
				$out[ (int) $row['webhook_id'] ] = $row['status'];
			}
		}
		return $out;
	}

	/**
	 * Outgoing deliveries queued for the pending-flush cron: `pending` with
	 * a due `next_retry_at` (an in-flight delivery is `pending` too, but
	 * with no `next_retry_at`). Oldest first.
	 *
	 * @param int $limit Maximum rows.
	 * @return list<array<string, mixed>>
	 */
	public function pending( int $limit ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bfag_webhook_deliveries WHERE status = %s AND direction = %s AND next_retry_at IS NOT NULL AND next_retry_at <= %s ORDER BY id ASC LIMIT %d",
				'pending',
				'out',
				gmdate( 'Y-m-d H:i:s' ),
				max( 1, min( 500, $limit ) )
			),
			ARRAY_A
		);
		$out  = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			if ( is_array( $row ) ) {
				$out[] = $this->normalize( $row );
			}
		}
		return $out;
	}

	/**
	 * Delete finished deliveries older than $retention_days, and `pending`
	 * or `retrying` rows older than a day (a row the cron never picked up,
	 * or a send the process died in the middle of, must not live for ever),
	 * 5,000 at a time. Returns the number of rows removed.
	 *
	 * @param int $retention_days Days to keep finished deliveries.
	 */
	public function prune( int $retention_days ): int {
		global $wpdb;

		$retention_days = max( 1, $retention_days );
		$cutoff         = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );
		$stale          = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$total          = 0;
		for ( $i = 0; $i < 200; $i++ ) {
			$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( "DELETE FROM {$wpdb->prefix}bfag_webhook_deliveries WHERE status IN ('success','failed') AND created_at < %s LIMIT %d", $cutoff, 5000 )
			);
			if ( ! is_int( $deleted ) || $deleted <= 0 ) {
				break;
			}
			$total += $deleted;
		}
		for ( $i = 0; $i < 200; $i++ ) {
			$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( "DELETE FROM {$wpdb->prefix}bfag_webhook_deliveries WHERE status IN ('pending','retrying') AND created_at < %s LIMIT %d", $stale, 5000 )
			);
			if ( ! is_int( $deleted ) || $deleted <= 0 ) {
				break;
			}
			$total += $deleted;
		}
		return $total;
	}

	/**
	 * Outbound delivery counts by status since a GMT datetime.
	 *
	 * Outbound only: an inbound row (the Pro receiving endpoint's record of
	 * a call someone made to this site) is not a delivery this site made,
	 * so it belongs in neither the success nor the failure figure of the
	 * dashboard's "Webhook success" card.
	 *
	 * @param string $mysql_gmt_datetime 'Y-m-d H:i:s' in GMT.
	 * @return array{success:int, failed:int, pending:int, retrying:int}
	 */
	public function stats_since( string $mysql_gmt_datetime ): array {
		global $wpdb;

		$stats = [
			'success'  => 0,
			'failed'   => 0,
			'pending'  => 0,
			'retrying' => 0,
		];
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT status, COUNT(*) AS n FROM {$wpdb->prefix}bfag_webhook_deliveries WHERE direction = %s AND created_at >= %s GROUP BY status LIMIT %d", 'out', $mysql_gmt_datetime, 10 ),
			ARRAY_A
		);
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			if ( is_array( $row ) && isset( $row['status'] ) && array_key_exists( (string) $row['status'], $stats ) ) {
				$stats[ (string) $row['status'] ] = (int) ( $row['n'] ?? 0 );
			}
		}
		return $stats;
	}

	/**
	 * How many of the most recent outgoing attempts for a webhook failed in
	 * a row — zero as soon as the newest finished attempt succeeded.
	 *
	 * @param int $webhook_id Webhook id.
	 */
	public function consecutive_failures( int $webhook_id ): int {
		global $wpdb;

		$statuses = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT status FROM {$wpdb->prefix}bfag_webhook_deliveries WHERE webhook_id = %d AND direction = %s AND status IN ('success','failed') ORDER BY id DESC LIMIT %d",
				$webhook_id,
				'out',
				100
			)
		);
		$count    = 0;
		foreach ( is_array( $statuses ) ? $statuses : [] as $status ) {
			if ( 'failed' !== $status ) {
				break;
			}
			++$count;
		}
		return $count;
	}

	/**
	 * Status of the newest outgoing delivery for a webhook, or null when none.
	 *
	 * @param int $webhook_id Webhook id.
	 */
	public function last_status( int $webhook_id ): ?string {
		global $wpdb;

		$status = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT status FROM {$wpdb->prefix}bfag_webhook_deliveries WHERE webhook_id = %d AND direction = %s ORDER BY id DESC LIMIT 1", $webhook_id, 'out' )
		);
		return is_string( $status ) && '' !== $status ? $status : null;
	}

	/**
	 * The wpdb format strings for a column set.
	 *
	 * @param array<string, mixed> $columns Column => value.
	 * @return list<string>
	 */
	private function formats( array $columns ): array {
		$ints = [ 'webhook_id', 'truncated', 'http_status', 'attempt' ];
		$out  = [];
		foreach ( array_keys( $columns ) as $column ) {
			$out[] = in_array( $column, $ints, true ) ? '%d' : '%s';
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
		$row['webhook_id']  = (int) ( $row['webhook_id'] ?? 0 );
		$row['truncated']   = (int) ( $row['truncated'] ?? 0 );
		$row['attempt']     = (int) ( $row['attempt'] ?? 1 );
		$row['http_status'] = isset( $row['http_status'] ) && is_numeric( $row['http_status'] ) ? (int) $row['http_status'] : null;
		$row['payload']     = (string) ( $row['payload'] ?? '' );
		$row['status']      = (string) ( $row['status'] ?? 'pending' );
		$row['event']       = (string) ( $row['event'] ?? '' );
		return $row;
	}
}
