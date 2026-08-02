<?php
/**
 * Base repository.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Foundation for the typed, per-table repositories. Concrete repositories name
 * their table and reuse the safe insert/update/find helpers here — so raw SQL
 * lives in one predictable layer, always parameterised.
 */
abstract class AbstractRepository {

	/**
	 * @param Database $db Database service.
	 */
	public function __construct( protected Database $db ) {}

	/**
	 * Bare table name (without prefix), e.g. "points_ledger".
	 *
	 * @return string
	 */
	abstract protected function table_name(): string;

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	protected function table(): string {
		return $this->db->table( $this->table_name() );
	}

	/**
	 * Insert a row.
	 *
	 * @param array         $data    column => value.
	 * @param array<string> $formats Formats.
	 * @return int Insert id.
	 */
	protected function insert( array $data, array $formats ): int {
		return $this->db->insert( $this->table(), $data, $formats );
	}

	/**
	 * Update rows by a single-column where.
	 *
	 * @param array  $data          column => value.
	 * @param array  $where         column => value.
	 * @param array  $data_formats  Formats for $data.
	 * @param array  $where_formats Formats for $where.
	 * @return int Rows affected.
	 */
	protected function update( array $data, array $where, array $data_formats, array $where_formats ): int {
		return $this->db->update( $this->table(), $data, $where, $data_formats, $where_formats );
	}

	/**
	 * Fetch a single row by primary key `id`.
	 *
	 * @param int $id Row id.
	 * @return object|null
	 */
	public function find( int $id ): ?object {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();

		/*
		 * ⚠️ THE STORE PREDICATE MATTERS MOST HERE, because this is the method that takes an id
		 * straight from a request. Without it, an admin approving a pending points entry by id, or
		 * a controller loading a reward to edit, reaches another tenant's row and every downstream
		 * check passes — the record is real, the caller is permitted, and the store is wrong.
		 *
		 * A miss returns null, which every caller already handles as "not found". That is the right
		 * answer: whether the id exists in some other store is not information this caller is owed.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND store_id = %d", $id, $this->db->store_id() )
		);

		return $row ?: null;
	}

	/**
	 * A `store_id = N` fragment for this repository's hand-built SQL.
	 *
	 * @param string $alias Optional table alias.
	 * @return string
	 */
	protected function scope( string $alias = '' ): string {
		return $this->db->scope( $alias );
	}

	/**
	 * Assemble a WHERE clause from a map of column => [operator, value] or
	 * column => value (defaults to `=`). Returns the clause plus ordered bind
	 * params. Column names are caller-supplied constants (never user input).
	 *
	 * ALWAYS BOUNDED TO THE ACTIVE STORE. The empty-conditions case used to return `('', array())`
	 * — a SELECT with no WHERE at all — which is precisely the "give me everything" shape that must
	 * not survive tenancy. It now returns the store predicate alone.
	 *
	 * ⚠️ NOTHING CALLS THIS. A repo-wide search finds exactly one occurrence: this definition. The
	 * 22 repositories each build their own SQL instead, which is why scoping this method alone
	 * would have secured nothing while looking like it secured everything. It is kept — and
	 * corrected — as the right shape for repositories that adopt it, not mistaken for coverage.
	 * `Database::scope()` is what the hand-built statements actually use.
	 *
	 * @param array $conditions Map.
	 * @return array{0:string,1:array} [ 'WHERE …', params ].
	 */
	protected function build_where( array $conditions ): array {
		if ( empty( $conditions ) ) {
			return array( 'WHERE ' . $this->scope(), array() );
		}
		$clauses = array( $this->scope() );
		$params  = array();
		foreach ( $conditions as $column => $spec ) {
			$column = preg_replace( '/[^a-z0-9_]/i', '', (string) $column );
			if ( is_array( $spec ) ) {
				$operator = strtoupper( (string) ( $spec[0] ?? '=' ) );
				$value    = $spec[1] ?? null;
			} else {
				$operator = '=';
				$value    = $spec;
			}
			$allowed_ops = array( '=', '!=', '<', '<=', '>', '>=', 'LIKE' );
			if ( ! in_array( $operator, $allowed_ops, true ) ) {
				$operator = '=';
			}
			$placeholder = is_int( $value ) ? '%d' : ( is_float( $value ) ? '%f' : '%s' );
			$clauses[]   = "{$column} {$operator} {$placeholder}";
			$params[]    = $value;
		}
		return array( 'WHERE ' . implode( ' AND ', $clauses ), $params );
	}
}
