<?php
/**
 * Public Rules Class
 *
 * Handles frontend validation of conditional rules for WooCommerce coupons.
 * Supports multiple rule groups with OR logic between groups and AND logic within groups.
 * Invalid coupons are hidden from display rather than showing error messages.
 *
 * @package    Power_Coupons
 * @subpackage Power_Coupons/Public
 * @since      1.0.0
 */

namespace Power_Coupons\Public_Folder;

use Power_Coupons\Includes\Power_Coupons_Rules_Registry;
use Power_Coupons\Includes\Power_Coupons_Utilities;
use Power_Coupons\Includes\Traits\Power_Coupons_Singleton;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Power_Coupons_Frontend_Rules
 *
 * Validates conditional rules on the frontend with support for operators.
 * Returns false quietly for invalid coupons to hide them from customers.
 */
class Power_Coupons_Frontend_Rules {

	use Power_Coupons_Singleton;

	/**
	 * Constructor
	 */
	protected function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize WordPress hooks
	 *
	 * @return void
	 */
	private function init_hooks() {
		// Main coupon validation hook - high priority to run early.
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate_conditional_rules' ), 5, 2 );
	}

	/**
	 * Validate conditional rules for a coupon
	 *
	 * Returns false quietly if rules don't pass, so coupon is hidden from display.
	 * Does not throw exceptions to avoid showing error messages to customers.
	 *
	 * Logic: OR between groups, AND within groups.
	 * - At least ONE group must be valid (OR)
	 * - ALL conditions within a group must be valid (AND)
	 *
	 * @param bool       $is_valid Whether the coupon is currently valid.
	 * @param \WC_Coupon $coupon   WooCommerce coupon object.
	 *
	 * @return bool True if valid, false otherwise.
	 */
	public function validate_conditional_rules( $is_valid, $coupon ) {
		// Early return if already invalid.
		if ( ! $is_valid ) {
			return $is_valid;
		}

		$coupon_id = $coupon->get_id();

		// Check if rules are enabled.
		if ( ! Power_Coupons_Rules_Registry::are_rules_enabled( $coupon_id ) ) {
			return $is_valid;
		}

		// Skip validation in admin.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $is_valid;
		}

		// Get all rule groups.
		$groups = Power_Coupons_Rules_Registry::get_rule_groups( $coupon_id );

		if ( empty( $groups ) ) {
			return $is_valid;
		}

		// OR logic between groups: At least ONE group must be valid.
		foreach ( $groups as $group ) {
			if ( $this->validate_group( $group ) ) {
				// At least one group is valid, coupon is valid.
				return true;
			}
		}

		// No groups were valid, hide the coupon.
		return false;
	}

	/**
	 * Validate a single rule group
	 *
	 * AND logic: ALL rules within the group must be valid.
	 *
	 * @param array<string, mixed> $group Rule group data.
	 *
	 * @return bool True if all rules in group are valid, false otherwise.
	 */
	private function validate_group( $group ) {
		// Check if group has rules.
		if ( empty( $group['rules'] ) || ! is_array( $group['rules'] ) ) {
			return true; // Empty group is always valid.
		}

		// AND logic: All rules must pass.
		foreach ( $group['rules'] as $rule ) {
			if ( ! $this->validate_rule( $rule ) ) {
				return false; // One rule failed, group fails.
			}
		}

		return true; // All rules passed.
	}

	/**
	 * Validate a single rule based on its type and operator
	 *
	 * @param array<string, mixed> $rule Rule data with type, operator, and value.
	 *
	 * @return bool True if rule is valid, false otherwise.
	 */
	private function validate_rule( $rule ) {
		// Skip if rule has no value set (empty/unset condition always passes).
		if ( ! isset( $rule['value'] ) || '' === $rule['value'] ) {
			return true;
		}

		$type     = isset( $rule['type'] ) && is_string( $rule['type'] ) ? $rule['type'] : '';
		$operator = isset( $rule['operator'] ) && is_string( $rule['operator'] ) ? $rule['operator'] : '';
		$value    = $rule['value'];

		// Validate based on rule type.
		switch ( $type ) {
			case 'cart_total':
				$operator_str = is_scalar( $operator ) ? (string) $operator : 'equals';
				return $this->validate_cart_total_rule( $operator_str, $value );

			case 'cart_items':
				$operator_str = is_scalar( $operator ) ? (string) $operator : 'equals';
				return $this->validate_cart_items_rule( $operator_str, $value );

			case 'products':
				$operator_str = is_scalar( $operator ) ? (string) $operator : 'equals';
				return $this->validate_products_rule( $operator_str, $value );

			case 'product_categories':
				$operator_str = is_scalar( $operator ) ? (string) $operator : 'equals';
				return $this->validate_categories_rule( $operator_str, $value );

			default:
				return true; // Unknown type always passes.
		}
	}

	/**
	 * Validate cart total rule with operator
	 *
	 * @param string $operator Comparison operator.
	 * @param mixed  $value    Value to compare against.
	 *
	 * @return bool True if valid, false otherwise.
	 */
	private function validate_cart_total_rule( $operator, $value ) {
		if ( ! WC()->cart ) {
			return false;
		}

		$cart       = WC()->cart;
		$cart_total = $cart->get_subtotal();
		$value      = is_numeric( $value ) ? floatval( $value ) : 0.0;

		return Power_Coupons_Utilities::compare_numeric( $cart_total, $operator, $value );
	}

	/**
	 * Validate cart items count rule with operator
	 *
	 * @param string $operator Comparison operator.
	 * @param mixed  $value    Value to compare against.
	 *
	 * @return bool True if valid, false otherwise.
	 */
	private function validate_cart_items_rule( $operator, $value ) {
		if ( ! WC()->cart ) {
			return false;
		}

		$cart             = WC()->cart;
		$cart_items_count = count( $cart->get_cart() );
		$value            = is_numeric( $value ) ? intval( $value ) : 0;

		return Power_Coupons_Utilities::compare_numeric( $cart_items_count, $operator, $value );
	}

	/**
	 * Validate products in cart rule with operator
	 *
	 * @param string $operator Comparison operator (in_list or not_in_list).
	 * @param mixed  $value    Array of product IDs.
	 *
	 * @return bool True if valid, false otherwise.
	 */
	private function validate_products_rule( $operator, $value ) {
		if ( ! is_int( $value ) || empty( $value ) ) {
			return true; // Empty list always passes.
		}

		if ( ! WC()->cart ) {
			return false;
		}

		$cart = WC()->cart;

		// Get product IDs in cart (including variations).
		$cart_product_ids = array();
		foreach ( $cart->get_cart() as $cart_item ) {
			$cart_product_ids[] = $cart_item['product_id'];
			if ( isset( $cart_item['variation_id'] ) && $cart_item['variation_id'] > 0 ) {
				$cart_product_ids[] = $cart_item['variation_id'];
			}
		}
		$cart_product_ids = array_unique( $cart_product_ids );

		// Check for matches.
		$has_match = in_array( $value, $cart_product_ids, true );

		// Apply operator.
		switch ( $operator ) {
			case 'in_list':
				return $has_match;

			case 'not_in_list':
				return ! $has_match;

			default:
				return true;
		}
	}

	/**
	 * Validate product categories in cart rule with operator
	 *
	 * @param string $operator Comparison operator (in_list or not_in_list).
	 * @param mixed  $value    Array of category IDs.
	 *
	 * @return bool True if valid, false otherwise.
	 */
	private function validate_categories_rule( $operator, $value ) {
		if ( ! is_int( $value ) || empty( $value ) ) {
			return true; // Empty list always passes.
		}

		if ( ! WC()->cart ) {
			return false;
		}

		$cart = WC()->cart;

		// Get all categories from cart products.
		$cart_categories = array();
		foreach ( $cart->get_cart() as $cart_item ) {
			$product_id = $cart_item['product_id'];
			$terms      = get_the_terms( $product_id, 'product_cat' );

			if ( $terms && ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$cart_categories[] = $term->term_id;
				}
			}
		}
		$cart_categories = array_unique( $cart_categories );

		// Check for matches.
		$has_match = in_array( $value, $cart_categories, true );

		// Apply operator.
		switch ( $operator ) {
			case 'in_list':
				return $has_match;

			case 'not_in_list':
				return ! $has_match;

			default:
				return true;
		}
	}

	/**
	 * Check if a specific coupon is valid based on conditional rules
	 *
	 * Public method that can be called from other classes to check validity.
	 * Useful for filtering coupons before display.
	 *
	 * @param int $coupon_id Coupon post ID.
	 *
	 * @return bool True if valid, false otherwise.
	 */
	public function is_coupon_valid( $coupon_id ) {
		// Check if rules are enabled.
		if ( ! Power_Coupons_Rules_Registry::are_rules_enabled( $coupon_id ) ) {
			return true; // No rules means always valid.
		}

		// Get all rule groups.
		$groups = Power_Coupons_Rules_Registry::get_rule_groups( $coupon_id );

		if ( empty( $groups ) ) {
			return true;
		}

		// OR logic between groups: At least ONE group must be valid.
		foreach ( $groups as $group ) {
			if ( $this->validate_group( $group ) ) {
				return true;
			}
		}

		// No groups were valid.
		return false;
	}

	/**
	 * Filter coupons array to only include valid ones
	 *
	 * @param array<int, mixed> $coupons Array of coupon codes or IDs.
	 *
	 * @return array<int, mixed> Filtered array of valid coupons.
	 */
	public function filter_valid_coupons( $coupons ) {
		if ( empty( $coupons ) ) {
			return $coupons;
		}

		$valid_coupons = array();

		foreach ( $coupons as $key => $coupon ) {
			$coupon_id = 0;

			// Handle different input formats.
			if ( is_numeric( $coupon ) ) {
				$coupon_id = intval( $coupon );
			} elseif ( is_string( $coupon ) ) {
				// It's a coupon code, get the ID.
				$coupon_obj = new \WC_Coupon( $coupon );
				$coupon_id  = $coupon_obj->get_id();
			} elseif ( is_object( $coupon ) && method_exists( $coupon, 'get_id' ) ) {
				$coupon_id = $coupon->get_id();
			}

			// Check if coupon is valid.
			if ( $coupon_id && $this->is_coupon_valid( $coupon_id ) ) {
				$valid_coupons[ $key ] = $coupon;
			}
		}

		return $valid_coupons;
	}
}
