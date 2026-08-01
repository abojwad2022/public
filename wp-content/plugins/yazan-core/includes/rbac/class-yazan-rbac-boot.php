<?php
/**
 * Yazan RBAC — subsystem bootstrap.
 *
 * Loads the RBAC classes, registers their hooks, and owns the install/upgrade path. Kept as one
 * entry point so yazan-core.php gains a single require + init() call.
 *
 * MUST be initialised BEFORE Yazan_Dashboard_Boot: the dashboard's very first act on a request is
 * a capability check, and that check has to see the projected capabilities.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the RBAC data layer, the capability projection, and the installer.
 */
class Yazan_RBAC_Boot {

	/**
	 * Autoloaded install signature.
	 *
	 * One autoloaded option rather than reading three separate ones on every request: this is
	 * checked on `init` for every page load, and non-autoloaded options each cost a query on a
	 * site with no persistent object cache.
	 */
	const READY_OPTION = 'yazan_rbac_ready';

	/** Set once the existing WordPress roles have been mapped onto Yazan roles. */
	const BACKFILL_OPTION = 'yazan_rbac_backfill_done';

	/** Short lock so two concurrent first requests do not both run the installer. */
	const INSTALL_LOCK = 'yazan_rbac_installing';

	/**
	 * Require the classes and register hooks. Call once at plugin load.
	 *
	 * @return void
	 */
	public static function init() {
		$dir = YAZAN_CORE_DIR . 'includes/rbac/';

		/*
		 * RBAC writes user.* and role.* entries and owns the audit table's upgrade path, so the
		 * dependency is required explicitly rather than relying on Yazan_Dashboard_Boot — which
		 * loads AFTER this class, because the dashboard's first act is a capability check.
		 */
		require_once YAZAN_CORE_DIR . 'includes/dashboard/class-yazan-dashboard-audit.php';

		require_once $dir . 'class-yazan-rbac-schema.php';
		require_once $dir . 'class-yazan-permission-registry.php';
		require_once $dir . 'class-yazan-permissions.php';
		require_once $dir . 'class-yazan-roles.php';
		require_once $dir . 'class-yazan-users.php';
		require_once $dir . 'class-yazan-cap-projection.php';
		require_once $dir . 'class-yazan-rbac-guard.php';

		Yazan_Cap_Projection::register();
		Yazan_Users::register();

		/*
		 * A user's WordPress role changing can flip them in or out of the administrator fallback
		 * that Yazan_Permissions::resolve() applies, so the cached set has to be invalidated.
		 */
		add_action( 'set_user_role', array( __CLASS__, 'on_wp_role_change' ), 10, 1 );
		add_action( 'add_user_role', array( __CLASS__, 'on_wp_role_change' ), 10, 1 );
		add_action( 'remove_user_role', array( __CLASS__, 'on_wp_role_change' ), 10, 1 );

		/*
		 * Self-install on `init` priority 1.
		 *
		 * Yazan_Core_Installer only runs on `admin_init`, and this store can be operated entirely
		 * from /dashboard without ever loading wp-admin — in which case the tables would never be
		 * created and the whole subsystem would silently stay inert.
		 */
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 1 );
	}

	/**
	 * The state this build expects to find installed.
	 *
	 * @return string
	 */
	private static function signature() {
		return Yazan_RBAC_Schema::SCHEMA_VERSION
			. ':' . Yazan_Permission_Registry::REGISTRY_VERSION
			. ':' . Yazan_Dashboard_Audit::SCHEMA_VERSION;
	}

	/**
	 * Install if the stored signature does not match this build.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( get_option( self::READY_OPTION ) === self::signature() ) {
			return;
		}
		if ( get_transient( self::INSTALL_LOCK ) ) {
			return;
		}

		set_transient( self::INSTALL_LOCK, 1, MINUTE_IN_SECONDS );
		self::install();
		delete_transient( self::INSTALL_LOCK );
	}

	/**
	 * Create the tables, sync the catalog, seed the default roles, and backfill existing staff.
	 *
	 * Every step is idempotent. Also called from {@see Yazan_Core_Installer::install()} so a
	 * wp-admin load performs the same work.
	 *
	 * @return bool
	 */
	public static function install() {
		/*
		 * The audit table is upgraded here, not only from Yazan_Core_Installer, for two reasons:
		 * it has the same admin_init-only trigger problem this class exists to solve, and RBAC
		 * starts writing user.* / role.* rows the moment it is live — so its schema must be current
		 * before, not after.
		 */
		if ( class_exists( 'Yazan_Dashboard_Audit' ) ) {
			Yazan_Dashboard_Audit::install_table();
		}

		if ( ! Yazan_RBAC_Schema::install() ) {
			return false;
		}

		Yazan_Permission_Registry::sync_catalog();
		Yazan_Roles::seed_defaults();
		self::backfill();

		Yazan_Permissions::bump();

		// Autoloaded — read on every request by maybe_install().
		update_option( self::READY_OPTION, self::signature(), true );

		return true;
	}

	/**
	 * One-time mapping of the WordPress roles that already exist onto Yazan roles.
	 *
	 * This is what makes the current owner's account work THROUGH the Yazan model as well as
	 * around it: an administrator is already treated as super by the resolver, but giving them a
	 * real super-admin row means the Users screen shows the truth and the "last super admin" guard
	 * has something to count.
	 *
	 * @return int Users backfilled.
	 */
	private static function backfill() {
		if ( get_option( self::BACKFILL_OPTION ) ) {
			return 0;
		}

		$map = array(
			'administrator' => Yazan_Roles::SUPER_SLUG,
			'shop_manager'  => 'manager',
		);

		$count = 0;

		foreach ( $map as $wp_role => $yazan_slug ) {
			$role = Yazan_Roles::get_by_slug( $yazan_slug );
			if ( ! $role ) {
				continue;
			}

			$users = get_users(
				array(
					'role'   => $wp_role,
					'fields' => 'ID',
					'number' => 200,
				)
			);

			foreach ( (array) $users as $user_id ) {
				Yazan_Roles::assign( (int) $user_id, $role['id'] );
				++$count;
			}
		}

		update_option( self::BACKFILL_OPTION, 1, false );

		return $count;
	}

	/**
	 * A WordPress role change invalidates the cached permission set.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function on_wp_role_change( $user_id ) {
		Yazan_Permissions::flush_user( (int) $user_id );
		Yazan_Permissions::bump();
	}

	/**
	 * Health summary for GET /status.
	 *
	 * @return array
	 */
	public static function status() {
		$installed = Yazan_RBAC_Schema::is_installed();

		return array(
			'installed'        => $installed,
			'schema_version'   => (string) get_option( Yazan_RBAC_Schema::SCHEMA_OPTION, '' ),
			'registry_version' => (string) get_option( Yazan_Permission_Registry::VERSION_OPTION, '' ),
			'permissions'      => count( Yazan_Permission_Registry::all() ),
			'roles'            => $installed ? count( Yazan_Roles::all() ) : 0,
			'staff'            => $installed ? count( Yazan_Roles::staff_ids() ) : 0,
			'super_admins'     => count( Yazan_RBAC_Guard::super_user_ids() ),
			'orphan_grants'    => $installed ? Yazan_Permission_Registry::orphan_slugs() : array(),
		);
	}
}
