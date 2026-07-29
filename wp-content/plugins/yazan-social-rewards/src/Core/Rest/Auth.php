<?php
/**
 * REST permission helpers.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Rest;

use Yazan\Rewards\Core\Security\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared permission_callback factories for the yazan-rewards/v1 namespace.
 *
 * Auth model is same-origin cookie + the standard `wp_rest` nonce (sent as
 * X-WP-Nonce). WordPress core only treats a cookie-authenticated REST request
 * as logged-in when that nonce is valid, so a capability check in every
 * permission_callback is sufficient CSRF protection for writes.
 */
final class Auth {

	/**
	 * Require an authenticated customer (any logged-in user).
	 *
	 * @return callable
	 */
	public function require_customer(): callable {
		return static function () {
			if ( ! is_user_logged_in() ) {
				return new \WP_Error(
					'yazan_rewards_unauthorized',
					__( 'You must be signed in.', 'yazan-rewards' ),
					array( 'status' => rest_authorization_required_code() )
				);
			}
			return true;
		};
	}

	/**
	 * Require a specific capability.
	 *
	 * @param string $cap Capability.
	 * @return callable
	 */
	public function require_cap( string $cap ): callable {
		return static function () use ( $cap ) {
			if ( ! current_user_can( $cap ) ) {
				return new \WP_Error(
					'yazan_rewards_forbidden',
					__( 'Insufficient permissions.', 'yazan-rewards' ),
					array( 'status' => rest_authorization_required_code() )
				);
			}
			return true;
		};
	}

	/**
	 * Require platform-management capability (admin surface).
	 *
	 * @return callable
	 */
	public function require_manager(): callable {
		return $this->require_cap( Capabilities::MANAGE );
	}
}
