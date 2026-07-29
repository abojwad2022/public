<?php
/**
 * Capability management.
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

namespace Yazan\PaymentBridge\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Grants the Bridge's capabilities on activation and removes them on uninstall.
 */
final class Capabilities {

	/** Read the payment-event ledger. */
	public const VIEW = 'yazan_payment_view';

	/** Change settings. */
	public const MANAGE = 'yazan_payment_manage';

	/** Re-run an integration for a stored event. */
	public const RETRY = 'yazan_payment_retry';

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
		if ( ! function_exists( 'wp_roles' ) ) {
			return;
		}

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
