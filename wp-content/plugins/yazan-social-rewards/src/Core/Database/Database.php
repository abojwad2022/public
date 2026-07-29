<?php
/**
 * $wpdb wrapper.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralises access to $wpdb and this plugin's table naming. The ONLY places
 * raw SQL is written are the repositories, and every value they pass flows
 * through prepare()/insert() here.
 */
final class Database {

	/** Table-name prefix segment owned by this plugin (after the site prefix). */
	public const TABLE_PREFIX = 'yazan_rw_';

	/**
	 * The global $wpdb instance.
	 *
	 * @return \wpdb
	 */
	public function wpdb(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	/**
	 * Fully-qualified table name: {site_prefix}yazan_rw_{$name}.
	 *
	 * @param string $name Bare table name (e.g. "points_ledger").
	 * @return string
	 */
	public function table( string $name ): string {
		return $this->wpdb()->prefix . self::TABLE_PREFIX . $name;
	}

	/**
	 * Charset/collate clause for CREATE TABLE.
	 *
	 * @return string
	 */
	public function charset_collate(): string {
		return $this->wpdb()->get_charset_collate();
	}

	/**
	 * Prepared query passthrough. Callers build SQL with %s/%d/%f placeholders;
	 * only trusted table names (from table()) may be interpolated.
	 *
	 * @param string $sql  SQL with placeholders.
	 * @param array  $args Bound values.
	 * @return string Prepared SQL.
	 */
	public function prepare( string $sql, array $args ): string {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $this->wpdb()->prepare( $sql, $args );
	}

	/**
	 * Insert a row (parameterised by $wpdb->insert).
	 *
	 * @param string        $table   Full table name.
	 * @param array         $data    column => value.
	 * @param array<string> $formats %d/%s/%f per column.
	 * @return int Insert id (0 on failure).
	 */
	public function insert( string $table, array $data, array $formats ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $this->wpdb()->insert( $table, $data, $formats );
		return $ok ? (int) $this->wpdb()->insert_id : 0;
	}

	/**
	 * Update rows (parameterised by $wpdb->update).
	 *
	 * @param string $table         Full table name.
	 * @param array  $data          column => value.
	 * @param array  $where         column => value.
	 * @param array  $data_formats  Formats for $data.
	 * @param array  $where_formats Formats for $where.
	 * @return int Rows affected (-1 on error).
	 */
	public function update( string $table, array $data, array $where, array $data_formats, array $where_formats ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb()->update( $table, $data, $where, $data_formats, $where_formats );
		return false === $result ? -1 : (int) $result;
	}
}
