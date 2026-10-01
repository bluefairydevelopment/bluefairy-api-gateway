<?php
/**
 * RateLimit module stubs: a $wpdb that really executes SQL, for the SEC6
 * concurrency test.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests;

// phpcs:disable WordPress.DB, WordPress.NamingConventions.ValidVariableName, Squiz.Commenting

/**
 * FakeWpdb records and answers from a script; it never counts anything. The
 * insert-then-count property of HitCounter::consume() can only be proven by
 * a store that actually counts its own rows, so this subclass runs the few
 * statements HitCounter issues against an in-memory SQLite database.
 *
 * Only the `bfag_rate_limit_hits` table exists. Every other method still
 * behaves as FakeWpdb (recorded, scripted). Calls are recorded in $calls as
 * well, so calls_to() and queries_matching() keep working.
 *
 * MySQL-isms translated: `DELETE … LIMIT n` (SQLite has no DELETE LIMIT
 * unless compiled with it) becomes a rowid subquery; `TRUNCATE TABLE t`
 * becomes `DELETE FROM t`. prepare() output quotes strings with addslashes;
 * none of the values HitCounter writes contain a quote, so that is left as is.
 */
class SqliteWpdb extends FakeWpdb {

	private \PDO $pdo;

	public function __construct() {
		$this->pdo = new \PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		$this->pdo->exec(
			'CREATE TABLE ' . $this->prefix . 'bfag_rate_limit_hits (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				subject TEXT NOT NULL,
				route TEXT NOT NULL,
				route_hash TEXT NOT NULL,
				hit_at TEXT NOT NULL
			)'
		);
	}

	/**
	 * The underlying connection, for tests that seed or inspect rows directly.
	 */
	public function pdo(): \PDO {
		return $this->pdo;
	}

	/**
	 * `SHOW TABLE STATUS LIKE` for the hits table answers with the real row
	 * count as `Rows` (MySQL's estimate; exact here). Anything else is
	 * FakeWpdb (recorded, scripted).
	 */
	public function get_row( string $query, string $output = 'OBJECT', int $y = 0 ): mixed {
		if ( 1 === preg_match( "/^SHOW TABLE STATUS LIKE '([^']+)'$/", $query, $m ) ) {
			$table = str_replace( [ '\\_', '\\%' ], [ '_', '%' ], stripslashes( $m[1] ) );
			if ( $this->prefix . 'bfag_rate_limit_hits' === $table ) {
				$this->calls[] = [ 'method' => 'get_row', 'sql' => $query ];
				return [ 'Rows' => (int) $this->pdo->query( 'SELECT COUNT(*) FROM ' . $table )->fetchColumn() ];
			}
		}
		return parent::get_row( $query, $output, $y );
	}

	/**
	 * Insert a hit row with hit_at some seconds in the past (or future),
	 * bypassing HitCounter.
	 */
	public function seed_hit( string $subject, string $route, int $age_seconds ): void {
		$this->insert(
			$this->prefix . 'bfag_rate_limit_hits',
			[
				'subject'    => $subject,
				'route'      => $route,
				'route_hash' => substr( hash( 'sha256', $route ), 0, 16 ),
				'hit_at'     => gmdate( 'Y-m-d H:i:s', time() - $age_seconds ),
			]
		);
	}

	public function insert( string $table, array $data, mixed $format = null ): int|false {
		$this->calls[] = [ 'method' => 'insert', 'table' => $table, 'data' => $data, 'format' => $format ];
		if ( $this->prefix . 'bfag_rate_limit_hits' !== $table ) {
			return parent::insert( $table, $data, $format );
		}
		$columns = array_keys( $data );
		$sql     = 'INSERT INTO ' . $table . ' (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', array_fill( 0, count( $columns ), '?' ) ) . ')';
		$stmt    = $this->pdo->prepare( $sql );
		$stmt->execute( array_values( $data ) );
		$this->insert_id = (int) $this->pdo->lastInsertId();
		return 1;
	}

	public function get_var( string $query, int $x = 0, int $y = 0 ): mixed {
		if ( ! $this->is_hits_query( $query ) ) {
			return parent::get_var( $query, $x, $y );
		}
		$this->last_query = $query;
		$this->calls[]    = [ 'method' => 'get_var', 'sql' => $query ];
		$stmt             = $this->pdo->query( $this->translate( $query ) );
		$value            = false === $stmt ? null : $stmt->fetchColumn( $x );
		return false === $value ? null : (string) $value;
	}

	public function query( string $query ): mixed {
		if ( ! $this->is_hits_query( $query ) ) {
			return parent::query( $query );
		}
		$this->last_query    = $query;
		$this->calls[]       = [ 'method' => 'query', 'sql' => $query ];
		$affected            = $this->pdo->exec( $this->translate( $query ) );
		$this->rows_affected = is_int( $affected ) ? $affected : 0;
		return $this->rows_affected;
	}

	private function is_hits_query( string $query ): bool {
		return str_contains( $query, $this->prefix . 'bfag_rate_limit_hits' ) && ! str_starts_with( $query, 'SHOW TABLES' );
	}

	private function translate( string $query ): string {
		$table = $this->prefix . 'bfag_rate_limit_hits';
		if ( 1 === preg_match( '/^\s*DELETE FROM ' . preg_quote( $table, '/' ) . ' WHERE (.+?) LIMIT (\d+)\s*$/s', $query, $m ) ) {
			return "DELETE FROM {$table} WHERE rowid IN (SELECT rowid FROM {$table} WHERE {$m[1]} LIMIT {$m[2]})";
		}
		if ( 1 === preg_match( '/^\s*TRUNCATE TABLE ' . preg_quote( $table, '/' ) . '\s*$/', $query ) ) {
			return "DELETE FROM {$table}";
		}
		return $query;
	}
}
