<?php
/**
 * Login → rule-trigger observer.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Integration\WordPress;

use Yazan\Rewards\Core\Contracts\Hookable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridges `wp_login` to the generic rule trigger `yazan_rewards/trigger/login`,
 * so login-based rules run. Kept a thin bridge so the RuleEngine stays the single
 * place events are consumed.
 */
final class LoginObserver implements Hookable {

	/**
	 * @inheritDoc
	 */
	public function hooks(): array {
		return array(
			array( 'type' => 'action', 'hook' => 'wp_login', 'method' => 'on_login', 'args' => 2 ),
		);
	}

	/**
	 * @param string $user_login User login.
	 * @param object $user       WP_User.
	 * @return void
	 */
	public function on_login( $user_login, $user ): void {
		$user_id = ( $user instanceof \WP_User ) ? (int) $user->ID : 0;
		if ( $user_id > 0 ) {
			/**
			 * Fires when a customer logs in (rule trigger).
			 *
			 * @param int   $user_id User id.
			 * @param array $context Extra facts.
			 */
			do_action( 'yazan_rewards/trigger/login', $user_id, array() );
		}
	}
}
