<?php
/**
 * Supported-actions catalog.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Rules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describes the action types a rule can perform, with the parameters each takes.
 * Drives the admin builder's action rows and validates rule input.
 */
final class ActionCatalog {

	/**
	 * Action definitions.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function definitions(): array {
		$defs = array(
			array( 'key' => Action::ADD_POINTS, 'label' => __( 'Add points', 'yazan-rewards' ), 'params' => array( 'amount' => 'number', 'per_currency' => 'number_optional' ) ),
			array( 'key' => Action::REMOVE_POINTS, 'label' => __( 'Remove points', 'yazan-rewards' ), 'params' => array( 'amount' => 'number' ) ),
			array( 'key' => Action::UPGRADE_LEVEL, 'label' => __( 'Upgrade level', 'yazan-rewards' ), 'params' => array( 'tier' => 'tier' ) ),
			array( 'key' => Action::GIVE_BADGE, 'label' => __( 'Give badge', 'yazan-rewards' ), 'params' => array( 'achievement' => 'achievement' ) ),
			array( 'key' => Action::CREATE_COUPON, 'label' => __( 'Create coupon', 'yazan-rewards' ), 'params' => array( 'discount_type' => 'coupon_type', 'amount' => 'number', 'expiry_days' => 'number', 'free_shipping' => 'bool' ) ),
			array( 'key' => Action::SEND_NOTIFICATION, 'label' => __( 'Send notification', 'yazan-rewards' ), 'params' => array( 'subject' => 'text', 'message' => 'textarea' ) ),
		);

		/**
		 * Filter the action catalog so add-ons can register new action types
		 * (execute them via `yazan_rewards/rules/execute_action`).
		 *
		 * @param array $defs Action definitions.
		 */
		return (array) apply_filters( 'yazan_rewards/rules/action_catalog', $defs );
	}

	/**
	 * Valid action keys.
	 *
	 * @return string[]
	 */
	public function keys(): array {
		return array_map( static fn( $d ) => (string) $d['key'], $this->definitions() );
	}
}
