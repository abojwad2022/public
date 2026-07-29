<?php
/**
 * Capability management.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Grants/removes the plugin's custom capabilities on activation/uninstall.
 * Ambassadors are NOT roles — status lives in the plugin table.
 */
final class Capabilities {

	/** Full-management capability. */
	public const MANAGE = 'manage_yazan_rewards';

	/** Read-only capability. */
	public const VIEW = 'view_yazan_rewards';

	/**
	 * @param array<string,string[]> $map capability => roles.
	 */
	public function __construct( private array $map ) {}

	/**
	 * Add every capability to its mapped roles.
	 *
	 * @return void
	 */
	public function grant(): void {
		foreach ( $this->map as $cap => $roles ) {
			foreach ( (array) $roles as $role_name ) {
				$role = get_role( $role_name );
				if ( $role && ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}
	}

	/**
	 * Remove every capability from every role (uninstall).
	 *
	 * @return void
	 */
	public function revoke(): void {
		$roles = wp_roles();
		foreach ( array_keys( $this->map ) as $cap ) {
			foreach ( array_keys( $roles->roles ) as $role_name ) {
				$role = get_role( $role_name );
				if ( $role && $role->has_cap( $cap ) ) {
					$role->remove_cap( $cap );
				}
			}
		}
	}
}
