<?php
/**
 * Coupon minter for the create_coupon rule action.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Rules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mints a single-use, email-restricted WooCommerce coupon from rule-action
 * parameters. All coupon settings are fixed server-side from the (admin-authored)
 * rule — no customer input reaches them. Mirrors the store's WELCOME10 pattern.
 */
final class ActionCouponFactory {

	/**
	 * Create a coupon for a user.
	 *
	 * @param int   $user_id User id.
	 * @param array $params  { discount_type: percent|fixed_cart, amount, expiry_days,
	 *                        free_shipping, prefix }.
	 * @return array{ok:bool,code:string,id:int}
	 */
	public function create( int $user_id, array $params ): array {
		if ( ! class_exists( 'WC_Coupon' ) ) {
			return array( 'ok' => false, 'code' => '', 'id' => 0 );
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array( 'ok' => false, 'code' => '', 'id' => 0 );
		}

		$prefix = sanitize_key( (string) ( $params['prefix'] ?? 'yz-rule' ) );
		$code   = $this->unique_code( $prefix );

		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_individual_use( true );
		$coupon->set_usage_limit( 1 );
		$coupon->set_usage_limit_per_user( 1 );
		$coupon->set_email_restrictions( array( $user->user_email ) );

		$type = ( 'fixed_cart' === ( $params['discount_type'] ?? 'percent' ) ) ? 'fixed_cart' : 'percent';
		$amount = max( 0, (float) ( $params['amount'] ?? 0 ) );
		if ( 'percent' === $type ) {
			$amount = min( 100, $amount );
		}
		$coupon->set_discount_type( $type );
		$coupon->set_amount( $amount );

		if ( ! empty( $params['free_shipping'] ) ) {
			$coupon->set_free_shipping( true );
		}

		$expiry_days = max( 1, (int) ( $params['expiry_days'] ?? 30 ) );
		$coupon->set_date_expires( strtotime( "+{$expiry_days} days" ) );
		$coupon->set_description( __( 'Issued by a Yazan reward rule.', 'yazan-rewards' ) );

		$coupon->save();

		return array( 'ok' => $coupon->get_id() > 0, 'code' => $code, 'id' => (int) $coupon->get_id() );
	}

	/**
	 * A unique coupon code.
	 *
	 * @param string $prefix Prefix.
	 * @return string
	 */
	private function unique_code( string $prefix ): string {
		do {
			$code = strtoupper( $prefix . '-' . wp_generate_password( 6, false, false ) );
		} while ( function_exists( 'wc_get_coupon_id_by_code' ) && wc_get_coupon_id_by_code( $code ) );
		return $code;
	}
}
