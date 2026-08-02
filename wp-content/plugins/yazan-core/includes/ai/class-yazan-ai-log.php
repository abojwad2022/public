<?php
/**
 * Yazan AI — generation log.
 *
 * Every call that reaches a provider (and every cache hit / failure) rows into a small custom table.
 * This is the audit + cost ledger that powers the Analytics and Diagnostics modules. It mirrors the
 * {@see Yazan_Dashboard_Audit} table pattern exactly: dbDelta create, schema-version option gate,
 * parameterised writes, prepared reads.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Append-only ledger of AI generations.
 */
class Yazan_AI_Log {

	/** Bump when the schema below changes. */
	const SCHEMA_VERSION = '1';

	/** Option storing the installed schema version. */
	const SCHEMA_OPTION = 'yazan_ai_schema_version';

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table() {
		return Yazan_DB::table( 'ai_generations' );
	}

	/**
	 * Create/upgrade the table. Idempotent. Called from {@see Yazan_Core_Installer::install()}.
	 *
	 * @return void
	 */
	public static function install_table() {
		if ( get_option( self::SCHEMA_OPTION ) === self::SCHEMA_VERSION ) {
			return;
		}

		global $wpdb;
		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			module varchar(40) NOT NULL DEFAULT '',
			task varchar(60) NOT NULL DEFAULT '',
			object_type varchar(20) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			provider varchar(30) NOT NULL DEFAULT '',
			model varchar(80) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT '',
			error_code varchar(40) NOT NULL DEFAULT '',
			tokens_in int(11) unsigned NOT NULL DEFAULT 0,
			tokens_out int(11) unsigned NOT NULL DEFAULT 0,
			cost_usd decimal(12,6) NOT NULL DEFAULT 0.000000,
			cached tinyint(1) NOT NULL DEFAULT 0,
			duration_ms int(11) unsigned NOT NULL DEFAULT 0,
			request_id varchar(40) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY module (module),
			KEY object (object_type,object_id)
		) {$collate};";

		dbDelta( $sql );
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Record one generation. Never throws — logging must not break a generation.
	 *
	 * @param array $row Keys: module, task, object_type, object_id, provider, model, status,
	 *                   error_code, tokens_in, tokens_out, cost_usd, cached, duration_ms, request_id.
	 * @return void
	 */
	public static function record( array $row ) {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			self::table(),
			array(
				'user_id'     => get_current_user_id(),
				'module'      => substr( sanitize_key( $row['module'] ?? '' ), 0, 40 ),
				'task'        => substr( sanitize_text_field( $row['task'] ?? '' ), 0, 60 ),
				'object_type' => substr( sanitize_key( $row['object_type'] ?? '' ), 0, 20 ),
				'object_id'   => absint( $row['object_id'] ?? 0 ),
				'provider'    => substr( sanitize_key( $row['provider'] ?? '' ), 0, 30 ),
				'model'       => substr( sanitize_text_field( $row['model'] ?? '' ), 0, 80 ),
				'status'      => substr( sanitize_key( $row['status'] ?? '' ), 0, 20 ),
				'error_code'  => substr( sanitize_key( $row['error_code'] ?? '' ), 0, 40 ),
				'tokens_in'   => absint( $row['tokens_in'] ?? 0 ),
				'tokens_out'  => absint( $row['tokens_out'] ?? 0 ),
				'cost_usd'    => round( (float) ( $row['cost_usd'] ?? 0 ), 6 ),
				'cached'      => ! empty( $row['cached'] ) ? 1 : 0,
				'duration_ms' => absint( $row['duration_ms'] ?? 0 ),
				'request_id'  => substr( sanitize_text_field( $row['request_id'] ?? '' ), 0, 40 ),
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%f', '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * Sum of cost + counts since a MySQL datetime (used for the monthly budget check).
	 *
	 * @param string $since MySQL datetime lower bound.
	 * @return array{cost:float,count:int,tokens_in:int,tokens_out:int}
	 */
	public static function totals_since( $since ) {
		global $wpdb;
		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(cost_usd),0) AS cost, COUNT(*) AS cnt,
				        COALESCE(SUM(tokens_in),0) AS tin, COALESCE(SUM(tokens_out),0) AS tout
				 FROM {$table} WHERE created_at >= %s AND status = 'ok'",
				sanitize_text_field( $since )
			)
		);
		// phpcs:enable

		return array(
			'cost'       => (float) ( $row->cost ?? 0 ),
			'count'      => (int) ( $row->cnt ?? 0 ),
			'tokens_in'  => (int) ( $row->tin ?? 0 ),
			'tokens_out' => (int) ( $row->tout ?? 0 ),
		);
	}

	/**
	 * Recent rows for the Diagnostics viewer (newest first).
	 *
	 * @param array $args page, per_page, module, status.
	 * @return array{items:array,total:int,total_pages:int,page:int,per_page:int}
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$table = self::table();

		$per_page = max( 1, min( 200, absint( $args['per_page'] ?? 50 ) ) );
		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['module'] ) ) {
			$where[]  = 'module = %s';
			$params[] = sanitize_key( $args['module'] );
		}
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( $args['status'] );
		}

		$clause = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$clause}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

		$rows_sql = "SELECT * FROM {$table} WHERE {$clause} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows     = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $params, array( $per_page, $offset ) ) ) );
		// phpcs:enable

		return array(
			'items'       => is_array( $rows ) ? $rows : array(),
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}
}
