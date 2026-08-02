<?php
/**
 * Yazan RBAC — database schema.
 *
 * Four tables: roles, the permission catalog, and two many-to-many pivots (role↔permission,
 * user↔role). They are the source of truth for who may do what inside the dashboard; native
 * WordPress capabilities are derived from them at runtime by {@see Yazan_Cap_Projection}.
 *
 * The role↔permission pivot stores the permission SLUG rather than a foreign key to
 * `yazan_permissions.id`, on purpose:
 *
 *   - Ids are assigned per database, so an id-keyed pivot cannot be moved between local, staging
 *     and production (or restored from a backup taken elsewhere) without remapping every row.
 *   - The catalog is defined in code ({@see Yazan_Permission_Registry}), and code speaks slugs.
 *     Resolving a user's permissions with slugs is one indexed SELECT with no JOIN, returning
 *     exactly the shape can() compares against.
 *   - The catalog table is rewritten on every registry version bump. If a row were ever deleted
 *     and re-inserted its id would change, and every grant pointing at it would silently move or
 *     evaporate. A slug grant survives; at worst it becomes inert.
 *   - Grants to `planned` permissions (modules that do not exist yet) are just strings, so they
 *     start working the day the module ships with no data migration.
 *
 * The accepted trade-off is no referential integrity, so nothing ever DELETEs from the catalog —
 * retired permissions are marked, and unknown slugs are reported rather than pruned.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades the RBAC tables.
 */
class Yazan_RBAC_Schema {

	/** Bump when the DDL below changes (drives re-install via dbDelta). */
	const SCHEMA_VERSION = '1';

	/** Option storing the installed schema version. */
	const SCHEMA_OPTION = 'yazan_rbac_schema_version';

	/**
	 * Roles table name.
	 *
	 * @return string
	 */
	public static function roles_table() {
		global $wpdb;
		return Yazan_DB::table( 'roles' );
	}

	/**
	 * Permission catalog table name.
	 *
	 * @return string
	 */
	public static function permissions_table() {
		global $wpdb;
		return Yazan_DB::table( 'permissions' );
	}

	/**
	 * role ↔ permission pivot table name.
	 *
	 * @return string
	 */
	public static function role_permissions_table() {
		global $wpdb;
		return Yazan_DB::table( 'role_permissions' );
	}

	/**
	 * user ↔ role pivot table name.
	 *
	 * @return string
	 */
	public static function user_roles_table() {
		global $wpdb;
		return Yazan_DB::table( 'user_roles' );
	}

	/**
	 * Every table this subsystem owns.
	 *
	 * @return string[]
	 */
	public static function tables() {
		return array(
			self::roles_table(),
			self::permissions_table(),
			self::role_permissions_table(),
			self::user_roles_table(),
		);
	}

	/**
	 * Are the tables present?
	 *
	 * Memoised for the request because this is called from the `user_has_cap` filter, which fires
	 * many times per page load. A single SHOW TABLES per request is the price of making the whole
	 * subsystem a safe no-op before it is installed.
	 *
	 * @param bool $force Re-check instead of using the memo (used right after install()).
	 * @return bool
	 */
	public static function is_installed( $force = false ) {
		static $installed = null;

		if ( ! $force && null !== $installed ) {
			return $installed;
		}

		global $wpdb;

		$found = 0;
		foreach ( self::tables() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $exists === $table ) {
				++$found;
			}
		}

		$installed = ( count( self::tables() ) === $found );
		return $installed;
	}

	/**
	 * Create/upgrade the tables. Idempotent — safe to call on every load.
	 *
	 * @return bool True when the schema is present afterwards.
	 */
	public static function install() {
		if ( get_option( self::SCHEMA_OPTION ) === self::SCHEMA_VERSION && self::is_installed() ) {
			return true;
		}

		global $wpdb;
		$collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$roles            = self::roles_table();
		$permissions      = self::permissions_table();
		$role_permissions = self::role_permissions_table();
		$user_roles       = self::user_roles_table();

		/*
		 * dbDelta is picky: two spaces after PRIMARY KEY, lowercase types, KEY not INDEX, and one
		 * definition per line. Composite primary keys on the pivots are fine — varchar(64) utf8mb4
		 * plus a bigint is 264 bytes, well inside InnoDB's 3072-byte index limit.
		 */
		$sql = array();

		$sql[] = "CREATE TABLE {$roles} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(64) NOT NULL DEFAULT '',
			name varchar(120) NOT NULL DEFAULT '',
			description varchar(255) NOT NULL DEFAULT '',
			is_super tinyint(1) NOT NULL DEFAULT 0,
			is_system tinyint(1) NOT NULL DEFAULT 0,
			sort int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug)
		) {$collate};";

		$sql[] = "CREATE TABLE {$permissions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(64) NOT NULL DEFAULT '',
			module varchar(40) NOT NULL DEFAULT '',
			action varchar(40) NOT NULL DEFAULT '',
			label varchar(160) NOT NULL DEFAULT '',
			description varchar(255) NOT NULL DEFAULT '',
			status varchar(12) NOT NULL DEFAULT 'active',
			sort int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY module (module)
		) {$collate};";

		$sql[] = "CREATE TABLE {$role_permissions} (
			role_id bigint(20) unsigned NOT NULL DEFAULT 0,
			permission_slug varchar(64) NOT NULL DEFAULT '',
			PRIMARY KEY  (role_id,permission_slug),
			KEY permission_slug (permission_slug)
		) {$collate};";

		$sql[] = "CREATE TABLE {$user_roles} (
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			role_id bigint(20) unsigned NOT NULL DEFAULT 0,
			assigned_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (user_id,role_id),
			KEY role_id (role_id)
		) {$collate};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		$ok = self::is_installed( true );
		if ( $ok ) {
			update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
		}

		return $ok;
	}
}
