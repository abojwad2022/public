<?php
/**
 * Auto Apply Coupon Controller
 *
 * @package Power_Coupons
 * @since 1.0.0
 */

namespace Power_Coupons\Controllers;

use Power_Coupons\Includes\Power_Coupons_Settings_Helper;
use Power_Coupons\Includes\Power_Coupons_Utilities;
use Power_Coupons\Includes\Traits\Power_Coupons_Singleton;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Auto_Apply_Controller
 */
class Auto_Apply_Controller {

	use Power_Coupons_Singleton;

	/**
	 * Flag to prevent multiple executions per request
	 *
	 * @var bool
	 */
	private $already_applied = false;

	/**
	 * Constructor
	 */
	protected function __construct() {

		// Check if guest users can see coupons.
		if ( ! is_user_logged_in() && ! Power_Coupons_Settings_Helper::get_instance()->enable_for_guests() ) {
			return;
		}

		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'auto_apply_coupons' ) );
		add_action( 'woocommerce_after_calculate_totals', array( $this, 'auto_apply_coupons' ), 1000 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'auto_apply_coupons' ), 0 );

		add_action( 'wp_enqueue_scripts', array( $this, 'hide_auto_applied_coupons_wc_remove_btn' ), 20 );
	}

	/**
	 * Hides remove button from the auto applied coupons.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function hide_auto_applied_coupons_wc_remove_btn() {
		$auto_coupons = $this->get_auto_apply_coupons();

		if ( empty( $auto_coupons ) ) {
			return;
		}

		$selectors = [];

		// Generate css selectors for auto-applied coupons.
		foreach ( $auto_coupons as $coupon_code ) {
			// Sanitize for CSS context — esc_attr() only escapes HTML entities, not CSS-special chars like ] { }.
			$safe_code = preg_replace( '/[^a-zA-Z0-9_\-]/', '', $coupon_code );
			if ( empty( $safe_code ) ) {
				continue;
			}
			$selectors[] = sprintf(
				'.woocommerce-remove-coupon[data-coupon="%1$s"], button.wc-block-components-chip__remove[aria-label*="%1$s"]',
				$safe_code
			);
		}

		// Print the dynamic css to hide the auto-applied coupons remove link.
		wp_add_inline_style(
			'power-coupons-public',
			implode( ', ', $selectors ) . '{ display: none !important; }'
		);
	}

	/**
	 * Auto apply coupons
	 *
	 * @return void
	 */
	public function auto_apply_coupons(): void {
		// Prevent multiple executions per request.
		if ( $this->already_applied ) {
			return;
		}

		$cart = WC()->cart;
		if ( ! $cart instanceof \WC_Cart || $cart->is_empty() ) {
			return;
		}

		// Mark as applied for this request.
		$this->already_applied = true;

		$auto_coupons = $this->get_auto_apply_coupons();

		// Flag these as programmatic applies so the analytics KPI doesn't count
		// auto-applied coupons as user-initiated engagement.
		Power_Coupons_Utilities::$applying_coupon_programmatically = true;

		try {
			foreach ( $auto_coupons as $coupon_code ) {
				if ( ! $cart->has_discount( $coupon_code ) ) {
					if ( $this->can_apply_coupon( $coupon_code ) ) {
						$cart->apply_coupon( $coupon_code );
					}
				}
			}
		} finally {
			Power_Coupons_Utilities::$applying_coupon_programmatically = false;
		}
	}

	/**
	 * Get auto apply coupons
	 *
	 * @return array<int, string>
	 */
	private function get_auto_apply_coupons(): array {
		$args = array(
			'post_type'      => 'shop_coupon',
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => '_power_coupon_auto_apply',
					'value' => 'yes',
				),
			),
		);

		$coupons_query = new \WP_Query( $args );
		$coupon_codes  = array();

		if ( $coupons_query->have_posts() ) {
			while ( $coupons_query->have_posts() ) {
				$coupons_query->the_post();
				$coupon         = new \WC_Coupon( get_the_ID() );
				$coupon_codes[] = $coupon->get_code();
			}
			wp_reset_postdata();
		}

		return $coupon_codes;
	}

	/**
	 * Check if coupon can be applied
	 *
	 * @param string $coupon_code Coupon code.
	 * @return bool
	 */
	private function can_apply_coupon( $coupon_code ) {
		$coupon = new \WC_Coupon( $coupon_code );

		if ( ! $coupon->is_valid() ) {
			return false;
		}

		// Check start date.
		$start_date = get_post_meta( $coupon->get_id(), '_power_coupon_start_date', true );
		if ( ! empty( $start_date ) ) {
			$start_timestamp = strtotime( $start_date . ' 00:00:00' );
			if ( $start_timestamp && time() < $start_timestamp ) {
				return false;
			}
		}

		// Check minimum amount.
		$minimum_amount = $coupon->get_minimum_amount();
		if ( $minimum_amount > 0 && WC()->cart->get_subtotal() < $minimum_amount ) {
			return false;
		}

		// Check maximum amount.
		$maximum_amount = $coupon->get_maximum_amount();
		if ( $maximum_amount > 0 && WC()->cart->get_subtotal() > $maximum_amount ) {
			return false;
		}

		return true;
	}
}
