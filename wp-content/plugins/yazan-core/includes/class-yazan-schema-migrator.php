<?php
/**
 * Raw DDL that dbDelta cannot express.
 *
 * WHY THIS EXISTS
 * ---------------
 * Every schema change in this codebase has so far gone through `dbDelta` over a `CREATE TABLE`
 * string, and that has been enough because every change has been additive. The multi-tenant
 * migration is the first that is not: it has to widen existing UNIQUE keys and rebuild a PRIMARY
 * KEY, and **dbDelta does neither — it ignores both, silently**. A migration that appears to
 * succeed while leaving a UNIQUE key un-widened is the worst possible outcome, because the key
 * then rejects a second store's perfectly legitimate row and the error surfaces far from its cause.
 *
 * So this class issues real `ALTER TABLE`. Three properties make that safe enough to run
 * unattended on every request:
 *
 *   1. **Everything is guarded.** No statement runs unless the schema is actually in the state that
 *      needs it. `add_column()` on an existing column is a no-op, not an error.
 *   2. **Everything is re-runnable.** Running a step twice is identical to running it once. The
 *      migrators that call this re-run every activate() on each version bump, so this is a
 *      requirement, not a nicety.
 *   3. **Everything reports.** Each method returns what it did and why, and failures are recorded
 *      rather than swallowed. `dbDelta` returns a diff nobody reads; this returns a decision.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 * --------------------------------
 * No transactions. MySQL DDL is implicitly committed and cannot be rolled back inside one, so
 * pretending otherwise would be theatre. The rollback story is `Yazan_Backup_Engine` — which is
 * why its four defects were fixed before a single line of this ran.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guarded, re-runnable ALTER TABLE.
 */
class Yazan_Schema_Migrator {

	/** Result: the schema already looked like this. */
	const SKIPPED = 'skipped';

	/** Result: a statement ran and succeeded. */
	const APPLIED = 'applied';

	/** Result: a statement ran and failed. */
	const FAILED = 'failed';

	/** Result: the table does not exist, so there was nothing to alter. */
	const ABSENT = 'absent';

	/**
	 * Everything this class did during the request. Read by the tests and the status endpoint.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private static $log = array();

	/* --------------------------------------------------------------------- */
	/* Introspection                                                          */
	/* --------------------------------------------------------------------- */

	/**
	 * Does a table exist?
	 *
	 * @param string $table Fully-qualified table name.
	 * @return bool
	 */
	public static function table_exists( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return $found === $table;
	}

	/**
	 * Does a column exist on a table?
	 *
	 * `SHOW COLUMNS` rather than `information_schema`: it needs no extra privilege, it is not
	 * affected by the metadata-cache staleness that bites information_schema on some hosts, and
	 * the pattern is already established in this codebase.
	 *
	 * @param string $table  Fully-qualified table name.
	 * @param string $column Column name.
	 * @return bool
	 */
	public static function column_exists( $table, $column ) {
		global $wpdb;

		if ( ! self::table_exists( $table ) ) {
			return false;
		}

		$quoted = self::quote( $table );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$quoted} LIKE %s", $wpdb->esc_like( $column ) ) );

		return null !== $found;
	}

	/**
	 * Does an index exist, and if so which columns does it cover?
	 *
	 * Returns the column list rather than a bool so callers can tell "the key exists but is the old
	 * shape" from "the key is already what we want" — which is the whole difference between a
	 * re-run and a real migration.
	 *
	 * @param string $table Fully-qualified table name.
	 * @param string $index Index name.
	 * @return string[]|null Ordered column names, or null when the index is absent.
	 */
	public static function index_columns( $table, $index ) {
		global $wpdb;

		if ( ! self::table_exists( $table ) ) {
			return null;
		}

		$quoted = self::quote( $table );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM {$quoted} WHERE Key_name = %s", $index ), ARRAY_A );

		if ( empty( $rows ) ) {
			return null;
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'];
			}
		);

		return array_map(
			static function ( $row ) {
				return (string) $row['Column_name'];
			},
			$rows
		);
	}

	/**
	 * Does an index exist at all?
	 *
	 * @param string $table Table.
	 * @param string $index Index name.
	 * @return bool
	 */
	public static function index_exists( $table, $index ) {
		return null !== self::index_columns( $table, $index );
	}

	/* --------------------------------------------------------------------- */
	/* Mutation                                                               */
	/* --------------------------------------------------------------------- */

	/**
	 * Add a column if it is missing.
	 *
	 * THE DEFAULT IS THE MIGRATION. Adding `store_id BIGINT UNSIGNED NOT NULL DEFAULT 1` sets every
	 * existing row to 1 in the same statement — there is no backfill pass, no window in which rows
	 * are unattributed, and no script that can be interrupted half-way. That is what makes "no data
	 * loss" and "existing data becomes store 1" a property of the DDL rather than a hope about a
	 * loop.
	 *
	 * ⚠️ A default belongs on the COLUMN and never in a QUERY. A query layer that assumes store 1
	 * turns cross-tenant reads — invisible — into the normal case; one that demands an explicit
	 * store turns them into outages, which are visible and therefore fixable.
	 *
	 * @param string $table      Fully-qualified table name.
	 * @param string $column     Column name.
	 * @param string $definition SQL type and constraints, e.g. "bigint unsigned NOT NULL DEFAULT 1".
	 * @return string One of the class result constants.
	 */
	public static function add_column( $table, $column, $definition ) {
		if ( ! self::table_exists( $table ) ) {
			return self::record( $table, "add column {$column}", self::ABSENT, 'table does not exist' );
		}

		if ( self::column_exists( $table, $column ) ) {
			return self::record( $table, "add column {$column}", self::SKIPPED, 'column already present' );
		}

		$sql = sprintf(
			'ALTER TABLE %s ADD COLUMN `%s` %s',
			self::quote( $table ),
			str_replace( '`', '``', $column ),
			$definition
		);

		return self::run( $table, "add column {$column}", $sql );
	}

	/**
	 * Add an index if it is missing.
	 *
	 * @param string   $table   Table.
	 * @param string   $index   Index name.
	 * @param string[] $columns Columns, in order.
	 * @return string
	 */
	public static function add_index( $table, $index, array $columns ) {
		if ( ! self::table_exists( $table ) ) {
			return self::record( $table, "add index {$index}", self::ABSENT, 'table does not exist' );
		}

		if ( self::index_exists( $table, $index ) ) {
			return self::record( $table, "add index {$index}", self::SKIPPED, 'index already present' );
		}

		foreach ( $columns as $column ) {
			if ( ! self::column_exists( $table, $column ) ) {
				return self::record( $table, "add index {$index}", self::ABSENT, "column {$column} does not exist" );
			}
		}

		$sql = sprintf(
			'ALTER TABLE %s ADD KEY `%s` (%s)',
			self::quote( $table ),
			str_replace( '`', '``', $index ),
			self::column_list( $columns )
		);

		return self::run( $table, "add index {$index}", $sql );
	}

	/**
	 * Replace a UNIQUE key with a wider one.
	 *
	 * ⚠️ THIS IS THE OPERATION dbDelta CANNOT DO. It compares desired tables against actual ones
	 * and emits ADD statements; it never drops, so an index whose definition changed is left
	 * exactly as it was and dbDelta reports nothing. A migration relying on it to widen
	 * `UNIQUE(slug)` into `UNIQUE(store_id, slug)` appears to succeed and silently does not.
	 *
	 * DROP and ADD are issued as ONE `ALTER TABLE`. Two separate statements would leave a window in
	 * which no uniqueness constraint exists at all, and a concurrent write in that window inserts
	 * the duplicate the constraint was there to prevent — after which the ADD fails and the table
	 * is left with no key.
	 *
	 * @param string   $table   Table.
	 * @param string   $index   Index name to replace.
	 * @param string[] $columns The new column list.
	 * @return string
	 */
	public static function rekey_unique( $table, $index, array $columns ) {
		if ( ! self::table_exists( $table ) ) {
			return self::record( $table, "rekey {$index}", self::ABSENT, 'table does not exist' );
		}

		$current = self::index_columns( $table, $index );

		if ( $current === $columns ) {
			return self::record( $table, "rekey {$index}", self::SKIPPED, 'index already has the target shape' );
		}

		foreach ( $columns as $column ) {
			if ( ! self::column_exists( $table, $column ) ) {
				return self::record( $table, "rekey {$index}", self::ABSENT, "column {$column} does not exist" );
			}
		}

		$quoted = self::quote( $table );
		$name   = str_replace( '`', '``', $index );
		$cols   = self::column_list( $columns );

		$sql = null === $current
			? "ALTER TABLE {$quoted} ADD UNIQUE KEY `{$name}` ({$cols})"
			: "ALTER TABLE {$quoted} DROP INDEX `{$name}`, ADD UNIQUE KEY `{$name}` ({$cols})";

		return self::run( $table, "rekey {$index}", $sql );
	}

	/**
	 * Rebuild a table's PRIMARY KEY.
	 *
	 * The sharpest edge in this class, and unavoidable: `wp_yazan_rw_analytics_daily` has no
	 * surrogate id — its PRIMARY KEY *is* `(stat_date, metric, dimension)` — so giving it a store
	 * dimension means rebuilding the key. dbDelta ignores PRIMARY KEY changes entirely.
	 *
	 * DROP and ADD in one statement, for the same reason as rekey_unique(). MySQL rejects the ADD
	 * if the new key is not unique over the existing rows, and because the DROP is in the same
	 * statement the whole thing rolls back — the table keeps its old key rather than ending up with
	 * none.
	 *
	 * @param string   $table   Table.
	 * @param string[] $columns New primary key columns, in order.
	 * @return string
	 */
	public static function rebuild_primary_key( $table, array $columns ) {
		if ( ! self::table_exists( $table ) ) {
			return self::record( $table, 'rebuild primary key', self::ABSENT, 'table does not exist' );
		}

		$current = self::index_columns( $table, 'PRIMARY' );

		if ( $current === $columns ) {
			return self::record( $table, 'rebuild primary key', self::SKIPPED, 'primary key already has the target shape' );
		}

		foreach ( $columns as $column ) {
			if ( ! self::column_exists( $table, $column ) ) {
				return self::record( $table, 'rebuild primary key', self::ABSENT, "column {$column} does not exist" );
			}
		}

		$quoted = self::quote( $table );
		$cols   = self::column_list( $columns );

		$sql = null === $current
			? "ALTER TABLE {$quoted} ADD PRIMARY KEY ({$cols})"
			: "ALTER TABLE {$quoted} DROP PRIMARY KEY, ADD PRIMARY KEY ({$cols})";

		return self::run( $table, 'rebuild primary key', $sql );
	}

	/**
	 * Drop a column if it is present. The reverse of add_column(), for rollback.
	 *
	 * @param string $table  Table.
	 * @param string $column Column.
	 * @return string
	 */
	public static function drop_column( $table, $column ) {
		if ( ! self::column_exists( $table, $column ) ) {
			return self::record( $table, "drop column {$column}", self::SKIPPED, 'column is not present' );
		}

		$sql = sprintf( 'ALTER TABLE %s DROP COLUMN `%s`', self::quote( $table ), str_replace( '`', '``', $column ) );

		return self::run( $table, "drop column {$column}", $sql );
	}

	/* --------------------------------------------------------------------- */
	/* Reporting                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * Everything attempted during this request.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function log() {
		return self::$log;
	}

	/**
	 * Did anything fail?
	 *
	 * @return bool
	 */
	public static function had_failures() {
		foreach ( self::$log as $entry ) {
			if ( self::FAILED === $entry['result'] ) {
				return true;
			}
		}

		return false;
	}

	/** Forget the log. Tests only. */
	public static function reset_log() {
		self::$log = array();
	}

	/* --------------------------------------------------------------------- */
	/* Internals                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * Execute a statement and record the outcome.
	 *
	 * Errors are surfaced, not suppressed. A DDL failure that nobody notices is how a half-migrated
	 * schema reaches production.
	 *
	 * @param string $table  Table, for the log.
	 * @param string $action Human description.
	 * @param string $sql    Statement.
	 * @return string
	 */
	private static function run( $table, $action, $sql ) {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query( $sql );
		$error  = (string) $wpdb->last_error;

		$wpdb->suppress_errors( $suppress );

		if ( false === $result ) {
			return self::record( $table, $action, self::FAILED, $error, $sql );
		}

		return self::record( $table, $action, self::APPLIED, '', $sql );
	}

	/**
	 * Append to the log, mirror to the audit trail, and return the result.
	 *
	 * Only real changes and failures are audited — logging every skipped no-op would bury the
	 * signal, and a re-run produces nothing but skips.
	 *
	 * @param string $table  Table.
	 * @param string $action Description.
	 * @param string $result One of the class constants.
	 * @param string $detail Reason or error message.
	 * @param string $sql    Statement, when one ran.
	 * @return string
	 */
	private static function record( $table, $action, $result, $detail = '', $sql = '' ) {
		self::$log[] = array(
			'table'  => $table,
			'action' => $action,
			'result' => $result,
			'detail' => $detail,
			'sql'    => $sql,
		);

		if ( ( self::APPLIED === $result || self::FAILED === $result ) && class_exists( 'Yazan_Dashboard_Audit' ) ) {
			Yazan_Dashboard_Audit::log(
				'schema.migrate',
				'settings',
				0,
				array(
					'table'  => $table,
					'action' => $action,
					'result' => $result,
					'detail' => $detail,
				)
			);
		}

		if ( self::FAILED === $result && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[yazan-schema] FAILED %s on %s: %s', $action, $table, $detail ) );
		}

		return $result;
	}

	/**
	 * Backtick-quote a table name.
	 *
	 * Table names here come from our own `Yazan_DB::table()` and its siblings, never from a
	 * request — but quoting costs nothing and makes that assumption explicit rather than implied.
	 *
	 * @param string $table Table name.
	 * @return string
	 */
	private static function quote( $table ) {
		return '`' . str_replace( '`', '``', $table ) . '`';
	}

	/**
	 * Render an ordered column list for an index definition.
	 *
	 * @param string[] $columns Columns.
	 * @return string
	 */
	private static function column_list( array $columns ) {
		return implode(
			',',
			array_map(
				static function ( $column ) {
					return '`' . str_replace( '`', '``', $column ) . '`';
				},
				$columns
			)
		);
	}
}
