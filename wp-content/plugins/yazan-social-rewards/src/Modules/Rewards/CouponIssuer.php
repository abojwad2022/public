<?php
/**
 * Coupon reward issuer.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Rewards;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Delivers a redeemed reward as a single-use WooCommerce coupon, restricted to
 * the redeeming customer's email. All coupon parameters are fixed server-side —
 * no customer input reaches the coupon settings (mirrors the store's existing
 * WELCOME10 issuance pattern).
 */
final class CouponIssuer implements RewardIssuerInterface {

	/**
	 * Issue a coupon for a reward.
	 *
	 * @param int    $user_id User id.
	 * @param Reward $reward  Reward.
	 * @return array{ok:bool,type:string,ref:string,code:string}
	 */
	public function issue( int $user_id, Reward $reward ): array {
		if ( ! class_exists( 'WC_Coupon' ) ) {
			return array( 'ok' => false, 'type' => 'coupon', 'ref' => '', 'code' => '' );
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array( 'ok' => false, 'type' => 'coupon', 'ref' => '', 'code' => '' );
		}

		$code = $this->unique_code();

		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_individual_use( true );
		$coupon->set_usage_limit( 1 );
		$coupon->set_usage_limit_per_user( 1 );
		$coupon->set_email_restrictions( array( $user->user_email ) );
		$coupon->set_exclude_sale_items( false );

		$expiry_days = max( 1, (int) ( $reward->value['expiry_days'] ?? 30 ) );
		$coupon->set_date_expires( strtotime( "+{$expiry_days} days" ) );

		if ( Reward::TYPE_FREE_SHIPPING === $reward->type ) {
			$coupon->set_discount_type( 'fixed_cart' );
			$coupon->set_amount( 0 );
			$coupon->set_free_shipping( true );
		} elseif ( Reward::TYPE_FREE_PRODUCT === $reward->type ) {
			// 100% off, restricted to the named product(s), limited to their count —
			// so only those products become free.
			$product_ids = array_values( array_filter( array_map( 'absint', (array) ( $reward->value['product_ids'] ?? array() ) ) ) );
			$coupon->set_discount_type( 'percent' );
			$coupon->set_amount( 100 );
			if ( $product_ids ) {
				$coupon->set_product_ids( $product_ids );
				$coupon->set_limit_usage_to_x_items( (int) ( $reward->value['qty'] ?? count( $product_ids ) ) );
			}
		} else {
			$discount_type = ( 'percent' === ( $reward->value['discount_type'] ?? 'percent' ) ) ? 'percent' : 'fixed_cart';
			$amount        = max( 0, (float) ( $reward->value['amount'] ?? 0 ) );
			if ( 'percent' === $discount_type ) {
				$amount = min( 100, $amount ); // Never exceed 100%.
			}
			$coupon->set_discount_type( $discount_type );
			$coupon->set_amount( $amount );
		}

		$coupon->set_description(
			sprintf(
				/* translators: %s: reward title. */
				__( 'Reward redemption: %s', 'yazan-rewards' ),
				$reward->title
			)
		);

		$coupon->save();

		return array(
			'ok'   => $coupon->get_id() > 0,
			'type' => 'coupon',
			'ref'  => (string) $coupon->get_id(),
			'code' => $code,
		);
	}

	/**
	 * A unique, human-typable coupon code.
	 *
	 * @return string
	 */
	private function unique_code(): string {
		do {
			$code = 'YZ-RDM-' . strtoupper( wp_generate_password( 6, false, false ) );
		} while ( function_exists( 'wc_get_coupon_id_by_code' ) && wc_get_coupon_id_by_code( $code ) );

		return $code;
	}
}
