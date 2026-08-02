<?php
/**
 * Yazan RBAC — roles and their grants.
 *
 * Roles live in `yazan_roles`; their permissions in the `yazan_role_permissions` pivot; who holds
 * them in the `yazan_user_roles` pivot. Both pivots are many-to-many: a role has unlimited
 * permissions and a user may hold several roles (their effective set is the union).
 *
 * Every write bumps the permission cache version, so a grant change is visible on the very next
 * request for every affected user without hunting down individual cache entries.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Role CRUD, grants, assignment, and the default role set.
 */
class Yazan_Roles {

	/** Named MySQL lock held across check-then-write sequences (last-super-admin, full replaces). */
	const LOCK = 'yazan_rbac_write';

	/** Seconds to wait for the lock before giving up. */
	const LOCK_TIMEOUT = 5;

	/** Slug of the seeded super-admin role. */
	const SUPER_SLUG = 'super-admin';

	/**
	 * Acquire the RBAC write lock.
	 *
	 * A named MySQL lock rather than a transaction: the sequences we protect read, decide, then
	 * write across several tables, and the only thing we actually need is that two operators
	 * cannot both pass the "another super admin still exists" check at the same moment.
	 *
	 * @return bool
	 */
	/**
	 * The store a membership operation applies to.
	 *
	 * Mirrors Yazan_Permissions::store() including the literal fallback: a missing mu-plugin must
	 * degrade to "the only store", never fatal.
	 *
	 * @param int|null $store_id Explicit store, or null for the active one.
	 * @return int
	 */
	private static function store( $store_id = null ) {
		if ( null !== $store_id ) {
			return absint( $store_id );
		}

		return class_exists( 'Yazan_Store_Context' ) ? Yazan_Store_Context::current() : 1;
	}

	public static function lock() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::LOCK, self::LOCK_TIMEOUT ) );
	}

	/**
	 * Release the RBAC write lock.
	 *
	 * @return void
	 */
	public static function unlock() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK ) );
	}

	/* --------------------------------------------------------------------- */
	/* Read                                                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * Every role, ordered for display.
	 *
	 * @param bool $with_counts Include the number of users holding each role.
	 * @return array<int,array>
	 */
	public static function all( $with_counts = false ) {
		if ( ! Yazan_RBAC_Schema::is_installed() ) {
			return array();
		}

		global $wpdb;
		$roles = Yazan_RBAC_Schema::roles_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$roles} ORDER BY sort ASC, name ASC" );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$counts = $with_counts ? self::member_counts() : array();
		$grants = self::grants_by_role();

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = self::shape( $row, $grants[ (int) $row->id ] ?? array(), $with_counts ? ( $counts[ (int) $row->id ] ?? 0 ) : null );
		}

		return $out;
	}

	/**
	 * One role by id.
	 *
	 * @param int $id Role id.
	 * @return array|null
	 */
	public static function get( $id ) {
		if ( ! Yazan_RBAC_Schema::is_installed() ) {
			return null;
		}

		global $wpdb;
		$roles = Yazan_RBAC_Schema::roles_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$roles} WHERE id = %d", absint( $id ) ) );
		if ( ! $row ) {
			return null;
		}

		$counts = self::member_counts();
		return self::shape( $row, self::permissions( (int) $row->id ), $counts[ (int) $row->id ] ?? 0 );
	}

	/**
	 * One role by slug.
	 *
	 * @param string $slug Role slug.
	 * @return array|null
	 */
	public static function get_by_slug( $slug ) {
		if ( ! Yazan_RBAC_Schema::is_installed() ) {
			return null;
		}

		global $wpdb;
		$roles = Yazan_RBAC_Schema::roles_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$roles} WHERE slug = %s", sanitize_key( $slug ) ) );

		return $row ? self::shape( $row, self::permissions( (int) $row->id ), null ) : null;
	}

	/**
	 * The permission slugs granted to a role.
	 *
	 * @param int $role_id Role id.
	 * @return string[]
	 */
	public static function permissions( $role_id ) {
		if ( ! Yazan_RBAC_Schema::is_installed() ) {
			return array();
		}

		global $wpdb;
		$grants = Yazan_RBAC_Schema::role_permissions_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT permission_slug FROM {$grants} WHERE role_id = %d", absint( $role_id ) ) );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * All grants, keyed by role id — one query instead of one per role.
	 *
	 * @return array<int,string[]>
	 */
	private static function grants_by_role() {
		global $wpdb;
		$grants = Yazan_RBAC_Schema::role_permissions_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT role_id, permission_slug FROM {$grants}" );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->role_id ][] = $row->permission_slug;
		}
		return $out;
	}

	/**
	 * How many users hold each role.
	 *
	 * @return array<int,int>
	 */
	public static function member_counts( $store_id = null ) {
		if ( ! Yazan_RBAC_Schema::is_installed() ) {
			return array();
		}

		global $wpdb;
		$pivot = Yazan_RBAC_Schema::user_roles_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT role_id, COUNT(*) AS total FROM {$pivot} WHERE store_id = %d GROUP BY role_id", self::store( $store_id ) )
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->role_id ] = (int) $row->total;
		}
		return $out;
	}

	/**
	 * Public shape of a role row.
	 *
	 * @param object        $row         Database row.
	 * @param string[]      $permissions Granted slugs.
	 * @param int|null      $members     Member count, or null when not requested.
	 * @return array
	 */
	private static function shape( $row, array $permissions, $members = null ) {
		$data = array(
			'id'          => (int) $row->id,
			'slug'        => (string) $row->slug,
			'name'        => (string) $row->name,
			'description' => (string) $row->description,
			'is_super'    => (bool) (int) $row->is_super,
			'is_system'   => (bool) (int) $row->is_system,
			'sort'        => (int) $row->sort,
			'created_at'  => (string) $row->created_at,
			'updated_at'  => (string) $row->updated_at,
			'permissions' => array_values( $permissions ),
		);

		if ( null !== $members ) {
			$data['members'] = (int) $members;
		}

		return $data;
	}

	/* --------------------------------------------------------------------- */
	/* Write                                                                  */
	/* --------------------------------------------------------------------- */

	/**
	 * Create a role.
	 *
	 * @param array $data name, slug, description, is_super, is_system, sort, permissions.
	 * @return int|WP_Error New role id.
	 */
	public static function create( array $data ) {
		if ( ! Yazan_RBAC_Schema::is_installed() ) {
			return new WP_Error( 'yazan_rbac_missing', __( 'The roles system is not installed yet.', 'yazan' ), array( 'status' => 503 ) );
		}

		$name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			return new WP_Error( 'yazan_role_name', __( 'A role needs a name.', 'yazan' ), array( 'status' => 400 ) );
		}

		$slug = sanitize_key( (string) ( $data['slug'] ?? '' ) );
		if ( '' === $slug ) {
			$slug = sanitize_title( $name );
		}
		$slug = self::unique_slug( $slug );

		global $wpdb;
		$now = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->insert(
			Yazan_RBAC_Schema::roles_table(),
			array(
				'slug'        => $slug,
				'name'        => $name,
				'description' => sanitize_text_field( (string) ( $data['description'] ?? '' ) ),
				'is_super'    => empty( $data['is_super'] ) ? 0 : 1,
				'is_system'   => empty( $data['is_system'] ) ? 0 : 1,
				'sort'        => absint( $data['sort'] ?? 100 ),
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
		);

		if ( ! $ok ) {
			return new WP_Error( 'yazan_role_insert', __( 'Could not create the role.', 'yazan' ), array( 'status' => 500 ) );
		}

		$role_id = (int) $wpdb->insert_id;

		if ( isset( $data['permissions'] ) && is_array( $data['permissions'] ) ) {
			self::set_permissions( $role_id, $data['permissions'] );
		}

		Yazan_Permissions::bump();

		return $role_id;
	}

	/**
	 * Update a role's metadata (not its grants — use set_permissions()).
	 *
	 * @param int   $role_id Role id.
	 * @param array $data    name, description, sort, is_super.
	 * @return true|WP_Error
	 */
	public static function update( $role_id, array $data ) {
		$role = self::get( $role_id );
		if ( ! $role ) {
			return new WP_Error( 'yazan_role_missing', __( 'Role not found.', 'yazan' ), array( 'status' => 404 ) );
		}

		$fields  = array();
		$formats = array();

		if ( isset( $data['name'] ) ) {
			$name = sanitize_text_field( (string) $data['name'] );
			if ( '' === $name ) {
				return new WP_Error( 'yazan_role_name', __( 'A role needs a name.', 'yazan' ), array( 'status' => 400 ) );
			}
			$fields['name'] = $name;
			$formats[]      = '%s';
		}
		if ( isset( $data['description'] ) ) {
			$fields['description'] = sanitize_text_field( (string) $data['description'] );
			$formats[]             = '%s';
		}
		if ( isset( $data['sort'] ) ) {
			$fields['sort'] = absint( $data['sort'] );
			$formats[]      = '%d';
		}
		if ( isset( $data['is_super'] ) ) {
			$fields['is_super'] = empty( $data['is_super'] ) ? 0 : 1;
			$formats[]          = '%d';
		}

		if ( ! $fields ) {
			return true;
		}

		$fields['updated_at'] = current_time( 'mysql' );
		$formats[]            = '%s';

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( Yazan_RBAC_Schema::roles_table(), $fields, array( 'id' => (int) $role_id ), $formats, array( '%d' ) );

		Yazan_Permissions::bump();

		return true;
	}

	/**
	 * Replace a role's grants wholesale and report the diff.
	 *
	 * Full replace rather than add/remove deltas: the Role Editor sends the complete tick state, so
	 * a delta protocol would silently diverge the moment a checkbox failed to register.
	 *
	 * @param int      $role_id Role id.
	 * @param string[] $slugs   Permission slugs to grant.
	 * @return array{added:string[],removed:string[]}|WP_Error
	 */
	public static function set_permissions( $role_id, array $slugs ) {
		if ( ! Yazan_RBAC_Schema::is_installed() ) {
			return new WP_Error( 'yazan_rbac_missing', __( 'The roles system is not installed yet.', 'yazan' ), array( 'status' => 503 ) );
		}

		$role_id = absint( $role_id );

		// Only slugs the catalog actually declares. Unknown input is dropped, never stored.
		$wanted = array();
		foreach ( $slugs as $slug ) {
			$slug = is_string( $slug ) ? trim( $slug ) : '';
			if ( '' !== $slug && Yazan_Permission_Registry::exists( $slug ) ) {
				$wanted[ $slug ] = true;
			}
		}
		$wanted  = array_keys( $wanted );
		$current = self::permissions( $role_id );

		$added   = array_values( array_diff( $wanted, $current ) );
		$removed = array_values( array_diff( $current, $wanted ) );

		if ( ! $added && ! $removed ) {
			return array(
				'added'   => array(),
				'removed' => array(),
			);
		}

		global $wpdb;
		$grants = Yazan_RBAC_Schema::role_permissions_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $grants, array( 'role_id' => $role_id ), array( '%d' ) );

		foreach ( $wanted as $slug ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$grants,
				array(
					'role_id'         => $role_id,
					'permission_slug' => $slug,
				),
				array( '%d', '%s' )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Yazan_RBAC_Schema::roles_table(),
			array( 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $role_id ),
			array( '%s' ),
			array( '%d' )
		);

		Yazan_Permissions::bump();

		return array(
			'added'   => $added,
			'removed' => $removed,
		);
	}

	/**
	 * Delete a role, optionally moving its members to another one.
	 *
	 * @param int $role_id     Role id.
	 * @param int $reassign_to Role id to move members to (0 = leave them role-less).
	 * @return true|WP_Error
	 */
	public static function delete( $role_id, $reassign_to = 0 ) {
		$role = self::get( $role_id );
		if ( ! $role ) {
			return new WP_Error( 'yazan_role_missing', __( 'Role not found.', 'yazan' ), array( 'status' => 404 ) );
		}
		if ( $role['is_system'] ) {
			return new WP_Error( 'yazan_role_system', __( 'Built-in roles cannot be deleted. Edit their permissions instead.', 'yazan' ), array( 'status' => 409 ) );
		}

		global $wpdb;
		$role_id     = (int) $role_id;
		$reassign_to = absint( $reassign_to );
		$pivot       = Yazan_RBAC_Schema::user_roles_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		/*
		 * Roles are global, so deleting one removes its memberships in every store. The reassignment
		 * below therefore has to remember WHICH store each membership was in — reassigning them all
		 * into the active store would quietly move staff between tenants.
		 */
		$members = $wpdb->get_results(
			$wpdb->prepare( "SELECT user_id, store_id FROM {$pivot} WHERE role_id = %d", $role_id ),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $pivot, array( 'role_id' => $role_id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Yazan_RBAC_Schema::role_permissions_table(), array( 'role_id' => $role_id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Yazan_RBAC_Schema::roles_table(), array( 'id' => $role_id ), array( '%d' ) );

		if ( $reassign_to && self::get( $reassign_to ) ) {
			foreach ( (array) $members as $member ) {
				// Each membership is restored into the store it came from, not the active one.
				self::assign( (int) $member['user_id'], $reassign_to, (int) $member['store_id'] );
			}
		}

		Yazan_Permissions::bump();

		return true;
	}

	/* --------------------------------------------------------------------- */
	/* Assignment                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * Role ids held by a user.
	 *
	 * @param int $user_id User id.
	 * @return int[]
	 */
	public static function for_user( $user_id, $store_id = null ) {
		if ( ! Yazan_RBAC_Schema::is_installed() ) {
			return array();
		}

		global $wpdb;
		$pivot = Yazan_RBAC_Schema::user_roles_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT role_id FROM {$pivot} WHERE user_id = %d AND store_id = %d",
				absint( $user_id ),
				self::store( $store_id )
			)
		);

		return array_map( 'intval', (array) $rows );
	}

	/**
	 * Grant one role to a user (idempotent).
	 *
	 * @param int $user_id User id.
	 * @param int $role_id Role id.
	 * @return void
	 */
	public static function assign( $user_id, $role_id, $store_id = null ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				// IGNORE keeps re-assignment a no-op rather than a primary-key error.
				'INSERT IGNORE INTO ' . Yazan_RBAC_Schema::user_roles_table() . ' (user_id, role_id, store_id, assigned_at) VALUES (%d, %d, %d, %s)',
				absint( $user_id ),
				absint( $role_id ),
				self::store( $store_id ),
				current_time( 'mysql' )
			)
		);

		Yazan_Permissions::bump();
	}

	/**
	 * Replace a user's roles wholesale.
	 *
	 * @param int   $user_id  User id.
	 * @param int[] $role_ids Role ids.
	 * @return array{added:int[],removed:int[]}
	 */
	public static function set_user_roles( $user_id, array $role_ids, $store_id = null ) {
		$user_id = absint( $user_id );

		$valid = array();
		foreach ( $role_ids as $role_id ) {
			$role_id = absint( $role_id );
			if ( $role_id && self::get( $role_id ) ) {
				$valid[ $role_id ] = true;
			}
		}
		$valid   = array_keys( $valid );
		$store   = self::store( $store_id );
		$current = self::for_user( $user_id, $store );

		$added   = array_values( array_diff( $valid, $current ) );
		$removed = array_values( array_diff( $current, $valid ) );

		global $wpdb;
		$pivot = Yazan_RBAC_Schema::user_roles_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		/*
		 * 🔴 THE STORE PREDICATE HERE IS LOAD-BEARING.
		 *
		 * Without it this DELETE removes every role the user holds ANYWHERE, so assigning roles in
		 * store B silently strips their access to store A. The insert loop below then only restores
		 * the store-B rows, and the store-A grants are gone with no error and no audit trail of a
		 * removal that nobody asked for.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $pivot, array( 'user_id' => $user_id, 'store_id' => $store ), array( '%d', '%d' ) );

		$now = current_time( 'mysql' );
		foreach ( $valid as $role_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$pivot,
				array(
					'user_id'     => $user_id,
					'role_id'     => $role_id,
					'store_id'    => $store,
					'assigned_at' => $now,
				),
				array( '%d', '%d', '%d', '%s' )
			);
		}

		Yazan_Permissions::bump();

		return array(
			'added'   => $added,
			'removed' => $removed,
		);
	}

	/**
	 * Drop every role row for a user. Called on `deleted_user` so WP-CLI and wp-admin deletions
	 * leave no phantom grants behind.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function purge_user( $user_id ) {
		if ( ! Yazan_RBAC_Schema::is_installed() ) {
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Yazan_RBAC_Schema::user_roles_table(), array( 'user_id' => absint( $user_id ) ), array( '%d' ) );

		Yazan_Permissions::bump();
	}

	/**
	 * User ids holding at least one Yazan role — the definition of "staff".
	 *
	 * @return int[]
	 */
	public static function staff_ids( $store_id = null ) {
		if ( ! Yazan_RBAC_Schema::is_installed() ) {
			return array();
		}

		global $wpdb;
		$pivot = Yazan_RBAC_Schema::user_roles_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col(
			$wpdb->prepare( "SELECT DISTINCT user_id FROM {$pivot} WHERE store_id = %d", self::store( $store_id ) )
		);

		return array_map( 'intval', (array) $rows );
	}

	/**
	 * User ids holding a specific role.
	 *
	 * @param int $role_id Role id.
	 * @return int[]
	 */
	public static function user_ids_in_role( $role_id, $store_id = null ) {
		if ( ! Yazan_RBAC_Schema::is_installed() ) {
			return array();
		}

		global $wpdb;
		$pivot = Yazan_RBAC_Schema::user_roles_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$pivot} WHERE role_id = %d AND store_id = %d",
				absint( $role_id ),
				self::store( $store_id )
			)
		);

		return array_map( 'intval', (array) $rows );
	}

	/* --------------------------------------------------------------------- */
	/* Seeding                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Create the default role set — ONCE, and only into an empty table.
	 *
	 * Never re-seeds: an operator who deletes or re-scopes a seeded role must not have it silently
	 * reappear (or be reset) on the next deploy.
	 *
	 * @return int Number of roles created.
	 */
	public static function seed_defaults() {
		if ( ! Yazan_RBAC_Schema::is_installed() ) {
			return 0;
		}

		global $wpdb;
		$roles = Yazan_RBAC_Schema::roles_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$roles}" );
		if ( $existing > 0 ) {
			return 0;
		}

		$created = 0;
		foreach ( self::defaults() as $definition ) {
			$id = self::create( $definition );
			if ( ! is_wp_error( $id ) ) {
				++$created;
			}
		}

		return $created;
	}

	/**
	 * The seeded roles and what each may do.
	 *
	 * Every role holds `dashboard.access` — without it the shell refuses the sign-in.
	 *
	 * @return array<int,array>
	 */
	private static function defaults() {
		$base = array( 'dashboard.access', 'dashboard.view' );

		$all_active = Yazan_Permission_Registry::active_slugs();

		// Admin is everything except the three actions that can destroy or rewrite history.
		$admin = array_values(
			array_diff(
				$all_active,
				array( 'backup.restore', 'backup.download', 'audit.purge' )
			)
		);

		return array(
			array(
				'name'        => __( 'Super Admin', 'yazan' ),
				'slug'        => self::SUPER_SLUG,
				'description' => __( 'Full, unrestricted access. Bypasses every permission check.', 'yazan' ),
				'is_super'    => true,
				'is_system'   => true,
				'sort'        => 10,
				'permissions' => $all_active,
			),
			array(
				'name'        => __( 'Admin', 'yazan' ),
				'slug'        => 'admin',
				'description' => __( 'Runs the store day to day. Cannot restore backups or purge the activity log.', 'yazan' ),
				'is_system'   => true,
				'sort'        => 20,
				'permissions' => $admin,
			),
			array(
				'name'        => __( 'Manager', 'yazan' ),
				'slug'        => 'manager',
				'description' => __( 'Catalog and sales, plus reporting. No system settings or staff administration.', 'yazan' ),
				'is_system'   => true,
				'sort'        => 30,
				'permissions' => array_merge(
					$base,
					array(
						'products.view', 'products.create', 'products.edit', 'products.delete', 'products.publish',
						'products.restore', 'products.duplicate', 'products.change_price', 'products.change_stock',
						'products.bulk', 'products.export',
						'categories.view', 'categories.create', 'categories.edit', 'categories.delete',
						'attributes.view', 'attributes.create', 'attributes.edit',
						'inventory.view', 'inventory.edit', 'inventory.export',
						'media.view', 'media.upload', 'media.delete',
						'orders.view', 'orders.create', 'orders.edit', 'orders.status', 'orders.cancel',
						'orders.refund', 'orders.notes', 'orders.items', 'orders.addresses', 'orders.coupons',
						'orders.export',
						'customers.view',
						'coupons.view', 'coupons.create', 'coupons.edit', 'coupons.delete',
						'reports.view', 'reports.export',
						'ai.use', 'ai.insights_view',
						'rewards.view', 'rewards.manage',
						'wishlist.view',
						'audit.view',
					)
				),
			),
			array(
				'name'        => __( 'Sales', 'yazan' ),
				'slug'        => 'sales',
				'description' => __( 'Takes and progresses orders. Cannot refund, discount or edit the catalog.', 'yazan' ),
				'is_system'   => true,
				'sort'        => 40,
				'permissions' => array_merge(
					$base,
					array(
						'products.view',
						'categories.view',
						'inventory.view',
						'orders.view', 'orders.create', 'orders.edit', 'orders.status',
						'orders.notes', 'orders.items', 'orders.addresses', 'orders.coupons',
						'customers.view',
						'coupons.view',
					)
				),
			),
			array(
				'name'        => __( 'Inventory', 'yazan' ),
				'slug'        => 'inventory',
				'description' => __( 'Keeps stock accurate. Can edit quantities but not prices.', 'yazan' ),
				'is_system'   => true,
				'sort'        => 50,
				'permissions' => array_merge(
					$base,
					array(
						'products.view', 'products.edit', 'products.change_stock', 'products.bulk', 'products.export',
						'categories.view',
						'attributes.view',
						'inventory.view', 'inventory.edit', 'inventory.export', 'inventory.import',
						'media.view', 'media.upload',
						'orders.view',
					)
				),
			),
			array(
				'name'        => __( 'Customer Service', 'yazan' ),
				'slug'        => 'customer-service',
				'description' => __( 'Handles enquiries, returns and refunds recorded against orders.', 'yazan' ),
				'is_system'   => true,
				'sort'        => 60,
				'permissions' => array_merge(
					$base,
					array(
						'products.view',
						'orders.view', 'orders.edit', 'orders.status', 'orders.cancel', 'orders.refund',
						'orders.notes', 'orders.addresses',
						'customers.view',
						'coupons.view',
						'reviews.view', 'reviews.reply', 'reviews.approve',
						'wishlist.view',
					)
				),
			),
			array(
				'name'        => __( 'Accountant', 'yazan' ),
				'slug'        => 'accountant',
				'description' => __( 'Reads the numbers and exports them. Changes nothing.', 'yazan' ),
				'is_system'   => true,
				'sort'        => 70,
				'permissions' => array_merge(
					$base,
					array(
						'orders.view', 'orders.export',
						'customers.view', 'customers.export',
						'coupons.view',
						'products.view',
						'reports.view', 'reports.export',
						'analytics.view', 'analytics.export',
						'settings.view',
						'tax.view',
						'shipping.view',
						'gateways.view',
						'porting.export',
						'audit.view', 'audit.export',
					)
				),
			),
			array(
				'name'        => __( 'Marketing', 'yazan' ),
				'slug'        => 'marketing',
				'description' => __( 'Owns promotions, content and campaigns.', 'yazan' ),
				'is_system'   => true,
				'sort'        => 80,
				'permissions' => array_merge(
					$base,
					array(
						'products.view', 'products.edit', 'products.publish',
						'categories.view', 'categories.edit',
						'collections.view', 'collections.create', 'collections.edit', 'collections.publish',
						'media.view', 'media.upload', 'media.delete',
						'coupons.view', 'coupons.create', 'coupons.edit', 'coupons.delete', 'coupons.export',
						'orders.view',
						'customers.view',
						'reports.view',
						'analytics.view',
						'ai.use', 'ai.insights_view',
						'rewards.view', 'rewards.manage',
						'reviews.view', 'reviews.reply', 'reviews.approve',
						'banners.view', 'banners.create', 'banners.edit', 'banners.delete', 'banners.publish',
						'notifications.view', 'notifications.create', 'notifications.edit', 'notifications.send',
					)
				),
			),
		);
	}

	/**
	 * Ensure a slug is unique by appending a numeric suffix.
	 *
	 * @param string $slug Desired slug.
	 * @return string
	 */
	private static function unique_slug( $slug ) {
		global $wpdb;
		$roles = Yazan_RBAC_Schema::roles_table();

		$candidate = $slug;
		$suffix    = 2;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		while ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$roles} WHERE slug = %s", $candidate ) ) > 0 ) {
			$candidate = $slug . '-' . $suffix;
			++$suffix;
		}
		// phpcs:enable

		return $candidate;
	}
}
