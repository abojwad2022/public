<?php
/**
 * Authorization adapter → Yazan_Permissions.
 *
 * The ONE place this module talks to the platform's permission service. Nothing here uses
 * current_user_can(), and no WordPress role name appears anywhere in the module.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Infrastructure\Adapter;

use Yazan\Homepage\Domain\Exception\Forbidden;
use Yazan\Homepage\Domain\Port\AuthorizationPort;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Delegates every check to Yazan_Permissions.
 */
final class YazanAuthorizationAdapter implements AuthorizationPort {

	/**
	 * @param string $permission Permission slug.
	 * @return bool
	 */
	public function can( $permission ) {
		if ( ! class_exists( 'Yazan_Permissions' ) ) {
			// RBAC not loaded: refuse rather than fall back to a capability check.
			return false;
		}

		return (bool) \Yazan_Permissions::can( $permission );
	}

	/**
	 * @param string[] $permissions Permission slugs.
	 * @return bool
	 */
	public function can_any( array $permissions ) {
		$permissions = array_values( array_filter( $permissions ) );

		if ( ! $permissions || ! class_exists( 'Yazan_Permissions' ) ) {
			return false;
		}

		return (bool) \Yazan_Permissions::can_any( $permissions );
	}

	/**
	 * @param string $permission Permission slug.
	 * @return void
	 * @throws Forbidden When denied.
	 */
	public function require_permission( $permission ) {
		if ( ! $this->can( $permission ) ) {
			throw ( new Forbidden( 'Missing permission: ' . $permission ) )
				->with_context( array( 'permission' => $permission ) );
		}
	}

	/**
	 * @param string[] $permissions Permission slugs.
	 * @return void
	 * @throws Forbidden When denied.
	 */
	public function require_any( array $permissions ) {
		if ( ! $this->can_any( $permissions ) ) {
			throw ( new Forbidden( 'Missing permission.' ) )
				->with_context( array( 'permissions' => array_values( $permissions ) ) );
		}
	}

	/** @return int */
	public function actor_id() {
		return (int) get_current_user_id();
	}

	/**
	 * Yazan role slugs for the audit trail — the "role used" field the audit table does not have
	 * a column for, carried inside `changes` instead of migrating a working table.
	 *
	 * @return string[]
	 */
	public function actor_roles() {
		$user_id = $this->actor_id();

		if ( ! $user_id || ! class_exists( 'Yazan_Roles' ) ) {
			return array();
		}

		$roles = array();

		// Yazan_Roles::for_user() returns role IDS, not rows — resolve each to its stable slug,
		// because an id means nothing to whoever reads the audit trail six months from now.
		foreach ( (array) \Yazan_Roles::for_user( $user_id ) as $role_id ) {
			$role = \Yazan_Roles::get( (int) $role_id );
			if ( is_array( $role ) && ! empty( $role['slug'] ) ) {
				$roles[] = (string) $role['slug'];
			}
		}

		return $roles;
	}
}
