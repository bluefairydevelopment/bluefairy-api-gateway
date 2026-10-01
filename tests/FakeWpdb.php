<?php
/**
 * A recording, scriptable stand-in for $wpdb.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests;

// phpcs:disable WordPress.DB, WordPress.NamingConventions.ValidVariableName, Squiz.Commenting

/**
 * Every call is recorded in $calls (method, sql, args, table, data). Return
 * values come from a per-method FIFO queue (queue()); when the queue is empty
 * the method returns an empty/neutral value. prepare() performs WordPress's
 * placeholder substitution so tests can assert on the final SQL text.
 *
 * It never executes SQL. Tests that need real SQL semantics (the HitCounter
 * concurrency test) use SqliteWpdb from tests/stubs instead.
 */
class FakeWpdb {

	public string $prefix   = 'bfw_';
	public string $options  = 'bfw_options';
	public string $posts    = 'bfw_posts';
	public string $users    = 'bfw_users';
	public string $postmeta = 'bfw_postmeta';
	public int $insert_id   = 0;
	public int $rows_affected = 0;
	public string $last_error = '';
	public string $last_query = '';

	/** @var list<array<string, mixed>> */
	public array $calls = [];

	/** @var array<string, list<mixed>> */
	private array $queue = [];

	/** @var array<string, mixed> */
	private array $fallback = [];

	/**
	 * Unprefixed table names `SHOW TABLES LIKE` reports as absent. Every other
	 * table exists: the query answers with the table name, as MySQL does, and
	 * never consumes the get_var queue.
	 *
	 * @var list<string>
	 */
	private array $missing_tables = [];

	/**
	 * Make `SHOW TABLES LIKE` report these (unprefixed) tables as absent.
	 *
	 * @param list<string> $tables Unprefixed table names.
	 */
	public function missing_tables( array $tables ): void {
		$this->missing_tables = $tables;
	}

	/**
	 * Queue the next return value for a method (get_results, get_row,
	 * get_var, get_col, query, insert, update, delete, replace).
	 */
	public function queue( string $method, mixed $value ): void {
		$this->queue[ $method ][] = $value;
	}

	/**
	 * A standing return value for a method, used when its queue is empty.
	 */
	public function always( string $method, mixed $value ): void {
		$this->fallback[ $method ] = $value;
	}

	/**
	 * SQL of every recorded call whose text contains $needle.
	 *
	 * @return list<string>
	 */
	public function queries_matching( string $needle ): array {
		$out = [];
		foreach ( $this->calls as $call ) {
			if ( isset( $call['sql'] ) && str_contains( (string) $call['sql'], $needle ) ) {
				$out[] = (string) $call['sql'];
			}
		}
		return $out;
	}

	/**
	 * Recorded calls of one method.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function calls_to( string $method ): array {
		return array_values( array_filter( $this->calls, static fn( array $c ): bool => $c['method'] === $method ) );
	}

	public function prepare( string $query, mixed ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$query = str_replace( "'%s'", '%s', $query );
		$query = str_replace( '"%s"', '%s', $query );
		$query = preg_replace( '/(?<!%)%(?!%)(?:\d+\$)?s/', "'%s'", $query ) ?? $query;
		$query = preg_replace( '/(?<!%)%(?!%)(?:\d+\$)?i/', '`%s`', $query ) ?? $query;
		$escaped = array_map(
			static fn( mixed $a ): mixed => is_string( $a ) ? addslashes( $a ) : ( is_float( $a ) ? $a : (int) $a ),
			$args
		);
		return str_replace( '%%', '%', vsprintf( $query, $escaped ) );
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
	}

	public function db_version(): string {
		return '8.0.36';
	}

	public function get_results( string $query, string $output = 'OBJECT' ): mixed {
		return $this->record( 'get_results', $query, [] );
	}

	public function get_row( string $query, string $output = 'OBJECT', int $y = 0 ): mixed {
		return $this->record( 'get_row', $query, null );
	}

	public function get_var( string $query, int $x = 0, int $y = 0 ): mixed {
		if ( 1 === preg_match( "/^SHOW TABLES LIKE '([^']+)'$/", $query, $m ) ) {
			$this->last_query = $query;
			$this->calls[]    = [ 'method' => 'get_var', 'sql' => $query ];
			// prepare() addslashes the value and esc_like() escaped `_`; undo both.
			$table            = str_replace( [ '\\_', '\\%' ], [ '_', '%' ], stripslashes( $m[1] ) );
			$unprefixed       = str_starts_with( $table, $this->prefix ) ? substr( $table, strlen( $this->prefix ) ) : $table;
			return in_array( $unprefixed, $this->missing_tables, true ) ? null : $table;
		}
		return $this->record( 'get_var', $query, null );
	}

	public function get_col( string $query, int $x = 0 ): mixed {
		return $this->record( 'get_col', $query, [] );
	}

	public function query( string $query ): mixed {
		$result              = $this->record( 'query', $query, 0 );
		$this->rows_affected = is_int( $result ) ? $result : 0;
		return $result;
	}

	public function insert( string $table, array $data, mixed $format = null ): int|false {
		$this->calls[] = [ 'method' => 'insert', 'table' => $table, 'data' => $data, 'format' => $format ];
		$queued        = $this->next( 'insert', 1 );
		if ( false !== $queued ) {
			++$this->insert_id;
		}
		return $queued;
	}

	public function replace( string $table, array $data, mixed $format = null ): int|false {
		$this->calls[] = [ 'method' => 'replace', 'table' => $table, 'data' => $data, 'format' => $format ];
		return $this->next( 'replace', 1 );
	}

	public function update( string $table, array $data, array $where, mixed $format = null, mixed $where_format = null ): int|false {
		$this->calls[] = [ 'method' => 'update', 'table' => $table, 'data' => $data, 'where' => $where ];
		return $this->next( 'update', 1 );
	}

	public function delete( string $table, array $where, mixed $where_format = null ): int|false {
		$this->calls[] = [ 'method' => 'delete', 'table' => $table, 'where' => $where ];
		return $this->next( 'delete', 1 );
	}

	public function remove_placeholder_escape( string $query ): string {
		return $query;
	}

	private function record( string $method, string $sql, mixed $default ): mixed {
		$this->last_query = $sql;
		$this->calls[]    = [ 'method' => $method, 'sql' => $sql ];
		return $this->next( $method, $default );
	}

	private function next( string $method, mixed $default ): mixed {
		if ( ! empty( $this->queue[ $method ] ) ) {
			return array_shift( $this->queue[ $method ] );
		}
		return $this->fallback[ $method ] ?? $default;
	}
}
