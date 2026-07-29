<?php
/**
 * Coupon Usage Controller
 *
 * Detects when a coupon created using Power Coupons is used in a placed order
 * and records a one-time flag marking the store's first real redemption. This
 * flag gates value-based prompts such as the 5-star review notice.
 *
 * @package Power_Coupons
 * @since 1.0.2
 */

namespace Power_Coupons\Controllers;

use Power_Coupons\Includes\Power_Coupons_Utilities;
use Power_Coupons\Includes\Traits\Power_Coupons_Singleton;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Coupon_Usage_Controller
 *
 * @since 1.0.2
 */
class Coupon_Usage_Controller {

	use Power_Coupons_Singleton;

	/**
	 * Option name storing the timestamp of the first Power Coupons redemption.
	 *
	 * @since 1.0.2
	 * @var string
	 */
	const FIRST_REDEEMED_OPTION = 'power_coupons_first_coupon_redeemed';

	/**
	 * Constructor
	 *
	 * @since 1.0.2
	 */
	protected function __construct() {
		// Classic checkout.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'maybe_record_redemption' ), 10, 1 );
		// Store API / block checkout.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'maybe_record_redemption' ), 10, 1 );
	}

	/**
	 * Record the first redemption of a Power Coupons coupon on a placed order.
	 *
	 * Idempotent: returns early once the flag is set and never re-writes it.
	 * Only inspects the order's applied coupon codes — no extra queries.
	 *
	 * @since 1.0.2
	 * @param int|\WC_Order $order Order ID (classic) or order object (Store API).
	 * @return void
	 */
	public function maybe_record_redemption( $order ) {
		// Already recorded — nothing to do.
		if ( get_option( self::FIRST_REDEEMED_OPTION ) ) {
			return;
		}

		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$coupon_codes = $order->get_coupon_codes();

		if ( empty( $coupon_codes ) ) {
			return;
		}

		foreach ( $coupon_codes as $code ) {
			$coupon = new \WC_Coupon( $code );

			if ( Power_Coupons_Utilities::is_power_coupons_coupon( $coupon ) ) {
				update_option( self::FIRST_REDEEMED_OPTION, time() );
				break;
			}
		}
	}
}
