<?php
/**
 * Supported-conditions catalog.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Rules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describes the condition types a rule can use, with the input shape each expects.
 * Drives the admin builder's condition rows and validates rule input.
 */
final class ConditionCatalog {

	/**
	 * Condition definitions.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function definitions(): array {
		$defs = array(
			array( 'key' => 'user_level', 'label' => __( 'User level', 'yazan-rewards' ), 'input' => 'tier', 'multiple' => true ),
			array( 'key' => 'lifetime_points', 'label' => __( 'Lifetime points', 'yazan-rewards' ), 'input' => 'number_op' ),
			array( 'key' => 'purchase_history', 'label' => __( 'Purchase history (orders)', 'yazan-rewards' ), 'input' => 'number_op' ),
			array( 'key' => 'order_total', 'label' => __( 'Order value', 'yazan-rewards' ), 'input' => 'number_op' ),
			array( 'key' => 'product', 'label' => __( 'Product', 'yazan-rewards' ), 'input' => 'product', 'multiple' => true ),
			array( 'key' => 'category', 'label' => __( 'Category', 'yazan-rewards' ), 'input' => 'category', 'multiple' => true ),
			array( 'key' => 'date', 'label' => __( 'Date', 'yazan-rewards' ), 'input' => 'date_range' ),
			array( 'key' => 'customer_role', 'label' => __( 'Customer role', 'yazan-rewards' ), 'input' => 'role', 'multiple' => true ),
		);

		/**
		 * Filter the condition catalog.
		 *
		 * @param array $defs Condition definitions.
		 */
		return (array) apply_filters( 'yazan_rewards/rules/condition_catalog', $defs );
	}

	/**
	 * Valid condition keys.
	 *
	 * @return string[]
	 */
	public function keys(): array {
		return array_map( static fn( $d ) => (string) $d['key'], $this->definitions() );
	}

	/**
	 * The comparison operators offered for number_op inputs.
	 *
	 * @return string[]
	 */
	public function operators(): array {
		return array( '=', '!=', '>', '>=', '<', '<=' );
	}
}
