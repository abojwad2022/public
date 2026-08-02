<?php
/**
 * User-data lifecycle for the two yazan-core tables.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * yazan-core owns two user-linked tables:
 *
 *   {prefix}yazan_audit_log      — who changed what in the dashboard, plus their IP.
 *   {prefix}yazan_ai_generations — who ran which AI task, and what it cost.
 *
 * Neither had any cleanup when a WordPress user was deleted, so both accumulated
 * rows pointing at users that no longer exist.
 *
 * Both are ANONYMISED rather than deleted:
 *
 *   - The audit log is an accountability record. Deleting a user must not erase
 *     the trail of what that account did — otherwise deleting an account becomes
 *     a way to cover tracks. The identifying columns (user_id, ip) are cleared
 *     and the action history is kept.
 *   - AI generations carry token counts and USD cost. Deleting them would
 *     silently rewrite historical spend totals.
 */
class Yazan_Core_Privacy {

	/** Value written into user columns when anonymising. */
	const ANONYMOUS_USER_ID = 0;

	/** Identifier WordPress uses for our exporter/eraser. */
	const SLUG = 'yazan-core';

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'deleted_user', array( __CLASS__, 'on_user_deleted' ), 10, 1 );
		add_action( 'remove_user_from_blog', array( __CLASS__, 'on_user_removed_from_blog' ), 10, 2 );

		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	/**
	 * The tables we own, and the columns to blank on each.
	 *
	 * @return array<string,array{user_column:string,pii:array<int,string>,label:string}>
	 */
	private static function tables() {
		return array(
			Yazan_DB::table( 'audit_log' )      => array(
				'user_column' => 'user_id',
				// The user agent is a browser fingerprint, so it is PII and must be blanked too.
				'pii'         => array( 'ip', 'user_agent' ),
				'label'       => __( 'Dashboard audit log', 'yazan' ),
			),
			Yazan_DB::table( 'ai_generations' ) => array(
				'user_column' => 'user_id',
				'pii'         => array(),
				'label'       => __( 'AI generation history', 'yazan' ),
			),
			/*
			 * Role assignments are DELETED rather than anonymised. Reassigning a grant to user 0
			 * would leave a phantom row that the "who is a super admin" count could pick up, and
			 * unlike the audit log there is no accountability value in keeping it — the audit log
			 * already records who was granted what, and when.
			 */
			Yazan_DB::table( 'user_roles' )     => array(
				'user_column' => 'user_id',
				'pii'         => array(),
				'mode'        => 'delete',
				'label'       => __( 'Dashboard role assignments', 'yazan' ),
			),
		);
	}

	/**
	 * Handle `deleted_user`.
	 *
	 * @param int $user_id Deleted user id.
	 * @return void
	 */
	public static function on_user_deleted( $user_id ) {
		self::anonymize( (int) $user_id );
	}

	/**
	 * Handle `remove_user_from_blog`.
	 *
	 * @param int $user_id User id.
	 * @param int $blog_id Site id.
	 * @return void
	 */
	public static function on_user_removed_from_blog( $user_id, $blog_id ) {
		if ( (int) $blog_id !== get_current_blog_id() ) {
			return;
		}
		self::anonymize( (int) $user_id );
	}

	/**
	 * Strip the user link (and any PII) from this user's rows.
	 *
	 * @param int $user_id User id.
	 * @return array<string,int> table => rows affected.
	 */
	public static function anonymize( $user_id ) {
		global $wpdb;

		$user_id  = (int) $user_id;
		$affected = array();

		if ( $user_id <= 0 ) {
			return $affected;
		}

		foreach ( self::tables() as $table => $spec ) {
			if ( ! self::table_exists( $table ) ) {
				continue;
			}

			/*
			 * Most tables are anonymised so the history survives. A table marked 'delete' holds
			 * rows whose only meaning is the user link itself — keeping a de-linked copy would be
			 * a phantom record rather than a preserved fact.
			 */
			if ( 'delete' === ( $spec['mode'] ?? 'anonymize' ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->delete( $table, array( $spec['user_column'] => $user_id ), array( '%d' ) );

				if ( $rows > 0 ) {
					$affected[ $table ] = (int) $rows;
				}
				continue;
			}

			$data    = array( $spec['user_column'] => self::ANONYMOUS_USER_ID );
			$formats = array( '%d' );

			foreach ( $spec['pii'] as $column ) {
				$data[ $column ] = '';
				$formats[]       = '%s';
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->update(
				$table,
				$data,
				array( $spec['user_column'] => $user_id ),
				$formats,
				array( '%d' )
			);

			if ( $rows > 0 ) {
				$affected[ $table ] = (int) $rows;
			}
		}

		return $affected;
	}

	/**
	 * Count rows whose user no longer exists. Read-only.
	 *
	 * @return array<string,int> table => orphan count.
	 */
	public static function scan_orphans() {
		global $wpdb;

		$out = array();

		foreach ( self::tables() as $table => $spec ) {
			if ( ! self::table_exists( $table ) ) {
				continue;
			}

			$column = $spec['user_column'];

			// Identifiers are internal constants; no request data reaches this SQL.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM `{$table}` a
				 LEFT JOIN `{$wpdb->users}` u ON u.ID = a.`{$column}`
				 WHERE u.ID IS NULL AND a.`{$column}` <> 0"
			);

			if ( $count > 0 ) {
				$out[ $table ] = $count;
			}
		}

		return $out;
	}

	/**
	 * Register the GDPR exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters                = (array) $exporters;
		$exporters[ self::SLUG ] = array(
			'exporter_friendly_name' => __( 'Yazan Core', 'yazan' ),
			'callback'               => array( __CLASS__, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Register the GDPR eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers                = (array) $erasers;
		$erasers[ self::SLUG ] = array(
			'eraser_friendly_name' => __( 'Yazan Core', 'yazan' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Export this user's audit + AI rows.
	 *
	 * @param string $email_address Subject email.
	 * @param int    $page          Page number.
	 * @return array
	 */
	public static function export( $email_address, $page = 1 ) {
		global $wpdb;

		$user = get_user_by( 'email', (string) $email_address );
		$data = array();

		if ( ! $user instanceof WP_User ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		foreach ( self::tables() as $table => $spec ) {
			if ( ! self::table_exists( $table ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$table}` WHERE `{$spec['user_column']}` = %d LIMIT 200",
					(int) $user->ID
				),
				ARRAY_A
			);

			foreach ( (array) $rows as $i => $row ) {
				$fields = array();

				foreach ( $row as $column => $value ) {
					$fields[] = array(
						'name'  => ucwords( str_replace( '_', ' ', (string) $column ) ),
						'value' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
					);
				}

				$data[] = array(
					'group_id'    => self::SLUG . '-' . $table,
					'group_label' => $spec['label'],
					'item_id'     => $table . '-' . ( $i + 1 ),
					'data'        => $fields,
				);
			}
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Anonymise on erasure request.
	 *
	 * @param string $email_address Subject email.
	 * @param int    $page          Page number.
	 * @return array
	 */
	public static function erase( $email_address, $page = 1 ) {
		$user = get_user_by( 'email', (string) $email_address );

		if ( ! $user instanceof WP_User ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$affected = self::anonymize( (int) $user->ID );

		// The two modes report differently: anonymised rows are retained, deleted ones are removed.
		$retained = false;
		$removed  = false;
		$specs    = self::tables();

		foreach ( array_keys( $affected ) as $table ) {
			if ( 'delete' === ( $specs[ $table ]['mode'] ?? 'anonymize' ) ) {
				$removed = true;
			} else {
				$retained = true;
			}
		}

		$messages = array();
		if ( $retained ) {
			$messages[] = __( 'Dashboard audit entries and AI generation history were anonymised rather than deleted: the audit trail is an accountability record and the AI rows carry billing totals. Neither identifies this person any more.', 'yazan' );
		}
		if ( $removed ) {
			$messages[] = __( 'Dashboard role assignments for this account were deleted outright.', 'yazan' );
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Does a table exist?
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;

		static $cache = array();

		if ( isset( $cache[ $table ] ) ) {
			return $cache[ $table ];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		$cache[ $table ] = ( null !== $found );

		return $cache[ $table ];
	}
}
