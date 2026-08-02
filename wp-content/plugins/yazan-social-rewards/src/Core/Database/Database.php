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
	 * The store this request belongs to.
	 *
	 * Read from the tenancy kernel, which is an mu-plugin and therefore present before any plugin
	 * loads. Falls back to store 1 when it is absent, which is the value every existing row already
	 * carries — so a site without the kernel behaves exactly as it did before tenancy existed.
	 *
	 * @return int
	 */
	public function store_id(): int {
		return \class_exists( 'Yazan_Store_Context' ) ? \Yazan_Store_Context::current() : 1;
	}

	/**
	 * Whether a fully-qualified table belongs to this plugin, and therefore carries `store_id`.
	 *
	 * Asked by name rather than by consulting `Yazan_Store_Schema`, deliberately: this plugin has
	 * ZERO PHP dependency on yazan-core and that is worth keeping. Every table this plugin owns is
	 * created through `table()`, so the prefix is a complete and self-contained answer.
	 *
	 * @param string $table Fully-qualified table name.
	 * @return bool
	 */
	public function owns( string $table ): bool {
		return 0 === strpos( $table, $this->wpdb()->prefix . self::TABLE_PREFIX );
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
		/*
		 * ⚠️ THE STORE IS STAMPED HERE, NOT BY THE 23 CALLERS.
		 *
		 * Every row this plugin writes carries `store_id`, and the column defaults to 1 — which is
		 * why forgetting it is invisible: the insert succeeds and the row silently joins store 1.
		 * Stamping at the one place every repository already funnels through makes that impossible
		 * to forget rather than merely discouraged.
		 *
		 * An explicit `store_id` from the caller wins, so a migration or a clone can place a row in
		 * a store other than the active one.
		 */
		if ( $this->owns( $table ) && ! array_key_exists( 'store_id', $data ) ) {
			$data['store_id'] = $this->store_id();
			$formats[]        = '%d';
		}

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
		/*
		 * ⚠️ THE STORE JOINS THE **WHERE**, NOT THE DATA.
		 *
		 * A row never changes tenant. Adding `store_id` to `$data` would silently move rows between
		 * stores; adding it to `$where` makes an update aimed at another store's row a no-op that
		 * returns 0 rows affected — which is the honest answer.
		 *
		 * This is what turns "the id came from the request" from a tenancy breach into nothing at
		 * all, everywhere in the plugin at once.
		 */
		if ( $this->owns( $table ) && ! array_key_exists( 'store_id', $where ) ) {
			$where['store_id']  = $this->store_id();
			$where_formats[]    = '%d';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb()->update( $table, $data, $where, $data_formats, $where_formats );
		return false === $result ? -1 : (int) $result;
	}

	/**
	 * Delete rows, always bounded to the active store.
	 *
	 * Added because four repositories reach for `$wpdb->delete()` directly. Giving them a scoped
	 * equivalent is what lets those call sites be corrected without each one restating the rule.
	 *
	 * @param string $table         Full table name.
	 * @param array  $where         column => value.
	 * @param array  $where_formats Formats for $where.
	 * @return int Rows deleted (-1 on error).
	 */
	public function delete( string $table, array $where, array $where_formats ): int {
		if ( $this->owns( $table ) && ! array_key_exists( 'store_id', $where ) ) {
			$where['store_id'] = $this->store_id();
			$where_formats[]   = '%d';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb()->delete( $table, $where, $where_formats );
		return false === $result ? -1 : (int) $result;
	}

	/**
	 * A `store_id = N` fragment for hand-built SQL.
	 *
	 * Safe to interpolate: the value is cast to int here, never taken from a request. Repositories
	 * that build their own SELECT use this rather than restating the predicate, so the store the
	 * plugin scopes to is decided in exactly one place.
	 *
	 * @param string $alias Optional table alias, e.g. 'l' for `FROM … l`.
	 * @return string
	 */
	public function scope( string $alias = '' ): string {
		$column = '' === $alias ? 'store_id' : $alias . '.store_id';

		return $column . ' = ' . $this->store_id();
	}
}
