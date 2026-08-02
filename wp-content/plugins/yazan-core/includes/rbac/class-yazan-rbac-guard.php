<?php
/**
 * Yazan RBAC — safety rails.
 *
 * Holding `roles.edit` is not enough to do anything you like. These assertions sit between the
 * REST controllers and the data layer and close the four ways an RBAC system usually gets broken:
 *
 *   1. Locking everyone out by removing the last super admin.
 *   2. Locking yourself out by editing your own access.
 *   3. Privilege escalation — granting yourself, or a role you administer, more than you hold.
 *   4. A mid-tier operator neutering a role above them (even just by stripping it).
 *
 * Every check returns true or a WP_Error, so a controller reads as a list of preconditions.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Preconditions for every RBAC mutation.
 */
class Yazan_RBAC_Guard {

	/**
	 * User ids that can still administer the system, ignoring the given exclusions.
	 *
	 * Counts BOTH holders of a Yazan super role and real WordPress administrators, because
	 * Yazan_Permissions treats the latter as super too. Suspended accounts do not count — a
	 * suspended super admin cannot sign in to fix anything.
	 *
	 * @param int $exclude_user User id to pretend is gone.
	 * @param int $exclude_role Role id to pretend is gone (or no longer super).
	 * @return int[]
	 */
	public static function super_user_ids( $exclude_user = 0, $exclude_role = 0, $store_id = null ) {
		$exclude_user = absint( $exclude_user );
		$exclude_role = absint( $exclude_role );

		$ids = array();

		if ( Yazan_RBAC_Schema::is_installed() ) {
			global $wpdb;
			$pivot = Yazan_RBAC_Schema::user_roles_table();
			$roles = Yazan_RBAC_Schema::roles_table();

			/*
			 * SCOPED TO THE STORE. Without the predicate this counted supers across every tenant, so
			 * the rail promising "the store will not be left without an administrator" was satisfied
			 * by an administrator of a DIFFERENT store — store B's last admin could be demoted
			 * because store A had one. The error message already said "leave the store with no super
			 * admin"; it simply stopped being true when membership became per-store.
			 *
			 * Platform grants (store_id = 0) count for every store — a platform super genuinely can
			 * administer any of them.
			 */
			$store = null === $store_id
				? ( class_exists( 'Yazan_Store_Context' ) ? Yazan_Store_Context::current() : 1 )
				: absint( $store_id );

			$sql    = "SELECT DISTINCT ur.user_id FROM {$pivot} ur JOIN {$roles} r ON r.id = ur.role_id"
				. " WHERE r.is_super = 1 AND ur.store_id IN ( %d, %d )";
			$params = array( Yazan_Permissions::PLATFORM_STORE, $store );

			if ( $exclude_role ) {
				$sql     .= ' AND r.id <> %d';
				$params[] = $exclude_role;
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $params
				? $wpdb->get_col( $wpdb->prepare( $sql, $params ) )
				: $wpdb->get_col( $sql );
			// phpcs:enable

			$ids = array_map( 'intval', (array) $rows );
		}

		// WordPress administrators are always effectively super — see Yazan_Permissions::resolve().
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
				'number' => 50,
			)
		);
		$ids = array_merge( $ids, array_map( 'intval', (array) $admins ) );

		$ids = array_unique( $ids );

		return array_values(
			array_filter(
				$ids,
				static function ( $id ) use ( $exclude_user ) {
					if ( $id === $exclude_user ) {
						return false;
					}
					return ! Yazan_Users::is_suspended( $id );
				}
			)
		);
	}

	/**
	 * Refuse an operation that would leave nobody able to administer the system.
	 *
	 * Callers must hold {@see Yazan_Roles::lock()} around the check AND the write, otherwise two
	 * concurrent requests can both observe "one other super admin remains" and both proceed.
	 *
	 * @param int $exclude_user User id being deleted, suspended or demoted.
	 * @param int $exclude_role Role id being deleted or stripped of is_super.
	 * @return true|WP_Error
	 */
	public static function assert_super_remains( $exclude_user = 0, $exclude_role = 0, $store_id = null ) {
		if ( self::super_user_ids( $exclude_user, $exclude_role, $store_id ) ) {
			return true;
		}

		return new WP_Error(
			'yazan_last_super',
			__( 'This would leave the store with no super admin. Give another account super-admin access first.', 'yazan' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Refuse an operation performed on your own account.
	 *
	 * @param int    $target_user_id Account being acted on.
	 * @param string $message        Optional override message.
	 * @return true|WP_Error
	 */
	public static function assert_not_self( $target_user_id, $message = '' ) {
		if ( get_current_user_id() !== absint( $target_user_id ) ) {
			return true;
		}

		return new WP_Error(
			'yazan_self_action',
			$message ? $message : __( 'You cannot change your own access. Ask another administrator to do it.', 'yazan' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Refuse a grant of permissions the caller does not hold.
	 *
	 * @param string[] $slugs   Permission slugs being granted.
	 * @param int|null $user_id Caller, or null for the current user.
	 * @return true|WP_Error
	 */
	public static function assert_can_grant( array $slugs, $user_id = null ) {
		if ( Yazan_Permissions::is_super( $user_id ) ) {
			return true;
		}

		$mine    = Yazan_Permissions::grantable_by( $user_id );
		$blocked = array_values( array_diff( $slugs, $mine ) );

		if ( ! $blocked ) {
			return true;
		}

		return new WP_Error(
			'yazan_escalation',
			__( 'You cannot grant a permission you do not hold yourself.', 'yazan' ),
			array(
				'status'  => 403,
				'blocked' => $blocked,
			)
		);
	}

	/**
	 * Refuse editing or deleting a role that is above the caller.
	 *
	 * The clause that matters is the second one: the role's CURRENT permission set must also be a
	 * subset of the caller's. Without it a Manager could edit the Super Admin role — if only to
	 * strip it — and lock their own boss out.
	 *
	 * @param array    $role    Role array from Yazan_Roles::get().
	 * @param int|null $user_id Caller.
	 * @return true|WP_Error
	 */
	public static function assert_can_manage_role( array $role, $user_id = null ) {
		if ( Yazan_Permissions::is_super( $user_id ) ) {
			return true;
		}

		if ( ! empty( $role['is_super'] ) ) {
			return new WP_Error(
				'yazan_escalation',
				__( 'Only a super admin can change a super-admin role.', 'yazan' ),
				array( 'status' => 403 )
			);
		}

		$mine    = Yazan_Permissions::grantable_by( $user_id );
		$blocked = array_values( array_diff( (array) $role['permissions'], $mine ) );

		if ( ! $blocked ) {
			return true;
		}

		return new WP_Error(
			'yazan_escalation',
			__( 'This role holds permissions you do not, so you cannot change it.', 'yazan' ),
			array(
				'status'  => 403,
				'blocked' => $blocked,
			)
		);
	}

	/**
	 * Refuse setting `is_super` from a non-super account.
	 *
	 * @param bool     $wants_super Requested flag.
	 * @param int|null $user_id     Caller.
	 * @return true|WP_Error
	 */
	public static function assert_can_set_super( $wants_super, $user_id = null ) {
		if ( ! $wants_super || Yazan_Permissions::is_super( $user_id ) ) {
			return true;
		}

		return new WP_Error(
			'yazan_escalation',
			__( 'Only a super admin can mark a role as super admin.', 'yazan' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Refuse assigning roles whose combined permissions exceed the caller's own.
	 *
	 * @param int[]    $role_ids Roles being assigned.
	 * @param int|null $user_id  Caller.
	 * @return true|WP_Error
	 */
	public static function assert_can_assign_roles( array $role_ids, $user_id = null, $store_id = null ) {
		// Platform super, not store super: role assignment is a global act because role
		// definitions are global, even when the membership it writes is store-scoped.
		if ( Yazan_Permissions::is_platform_super( $user_id ) ) {
			return true;
		}

		$union = array();
		foreach ( $role_ids as $role_id ) {
			$role = Yazan_Roles::get( $role_id );
			if ( ! $role ) {
				continue;
			}
			if ( $role['is_super'] ) {
				/*
				 * ⚠️ THE SELF-ELEVATION GATE. A super role assigned inside a store makes its holder
				 * super OF THAT STORE — a legitimate tier (Store Owner). But only a PLATFORM super
				 * may hand it out. Without this, anyone who could edit a store's staff could grant
				 * themselves that role, and the store-engine route that does exactly that called no
				 * guard at all.
				 */
				return new WP_Error(
					'yazan_escalation',
					__( 'Only a platform super admin can assign a super-admin role.', 'yazan' ),
					array( 'status' => 403 )
				);
			}
			$union = array_merge( $union, $role['permissions'] );
		}

		return self::assert_can_grant( array_values( array_unique( $union ) ), $user_id );
	}

	/**
	 * Refuse acting on an account more privileged than the caller.
	 *
	 * @param int      $target_user_id Account being edited.
	 * @param int|null $user_id        Caller.
	 * @return true|WP_Error
	 */
	public static function assert_can_edit_user( $target_user_id, $user_id = null ) {
		if ( Yazan_Permissions::is_super( $user_id ) ) {
			return true;
		}

		$target_user_id = absint( $target_user_id );

		if ( Yazan_Users::is_wp_admin( $target_user_id ) ) {
			return new WP_Error(
				'yazan_escalation',
				__( 'WordPress administrators can only be managed by a super admin.', 'yazan' ),
				array( 'status' => 403 )
			);
		}

		if ( Yazan_Permissions::is_super( $target_user_id ) ) {
			return new WP_Error(
				'yazan_escalation',
				__( 'Only a super admin can manage another super admin.', 'yazan' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/* --------------------------------------------------------------------- */
	/* WordPress role changes                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * WordPress administrators who could still sign in, ignoring one user.
	 *
	 * Suspended accounts do not count: Yazan_Users::block_suspended_login() hooks `authenticate`,
	 * which runs for wp-login.php as well as the dashboard, so a suspended administrator cannot get
	 * in anywhere to fix things.
	 *
	 * @param int $exclude_user User id to pretend is gone.
	 * @return int[]
	 */
	public static function wp_admin_ids( $exclude_user = 0 ) {
		$exclude_user = absint( $exclude_user );

		$admins = get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
				'number' => 100,
			)
		);

		return array_values(
			array_filter(
				array_map( 'intval', (array) $admins ),
				static function ( $id ) use ( $exclude_user ) {
					if ( $id === $exclude_user ) {
						return false;
					}
					return ! Yazan_Users::is_suspended( $id );
				}
			)
		);
	}

	/**
	 * Refuse a WordPress role change that would leave the site with no usable administrator.
	 *
	 * Demoting the last administrator is worse than any Yazan-side mistake: WordPress itself would
	 * have nobody who can install a plugin, edit a user, or reach wp-admin at all, and the dashboard
	 * cannot grant that back — the projection is additive within Yazan's scope only.
	 *
	 * @param int $user_id User being demoted.
	 * @return true|WP_Error
	 */
	public static function assert_wp_admin_remains( $user_id ) {
		if ( self::wp_admin_ids( $user_id ) ) {
			return true;
		}

		return new WP_Error(
			'yazan_last_wp_admin',
			__( 'This is the last WordPress administrator. Promote another account first, or WordPress itself would be left with nobody in charge.', 'yazan' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * May the caller move this account onto that WordPress role?
	 *
	 * Two separate rules:
	 *
	 *  - Only a Yazan super admin may touch WordPress roles at all. This is site-level
	 *    authorization, not store-level.
	 *  - Only an account that IS a WordPress administrator may hand out `administrator`. Without
	 *    this, a Yazan-only super admin could mint a full WordPress administrator from the
	 *    dashboard — exactly the escalation path the capability projection refuses to open by
	 *    deliberately mapping `users.*` to no capabilities at all.
	 *
	 * @param string   $role    Target WordPress role slug ('' clears every role).
	 * @param int|null $user_id Caller.
	 * @return true|WP_Error
	 */
	public static function assert_can_set_wp_role( $role, $user_id = null ) {
		// Site-level authorisation, so it asks the site-level question. Super-in-one-store must
		// not reach WordPress role assignment for the whole installation.
		if ( ! Yazan_Permissions::is_platform_super( $user_id ) ) {
			return new WP_Error(
				'yazan_escalation',
				__( 'Only a super admin can change a WordPress role.', 'yazan' ),
				array( 'status' => 403 )
			);
		}

		if ( 'administrator' !== $role ) {
			return true;
		}

		$caller = ( null === $user_id ) ? get_current_user_id() : absint( $user_id );

		if ( Yazan_Users::is_wp_admin( $caller ) ) {
			return true;
		}

		return new WP_Error(
			'yazan_escalation',
			__( 'Only a WordPress administrator can make another account a WordPress administrator.', 'yazan' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Optimistic-concurrency check for the Role Editor.
	 *
	 * The editor sends a full permission set, so two people saving the same role would silently
	 * overwrite each other. The client echoes the `updated_at` it loaded; a mismatch means the row
	 * moved underneath them.
	 *
	 * @param array  $role     Current role array.
	 * @param string $expected updated_at the client last saw ('' skips the check).
	 * @return true|WP_Error
	 */
	public static function assert_fresh( array $role, $expected ) {
		$expected = trim( (string) $expected );

		if ( '' === $expected || $expected === $role['updated_at'] ) {
			return true;
		}

		return new WP_Error(
			'yazan_stale',
			__( 'This role was changed by someone else while you were editing. Reload and try again.', 'yazan' ),
			array( 'status' => 409 )
		);
	}
}
