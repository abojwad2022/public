<?php
/**
 * Public Class
 *
 * @package Power_Coupons
 * @since 1.0.0
 */

namespace Power_Coupons\Public_Folder;

use Power_Coupons\Controllers\Cart_Controller;
use Power_Coupons\Controllers\Display_Controller;
use Power_Coupons\Controllers\Auto_Apply_Controller;
use Power_Coupons\Includes\Power_Coupons_Settings_Helper;
use Power_Coupons\Includes\Traits\Power_Coupons_Singleton;
use Power_Coupons\Controllers\Checkout_Drawer_Controller;
use Power_Coupons\Includes\Power_Coupons_Utilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Power_Coupons_Frontend
 */
class Power_Coupons_Frontend {

	use Power_Coupons_Singleton;

	/**
	 * Cart Controller instance
	 *
	 * @phpstan-ignore-next-line
	 * @var Cart_Controller
	 */
	private $cart_controller;

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	protected function __construct() {
		// Initialize controllers.
		new Cart_Controller();
		Display_Controller::get_instance();
		Auto_Apply_Controller::get_instance();
		Checkout_Drawer_Controller::get_instance();

		// Initialize conditional rules validation.
		Power_Coupons_Frontend_Rules::get_instance();

		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function init_hooks() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public_assets' ) );
	}

	/**
	 * Enqueue public assets
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function enqueue_public_assets() {
		$settings_helper = Power_Coupons_Settings_Helper::get_instance();

		// Check if plugin is enabled.
		if ( ! $settings_helper->is_enabled() ) {
			return;
		}

		wp_enqueue_style(
			'power-coupons-public',
			POWER_COUPONS_URL . 'public/assets/css/frontend.css',
			array(),
			POWER_COUPONS_VERSION
		);

		wp_enqueue_script(
			'power-coupons-public',
			POWER_COUPONS_URL . 'public/assets/js/frontend.js',
			array( 'jquery' ),
			POWER_COUPONS_VERSION,
			true
		);

		$ajax_url = admin_url( 'admin-ajax.php' );

		// Get text settings for JavaScript.
		$text_settings = $settings_helper->get_text_settings();

		// Get general settings that JS needs.
		$general_settings = $settings_helper->get_general_settings();

		wp_localize_script(
			'power-coupons-public',
			'powerCouponsData',
			array(
				'ajaxUrl'                      => array(
					'getCouponsHtml' => add_query_arg( 'action', 'power_coupons_get_coupons_html', $ajax_url ),
					'applyCoupon'    => add_query_arg( 'action', 'power_coupons_apply_coupons', $ajax_url ),
					'removeCoupon'   => add_query_arg( 'action', 'power_coupons_remove_coupon', $ajax_url ),
				),
				'nonce'                        => wp_create_nonce( 'power-coupons-nonce' ),
				'text'                         => array(
					// Success/Info messages.
					'successMessage'          => $text_settings['success_message'] ?? __( 'Coupon applied successfully!', 'power-coupons' ),
					'codeCopiedMessage'       => $text_settings['code_copied_message'] ?? __( 'Coupon code copied!', 'power-coupons' ),
					'couponRemovedMessage'    => $text_settings['coupon_removed_message'] ?? __( 'Coupon removed.', 'power-coupons' ),

					// Error messages.
					'conditionsNotMetMessage' => $text_settings['conditions_not_met_message'] ?? __( 'This coupon cannot be applied to your current cart.', 'power-coupons' ),
					'alreadyAppliedMessage'   => $text_settings['already_applied_message'] ?? __( 'This coupon is already applied.', 'power-coupons' ),
					'genericErrorMessage'     => $text_settings['generic_error_message'] ?? __( 'Sorry, something went wrong. Please try again.', 'power-coupons' ),
					'networkErrorMessage'     => $text_settings['network_error_message'] ?? __( 'Connection error. Please check your internet connection.', 'power-coupons' ),

					// Loading states (for JavaScript).
					'applyingText'            => $text_settings['coupon_applying_text'] ?? __( 'Applying...', 'power-coupons' ),
					'removingText'            => __( 'Removing...', 'power-coupons' ),
					'applyErrorText'          => __( 'Failed to apply coupon. Please try again.', 'power-coupons' ),
					'removeErrorText'         => __( 'Failed to remove coupon. Please try again.', 'power-coupons' ),

					// Button labels.
					'applyButtonText'         => $text_settings['apply_button_text'] ?? __( 'Apply Coupon', 'power-coupons' ),
					'copyCodeButtonText'      => $text_settings['copy_code_button_text'] ?? __( 'Copy Code', 'power-coupons' ),
					'removeButtonText'        => $text_settings['remove_button_text'] ?? __( 'Remove', 'power-coupons' ),
					'viewDetailsText'         => $text_settings['view_details_text'] ?? __( 'View Details', 'power-coupons' ),
				),
				'settings'                     => array(
					'showAppliedCoupons' => $general_settings['show_applied_coupons'] ?? true,
				),
				'reloadPageAfterCouponApplied' => Power_Coupons_Utilities::reload_page_after_coupon_is_applied(),
			)
		);
	}
}

