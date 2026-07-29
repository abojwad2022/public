<?php
/**
 * Rule Model Class
 *
 * @package Power_Coupons
 * @since 1.0.0
 */

namespace Power_Coupons\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Power_Coupons_Rule_Model
 */
class Power_Coupons_Rule_Model {

	/**
	 * Get rules for coupon
	 *
	 * @param int $coupon_id Coupon ID.
	 * @since 1.0.0
	 * @return array<string, mixed> Array of rules.
	 */
	public function get_by_coupon( $coupon_id ) {
		$conditions_json = get_post_meta( $coupon_id, '_pc_rule_conditions', true );

		if ( empty( $conditions_json ) ) {
			return array();
		}

		$conditions = json_decode( is_string( $conditions_json ) ? $conditions_json : '', true );

		return array(
			'coupon_id'  => $coupon_id,
			'conditions' => $conditions,
			'logic'      => ! empty( get_post_meta( $coupon_id, '_pc_rule_logic', true ) ) ? get_post_meta( $coupon_id, '_pc_rule_logic', true ) : 'AND',
		);
	}

	/**
	 * Save rules for coupon
	 *
	 * @param int                  $coupon_id Coupon ID.
	 * @param array<string, mixed> $rules Rules data.
	 * @since 1.0.0
	 * @return bool
	 */
	public function save( $coupon_id, $rules ) {
		if ( isset( $rules['conditions'] ) ) {
			update_post_meta( $coupon_id, '_pc_rule_conditions', wp_json_encode( $rules['conditions'] ) );
		}

		if ( isset( $rules['logic'] ) ) {
			$logic_val = is_string( $rules['logic'] ) ? $rules['logic'] : '';
			update_post_meta( $coupon_id, '_pc_rule_logic', sanitize_text_field( $logic_val ) );
		}

		return true;
	}
}

