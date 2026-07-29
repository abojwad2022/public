<?php
/**
 * Cart Model Class
 *
 * @package Power_Coupons
 * @since 1.0.0
 */

namespace Power_Coupons\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Power_Coupons_Cart_Model
 */
class Power_Coupons_Cart_Model {

	/**
	 * Get cart data
	 *
	 * @since 1.0.0
	 * @return array<string, mixed> Cart data.
	 */
	public function get_cart_data() {
		if ( ! class_exists( 'WooCommerce' ) || ! WC()->cart ) {
			return array();
		}

		$cart = WC()->cart;

		return array(
			'total'           => $cart->get_subtotal(),
			'item_count'      => $cart->get_cart_contents_count(),
			'product_ids'     => $this->get_cart_product_ids(),
			'category_ids'    => $this->get_cart_category_ids(),
			'applied_coupons' => $cart->get_applied_coupons(),
		);
	}

	/**
	 * Get cart product IDs
	 *
	 * @since 1.0.0
	 * @return array<int, int> Product IDs.
	 */
	private function get_cart_product_ids() {
		if ( ! WC()->cart ) {
			return array();
		}

		$product_ids = array();
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product_ids[] = $cart_item['product_id'];
			if ( ! empty( $cart_item['variation_id'] ) ) {
				$product_ids[] = $cart_item['variation_id'];
			}
		}

		return array_unique( $product_ids );
	}

	/**
	 * Get cart category IDs
	 *
	 * @since 1.0.0
	 * @return array<int, int> Category IDs.
	 */
	private function get_cart_category_ids() {
		if ( ! WC()->cart ) {
			return array();
		}

		$category_ids = array();
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$terms = wp_get_post_terms( $cart_item['product_id'], 'product_cat', array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) ) {
				$category_ids = array_merge( $category_ids, $terms );
			}
		}

		return array_unique( $category_ids );
	}
}

