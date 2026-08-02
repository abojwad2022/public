<?php
/**
 * Authorization boundary.
 *
 * The domain never calls Yazan_Permissions, and never calls current_user_can(). It asks this port,
 * and the adapter decides. That is what lets the whole rule set be tested without WordPress, and
 * what makes "no dependency on native WordPress roles" structurally true rather than a convention.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Permission checks.
 */
interface AuthorizationPort {

	/**
	 * @param string $permission Permission slug.
	 * @return bool
	 */
	public function can( $permission );

	/**
	 * @param string[] $permissions Permission slugs.
	 * @return bool
	 */
	public function can_any( array $permissions );

	/**
	 * Throw Forbidden unless the actor holds the permission.
	 *
	 * @param string $permission Permission slug.
	 * @return void
	 */
	public function require_permission( $permission );

	/**
	 * Throw Forbidden unless the actor holds at least one.
	 *
	 * @param string[] $permissions Permission slugs.
	 * @return void
	 */
	public function require_any( array $permissions );

	/** @return int Current user id, 0 when none. */
	public function actor_id();

	/** @return string[] Role slugs, for the audit trail. */
	public function actor_roles();
}
