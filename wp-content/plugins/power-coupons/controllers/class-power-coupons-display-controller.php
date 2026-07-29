<?php
/**
 * Coupon Display Controller
 *
 * @package Power_Coupons
 * @since 1.0.0
 */

namespace Power_Coupons\Controllers;

use Power_Coupons\Public_Folder\Power_Coupons_Frontend_Rules;
use Power_Coupons\Includes\Power_Coupons_Settings_Helper;
use Power_Coupons\Includes\Power_Coupons_Utilities;
use Power_Coupons\Includes\Traits\Power_Coupons_Singleton;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Display_Controller
 */
class Display_Controller {

	use Power_Coupons_Singleton;

	/**
	 * Flag to prevent multiple displays
	 *
	 * @var bool
	 */
	private $displayed = false;

	/**
	 * Settings Helper instance
	 *
	 * @var Power_Coupons_Settings_Helper
	 */
	private $settings_helper;

	/**
	 * Constructor
	 */
	protected function __construct() {
		$this->settings_helper = Power_Coupons_Settings_Helper::get_instance();
		$this->init_hooks();
	}

	/**
	 * Initialize hooks based on settings
	 *
	 * @return void
	 */
	private function init_hooks() {
		// Only initialize if plugin is enabled.
		if ( ! $this->settings_helper->is_enabled() ) {
			return;
		}

		// Add shortcode for manual placement.
		add_shortcode( 'power_coupons', array( $this, 'shortcode_display_coupons' ) );

		// Hide WooCommerce coupon field if enabled.
		if ( $this->settings_helper->get( 'general', 'hide_wc_coupon_field', false ) ) {
			add_filter( 'woocommerce_coupons_enabled', '__return_false' );
		}
	}

	/**
	 * Register cart display hook based on position
	 * Used dynamically by init_hooks method
	 *
	 * @phpstan-ignore-next-line
	 * @param string $position Cart display position.
	 * @return void
	 * @phpstan-ignore method.unused
	 */
	private function register_cart_hook( $position ) {
		$hook_map = array(
			'before_cart'       => 'woocommerce_before_cart',
			'before_cart_table' => 'woocommerce_before_cart_table',
			'after_cart_table'  => 'woocommerce_after_cart_table',
			'before_totals'     => 'woocommerce_before_cart_totals',
			'after_totals'      => 'woocommerce_after_cart_totals',
			'after_cart'        => 'woocommerce_after_cart',
		);

		$hook = $hook_map[ $position ] ?? 'woocommerce_before_cart_table';
		add_action( $hook, array( $this, 'display_coupons_on_cart' ), 10 );
	}

	/**
	 * Register checkout display hook based on position
	 * Used dynamically by init_hooks method
	 *
	 * @phpstan-ignore-next-line
	 * @param string $position Checkout display position.
	 * @return void
	 * @phpstan-ignore method.unused
	 */
	private function register_checkout_hook( $position ) {
		$hook_map = array(
			'before_checkout_form'     => 'woocommerce_before_checkout_form',
			'after_checkout_form'      => 'woocommerce_after_checkout_form',
			'before_checkout_billing'  => 'woocommerce_before_checkout_billing_form',
			'after_checkout_billing'   => 'woocommerce_after_checkout_billing_form',
			'before_checkout_shipping' => 'woocommerce_before_checkout_shipping_form',
			'after_checkout_shipping'  => 'woocommerce_after_checkout_shipping_form',
			'before_order_review'      => 'woocommerce_checkout_before_order_review',
			'after_order_review'       => 'woocommerce_checkout_after_order_review',
		);

		$hook = $hook_map[ $position ] ?? 'woocommerce_before_checkout_form';
		add_action( $hook, array( $this, 'display_coupons_on_checkout' ), 5 );
	}

	/**
	 * Shortcode to display coupons
	 *
	 * @param array<string, mixed> $attrs Shortcode attributes.
	 * @return string
	 */
	public function shortcode_display_coupons( $attrs ) {
		$raw_id    = isset( $attrs['id'] ) ? $attrs['id'] : 0;
		$coupon_id = is_numeric( $raw_id ) ? absint( $raw_id ) : 0;

		ob_start();
		$this->render_coupon_list( 'shortcode', $coupon_id );
		$output = ob_get_clean();
		return false !== $output ? $output : '';
	}

	/**
	 * Display coupons on cart page
	 *
	 * @param bool $force Force display even if already displayed.
	 * @return void
	 */
	public function display_coupons_on_cart( $force = false ) {
		// For WooCommerce Blocks, we want to always render, so check force flag.
		if ( ! $force && $this->displayed ) {
			return;
		}

		if ( ! $force ) {
			$this->displayed = true;
		}

		$this->render_coupon_list( 'cart' );
	}

	/**
	 * Display coupons on checkout page.
	 *
	 * @return void
	 */
	public function display_coupons_on_checkout() {
		$this->render_coupon_list( 'checkout' );
	}

	/**
	 * Display coupons on my account page
	 *
	 * @return void
	 */
	public function display_coupons_on_my_account() {
		$this->render_coupon_list( 'my-account' );
	}

	/**
	 * Render coupon list
	 *
	 * @param string $context Context (cart, checkout, my-account).
	 * @param int    $coupon_id Coupon ID (optional).
	 * @return void
	 */
	public function render_coupon_list( $context, $coupon_id = 0 ) {
		// Check if plugin is enabled.
		if ( ! $this->settings_helper->is_enabled() ) {
			return;
		}

		// Check if guest users can see coupons.
		if ( ! is_user_logged_in() && ! $this->settings_helper->enable_for_guests() ) {
			return;
		}

		// Check context-specific display settings.
		if ( 'cart' === $context && ! $this->settings_helper->should_show_on_cart() ) {
			return;
		}

		if ( 'checkout' === $context && ! $this->settings_helper->should_show_on_checkout() ) {
			return;
		}

		$coupons = $this->get_available_coupons( $coupon_id );

		if ( empty( $coupons ) ) {
			return;
		}

		// Analytics flag-setter: first coupon displayed.
		if ( ! get_option( 'power_coupons_first_coupon_displayed' ) ) {
			update_option( 'power_coupons_first_coupon_displayed', array( 'context' => $context ) );
		}

		$all_coupons = array();

		foreach ( $coupons as $coupon ) {
			if ( ! is_array( $coupon ) ) {
				continue;
			}
			if ( Power_Coupons_Utilities::is_coupon_not_started( $coupon ) || Power_Coupons_Utilities::is_coupon_expired( $coupon ) ) {
				continue; // Skip coupons that haven't started yet.
			}

			$all_coupons[] = $coupon;
		}

		// Get settings for template.
		$text_settings           = $this->settings_helper->get_text_settings();
		$general_settings        = $this->settings_helper->get_general_settings();
		$coupon_styling_settings = $this->settings_helper->get_coupon_styling_settings();

		include \POWER_COUPONS_DIR . 'views/coupon-list.php';
	}

	/**
	 * Get available coupons
	 *
	 * @param int $coupon_id Optional specific coupon ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_available_coupons( $coupon_id = 0 ) {
		static $caches = array();

		// Check cache first.
		$cache_key = 'pc_available_coupons_' . $coupon_id;
		$cached    = ! empty( $caches[ $cache_key ] ) ? $caches[ $cache_key ] : false;

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$args = array(
			'post_type'      => 'shop_coupon',
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => 'discount_type',
					'value'   => 'power_coupons_bogo',
					'compare' => '!=',
				),
				// Exclude gift card coupons — they are personal, recipient-specific codes.
				array(
					'key'     => '_power_coupon_gift_card',
					'compare' => 'NOT EXISTS',
				),
			),
		);

		if ( $coupon_id ) {
			$args['p']              = $coupon_id;
			$args['posts_per_page'] = 1;
		}

		$coupon_ids = get_posts( $args );

		if ( empty( $coupon_ids ) ) {
			return array();
		}

		$general_settings = $this->settings_helper->get_general_settings();

		$show_applied_coupons = ! empty( $general_settings['show_applied_coupons'] );

		// Bulk fetch all meta at once to avoid N+1 queries.
		update_meta_cache( 'post', $coupon_ids );

		$coupons = array();

		// Get the rules validator instance.
		$rules_validator = Power_Coupons_Frontend_Rules::get_instance();

		foreach ( $coupon_ids as $id ) {
			$coupon = new \WC_Coupon( $id );

			$code = $coupon->get_code();

			$is_applied = $this->is_coupon_applied( $code );

			if ( ! $show_applied_coupons && $is_applied ) {
				// Hide the applied coupons if show applied coupons setting is disabled.
				continue;
			}

			// Only show coupons explicitly enabled for slideout display.
			if ( 'yes' !== get_post_meta( $id, '_power_coupon_show_in_slideout', true ) ) {
				continue;
			}

			// Check if coupon meets conditional rules (if enabled).
			// Invalid coupons are hidden from display.
			if ( ! $rules_validator->is_coupon_valid( $id ) ) {
				continue; // Skip this coupon - it doesn't meet the rules.
			}

			$code          = $coupon->get_code();
			$coupon_type   = $coupon->get_discount_type();
			$coupon_expiry = $coupon->get_date_expires();

			$coupons[] = array(
				'id'          => $id,
				'code'        => $code,
				'description' => $coupon->get_description(),
				'amount'      => $coupon->get_amount(),
				'type'        => $coupon_type,
				'type_text'   => $this->get_coupon_type_text( $coupon_type ),
				'expiry_date' => ! empty( $coupon_expiry ) ? $coupon_expiry : __( 'NA', 'power-coupons' ),
				'auto_apply'  => get_post_meta( $id, '_power_coupon_auto_apply', true ),
				'start_date'  => get_post_meta( $id, '_power_coupon_start_date', true ),
				'is_applied'  => $is_applied,
			);
		}

		/**
		 * Filter the available coupons before display.
		 *
		 * @since 1.0.1
		 *
		 * @param array  $coupons Array of coupon data arrays.
		 * @param string $context Display context — 'coupon_list'.
		 */
		$coupons = apply_filters( 'power_coupons_available_coupons', $coupons, 'coupon_list' );

		$caches[ $cache_key ] = $coupons;

		return $coupons;
	}

	/**
	 * Check if coupon is applied
	 *
	 * @param string $coupon_code Coupon code.
	 * @return bool
	 */
	private function is_coupon_applied( $coupon_code ) {
		$cart = WC()->cart;
		return $cart instanceof \WC_Cart && $cart->has_discount( $coupon_code );
	}

	/**
	 * Get human-readable text for coupon type.
	 *
	 * @param string $type Coupon discount type (e.g., 'percent', 'fixed_cart', 'fixed_product').
	 * @return string Localized type description, or empty string if unknown.
	 */
	private function get_coupon_type_text( $type = '' ) {
		$type_text = '';

		if ( empty( $type ) ) {
			return $type_text;
		}

		switch ( $type ) {
			case 'percent':
				$type_text = __( 'Percent Discount', 'power-coupons' );
				break;

			case 'fixed_cart':
				$type_text = __( 'Cart Discount', 'power-coupons' );
				break;

			case 'fixed_product':
				$type_text = __( 'Product Discount', 'power-coupons' );
				break;

			default:
				$type_text = '';
				break;
		}

		return $type_text;
	}
}
