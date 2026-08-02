<?php
/**
 * Yazan Dashboard — audit log.
 *
 * Records every state-changing dashboard action (create/update/delete/media/login) to a small custom
 * table. This is the ONE custom table in the project; everything else reuses WP/WooCommerce storage.
 * All writes go through $wpdb->insert() (parameterised) and all reads through $wpdb->prepare().
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Append-only audit trail for the dashboard.
 */
class Yazan_Dashboard_Audit {

	/** Bump when the table schema changes (drives re-install via dbDelta). */
	const SCHEMA_VERSION = '2';

	/** Option storing the installed schema version. */
	const SCHEMA_OPTION = 'yazan_audit_schema_version';

	/**
	 * Fully-qualified table name (with the site prefix).
	 *
	 * @return string
	 */
	public static function table() {
		return Yazan_DB::table( 'audit_log' );
	}

	/**
	 * Create/upgrade the table. Idempotent — safe to call on every install run.
	 * Called from {@see Yazan_Core_Installer::install()}.
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

		/*
		 * dbDelta is picky: two spaces after PRIMARY KEY, lowercase types, KEY not INDEX.
		 *
		 * v2 added `user_agent` plus the user_id and action indexes. dbDelta ADDs a missing column
		 * to an existing table and never drops one — but it also emits CHANGE COLUMN whenever it
		 * thinks a type differs, so the pre-existing lines below must not be reformatted.
		 */
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(50) NOT NULL DEFAULT '',
			object_type varchar(20) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			changes longtext NULL,
			ip varchar(45) NOT NULL DEFAULT '',
			user_agent varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY object (object_type,object_id),
			KEY user_id (user_id),
			KEY action (action)
		) {$collate};";

		dbDelta( $sql );
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Record an event. Never throws; logging must never break the primary action.
	 *
	 * @param string $action      e.g. 'product.create', 'product.update', 'media.upload', 'auth.login'.
	 * @param string $object_type e.g. 'product', 'media', 'order', 'auth'.
	 * @param int    $object_id   Related object id (0 when none).
	 * @param array  $changes     Optional diff/context; JSON-encoded after shallow sanitising.
	 * @return void
	 */
	public static function log( $action, $object_type, $object_id = 0, array $changes = array() ) {
		global $wpdb;

		$payload = '';
		if ( ! empty( $changes ) ) {
			$encoded = wp_json_encode( self::sanitize_changes( $changes ) );
			$payload = is_string( $encoded ) ? $encoded : '';
		}

		/*
		 * $wpdb->insert parameterises every value — no manual escaping needed. The $format array
		 * below is POSITIONAL: it must stay in lock-step with the data array above it, or values
		 * are bound with the wrong types and no error is raised. Keep them adjacent.
		 */
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			self::table(),
			array(
				'user_id'     => get_current_user_id(),
				// sanitize_key() would strip the dot in "product.create" and leave "productcreate",
				// so keep dots/hyphens while still allowing nothing else.
				'action'      => substr( self::clean_action( $action ), 0, 50 ),
				'object_type' => substr( sanitize_key( $object_type ), 0, 20 ),
				'object_id'   => absint( $object_id ),
				'changes'     => $payload,
				'ip'          => self::client_ip(),
				'user_agent'  => self::client_user_agent(),
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Read recent entries (newest first).
	 *
	 * @param int $limit  Rows to return (1–200).
	 * @param int $offset Offset.
	 * @return array<int,object>
	 */
	public static function recent( $limit = 50, $offset = 0 ) {
		$result = self::query( array( 'per_page' => $limit, 'page' => 1 + (int) floor( $offset / max( 1, $limit ) ) ) );
		return $result['items'];
	}

	/**
	 * Filtered, paginated query for the activity-log viewer.
	 *
	 * Filters are assembled as prepared fragments — every value goes through $wpdb->prepare(),
	 * and the only interpolated part is the table name built from the trusted prefix.
	 *
	 * @param array $args search, action, object_type, user_id, date_from, date_to, page, per_page.
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

		if ( ! empty( $args['action'] ) ) {
			$where[] = 'action = %s';
			/*
			 * clean_action(), NOT sanitize_key() — sanitize_key strips the dot, so filtering for
			 * "product.create" searched for "productcreate" and matched nothing. Every action name
			 * in this table is dotted, so the filter silently returned an empty set for all of them.
			 */
			$params[] = self::clean_action( $args['action'] );
		}
		if ( ! empty( $args['object_type'] ) ) {
			$where[]  = 'object_type = %s';
			$params[] = sanitize_key( $args['object_type'] );
		}
		if ( ! empty( $args['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = absint( $args['user_id'] );
		}
		if ( ! empty( $args['object_id'] ) ) {
			$where[]  = 'object_id = %d';
			$params[] = absint( $args['object_id'] );
		}
		if ( ! empty( $args['date_from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = sanitize_text_field( $args['date_from'] ) . ' 00:00:00';
		}
		if ( ! empty( $args['date_to'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = sanitize_text_field( $args['date_to'] ) . ' 23:59:59';
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$where[]  = '(action LIKE %s OR changes LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		$clause = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$clause}";
		$total     = (int) ( $params
			? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) )
			: $wpdb->get_var( $count_sql ) );

		$rows_sql  = "SELECT * FROM {$table} WHERE {$clause} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows      = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $params, array( $per_page, $offset ) ) ) );
		// phpcs:enable

		return array(
			'items'       => is_array( $rows ) ? $rows : array(),
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Distinct action + object_type values present in the log, for the filter dropdowns.
	 *
	 * @return array{actions:string[],object_types:string[]}
	 */
	public static function facets() {
		global $wpdb;
		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$actions = $wpdb->get_col( "SELECT DISTINCT action FROM {$table} ORDER BY action ASC" );
		$types   = $wpdb->get_col( "SELECT DISTINCT object_type FROM {$table} ORDER BY object_type ASC" );
		// phpcs:enable

		return array(
			'actions'      => is_array( $actions ) ? $actions : array(),
			'object_types' => is_array( $types ) ? $types : array(),
		);
	}

	/**
	 * Delete entries older than N days. Returns the number of rows removed.
	 *
	 * @param int $days Age in days (minimum 1).
	 * @return int
	 */
	public static function purge_older_than( $days ) {
		global $wpdb;
		$days  = max( 1, absint( $days ) );
		$table = self::table();

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Normalise an action name to lowercase [a-z0-9_.-], preserving the dotted namespace
	 * (e.g. "order.refund") that makes the log readable.
	 *
	 * @param string $action Raw action.
	 * @return string
	 */
	private static function clean_action( $action ) {
		$action = strtolower( (string) $action );
		return (string) preg_replace( '/[^a-z0-9_.\-]/', '', $action );
	}

	/**
	 * Shallow-sanitise a changes array to primitives so the JSON stays small and safe.
	 *
	 * @param array $changes Raw context.
	 * @return array
	 */
	private static function sanitize_changes( array $changes ) {
		$out = array();
		foreach ( $changes as $k => $v ) {
			$key = sanitize_key( (string) $k );
			if ( is_scalar( $v ) || null === $v ) {
				$out[ $key ] = is_string( $v ) ? sanitize_text_field( $v ) : $v;
			} elseif ( is_array( $v ) ) {
				$out[ $key ] = array_map(
					static function ( $item ) {
						return is_scalar( $item ) ? ( is_string( $item ) ? sanitize_text_field( $item ) : $item ) : '';
					},
					$v
				);
			}
		}
		return $out;
	}

	/**
	 * Best-effort client IP for the audit record. Proxy headers are NOT trusted (spoofable); only the
	 * direct connection address is recorded.
	 *
	 * @return string
	 */
	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Browser/user-agent string, truncated to the column width.
	 *
	 * Recorded so "who did this" can distinguish a session on the shop laptop from one on someone's
	 * phone. It is a fingerprint, so Yazan_Core_Privacy blanks it when a user is deleted.
	 *
	 * @return string
	 */
	private static function client_user_agent() {
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		return substr( $agent, 0, 255 );
	}
}
