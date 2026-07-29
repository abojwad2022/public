<?php
/**
 * Cart Controller Class
 *
 * @package Power_Coupons
 * @since 1.0.0
 */

namespace Power_Coupons\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Cart_Controller
 */
class Cart_Controller {
	/**
	 * Display Controller instance
	 *
	 * @var Display_Controller
	 */
	private $display_controller;

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->display_controller = Display_Controller::get_instance();

		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function init_hooks() {
		add_action( 'wp_ajax_power_coupons_get_coupons_html', array( $this, 'get_coupons_html' ) );
		add_action( 'wp_ajax_nopriv_power_coupons_get_coupons_html', array( $this, 'get_coupons_html' ) );
		add_action( 'wp_ajax_power_coupons_apply_coupons', array( $this, 'apply_coupon' ) );
		add_action( 'wp_ajax_nopriv_power_coupons_apply_coupons', array( $this, 'apply_coupon' ) );
		add_action( 'wp_ajax_power_coupons_remove_coupon', array( $this, 'remove_coupon' ) );
		add_action( 'wp_ajax_nopriv_power_coupons_remove_coupon', array( $this, 'remove_coupon' ) );

		// Validate coupon start date.
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate_coupon_start_date' ), 10, 2 );
	}

	/**
	 * Get coupons HTML via AJAX.
	 *
	 * @return void
	 */
	public function get_coupons_html() {
		check_ajax_referer( 'power-coupons-nonce', 'nonce' );

		$context     = ! empty( $_GET['context'] ) ? sanitize_text_field( wp_unslash( $_GET['context'] ) ) : 'ajax';
		$coupon_code = ! empty( $_GET['couponCode'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['couponCode'] ) ) ) : '';

		$coupon_id = 0;
		if ( ! empty( $coupon_code ) ) {
			$coupon_id = wc_get_coupon_id_by_code( $coupon_code );
		}

		ob_start();
		$this->display_controller->render_coupon_list( $context, $coupon_id );
		$html = ob_get_clean();

		wp_send_json( compact( 'coupon_id', 'coupon_code', 'html' ) );
	}

	/**
	 * Apply coupon via AJAX
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function apply_coupon() {
		check_ajax_referer( 'power-coupons-nonce', 'nonce' );

		$coupon_code = isset( $_POST['coupon_code'] ) ? sanitize_text_field( wp_unslash( $_POST['coupon_code'] ) ) : '';

		if ( empty( $coupon_code ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid coupon code.', 'power-coupons' ) ) );
		}

		$wc_cart = WC()->cart;
		if ( ! $wc_cart instanceof \WC_Cart ) {
			wp_send_json_error( array( 'message' => __( 'Cart not found.', 'power-coupons' ) ) );
		}

		$billing_email = isset( $_POST['billing_email'] ) ? sanitize_email( wp_unslash( $_POST['billing_email'] ) ) : '';

		if ( ! empty( $billing_email ) ) {
			// Set billing email.
			$wc_cart->get_customer()->set_billing_email( $billing_email );
		}

		$result = $wc_cart->apply_coupon( $coupon_code );

		if ( $result ) {
			// Analytics flag-setter: first coupon applied via Power Coupons.
			if ( ! get_option( 'power_coupons_first_coupon_applied' ) ) {
				update_option( 'power_coupons_first_coupon_applied', true );
			}

			wp_send_json_success( array( 'message' => __( 'Coupon applied successfully.', 'power-coupons' ) ) );
		}

		$error_message = wc_get_notices( 'error' );
		wc_clear_notices();
		wp_send_json_error( array( 'message' => ! empty( $error_message ) ? wp_strip_all_tags( html_entity_decode( $error_message[0]['notice'] ) ) : __( 'Failed to apply coupon.', 'power-coupons' ) ) );
	}

	/**
	 * Remove coupon via AJAX
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function remove_coupon() {
		check_ajax_referer( 'power-coupons-nonce', 'nonce' );

		$coupon_code = isset( $_POST['coupon_code'] ) ? sanitize_text_field( wp_unslash( $_POST['coupon_code'] ) ) : '';

		if ( empty( $coupon_code ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid coupon code.', 'power-coupons' ) ) );
		}

		$wc_cart = WC()->cart;
		if ( ! $wc_cart instanceof \WC_Cart ) {
			wp_send_json_error( array( 'message' => __( 'Cart not found.', 'power-coupons' ) ) );
		}

		// Check if coupon has auto-apply enabled.
		$coupon = new \WC_Coupon( $coupon_code );
		if ( $coupon->get_id() && 'yes' === get_post_meta( $coupon->get_id(), '_power_coupon_auto_apply', true ) ) {
			wp_send_json_error( array( 'message' => __( 'This coupon is auto-applied and cannot be removed.', 'power-coupons' ) ) );
		}

		$result = $wc_cart->remove_coupon( $coupon_code );

		if ( $result ) {
			wp_send_json_success( array( 'message' => __( 'Coupon removed successfully.', 'power-coupons' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to remove coupon.', 'power-coupons' ) ) );
		}
	}

	/**
	 * Validate coupon start date
	 *
	 * @param bool       $valid Coupon validity.
	 * @param \WC_Coupon $coupon Coupon object.
	 * @since 1.0.0
	 * @return bool
	 * @throws \Exception If coupon hasn't started yet.
	 */
	public function validate_coupon_start_date( $valid, $coupon ) {
		$start_date = get_post_meta( $coupon->get_id(), '_power_coupon_start_date', true );

		if ( empty( $start_date ) ) {
			return $valid;
		}

		$start_timestamp = strtotime( $start_date . ' 00:00:00' );

		if ( $start_timestamp && time() < $start_timestamp ) {
			throw new \Exception(
				sprintf(
					/* translators: %s: start date */
					esc_html__( 'Sorry, this coupon is only available after %s', 'power-coupons' ),
					esc_html( date_i18n( wc_date_format(), $start_timestamp ) )
				),
				109
			);
		}

		return $valid;
	}
}
