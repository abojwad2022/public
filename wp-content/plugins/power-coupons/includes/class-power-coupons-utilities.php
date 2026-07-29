<?php
/**
 * Utilities Class
 *
 * Provides shared utility methods used across the plugin.
 * Eliminates duplicate code and provides a centralized location for common operations.
 *
 * @package Power_Coupons
 * @since 1.0.0
 */

namespace Power_Coupons\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Power_Coupons_Utilities
 *
 * Static utility class providing common helper methods.
 */
class Power_Coupons_Utilities {

	/**
	 * Whether a coupon is currently being applied programmatically rather than by
	 * an explicit user action (e.g. auto-apply, or a PRO offer flow).
	 *
	 * Callers that apply coupons in code should wrap the apply in
	 * `self::$applying_coupon_programmatically = true; ... = false;` so automated
	 * applies stay out of the user-engagement "coupons applied" KPI.
	 *
	 * @since 1.0.4
	 * @var bool
	 */
	public static $applying_coupon_programmatically = false;

	/**
	 * Check if coupon is expired
	 *
	 * Supports both WC_DateTime objects and string dates.
	 *
	 * @param array<string, mixed> $coupon Coupon data array with expiry_date key.
	 * @return bool True if expired, false otherwise.
	 */
	public static function is_coupon_expired( $coupon ) {
		if ( empty( $coupon['expiry_date'] ) ) {
			return false;
		}

		$expiry_date = $coupon['expiry_date'];

		// Handle WC_DateTime objects.
		if ( is_object( $expiry_date ) && $expiry_date instanceof \WC_DateTime ) {
			return $expiry_date->getTimestamp() < time();
		}

		// Handle string dates.
		if ( is_string( $expiry_date ) ) {
			$expiry_timestamp = strtotime( $expiry_date );
			return $expiry_timestamp && $expiry_timestamp < time();
		}

		return false;
	}

	/**
	 * Check if coupon hasn't started yet
	 *
	 * @param array<string, mixed> $coupon Coupon data array with start_date key.
	 * @return bool True if not started, false otherwise.
	 */
	public static function is_coupon_not_started( $coupon ) {
		if ( empty( $coupon['start_date'] ) ) {
			return false;
		}

		$start_timestamp = strtotime( $coupon['start_date'] . ' 00:00:00' );
		return $start_timestamp && time() < $start_timestamp;
	}

	/**
	 * Compare numeric values with operator
	 *
	 * Supports: equal_to, not_equal_to, less_than, less_than_or_equal,
	 * greater_than, greater_than_or_equal
	 *
	 * @param mixed  $actual   The actual value.
	 * @param string $operator The comparison operator.
	 * @param mixed  $expected The expected value.
	 * @return bool True if comparison passes, false otherwise.
	 */
	public static function compare_numeric( $actual, $operator, $expected ) {
		// Convert to numeric if strings.
		$actual   = is_numeric( $actual ) ? (float) $actual : $actual;
		$expected = is_numeric( $expected ) ? (float) $expected : $expected;

		switch ( $operator ) {
			case 'equal_to':
			case 'equals': // Support legacy operator name.
				return $actual === $expected;

			case 'not_equal_to':
			case 'not_equals': // Support legacy operator name.
				return $actual !== $expected;

			case 'less_than':
				return $actual < $expected;

			case 'less_than_or_equal':
				return $actual <= $expected;

			case 'greater_than':
				return $actual > $expected;

			case 'greater_than_or_equal':
				return $actual >= $expected;

			default:
				return true; // Unknown operator always passes.
		}
	}

	/**
	 * Check if WooCommerce is active
	 *
	 * @return bool True if WooCommerce is active.
	 */
	public static function is_woocommerce_active() {
		return class_exists( 'WooCommerce' ) || function_exists( 'WC' );
	}

	/**
	 * Check if WooCommerce cart exists and is not empty
	 *
	 * @return bool True if cart exists and has items.
	 */
	public static function is_cart_available() {
		return self::is_woocommerce_active() && null !== WC()->cart && ! WC()->cart->is_empty();
	}

	/**
	 * Sanitize coupon code
	 *
	 * @param string $code The coupon code to sanitize.
	 * @return string Sanitized coupon code.
	 */
	public static function sanitize_coupon_code( $code ) {
		return sanitize_text_field( strtolower( $code ) );
	}

	/**
	 * Format discount amount for display
	 *
	 * @param float  $amount The discount amount.
	 * @param string $type   The discount type (percent, fixed_cart, fixed_product).
	 * @return string Formatted discount amount.
	 */
	public static function format_discount_amount( $amount, $type ) {
		switch ( $type ) {
			case 'percent':
				return $amount . '%';

			case 'fixed_cart':
			case 'fixed_product':
				return wc_price( $amount );

			default:
				return (string) $amount;
		}
	}

	/**
	 * Get current cart total
	 *
	 * @return float Cart total or 0 if cart not available.
	 */
	public static function get_cart_total() {
		if ( ! self::is_cart_available() ) {
			return 0;
		}

		return (float) WC()->cart->get_cart_contents_total();
	}

	/**
	 * Get current cart item count
	 *
	 * @return int Number of items in cart.
	 */
	public static function get_cart_item_count() {
		if ( ! self::is_cart_available() ) {
			return 0;
		}

		return WC()->cart->get_cart_contents_count();
	}

	/**
	 * Get array of coupon card templates
	 *
	 * Returns filtered array of available coupon card templates with their paths and template tags.
	 * Each template has a path to the template file and an array of supported template tags.
	 *
	 * @since 1.0.0
	 * @param bool   $pre_rendered Whether templates are pre-rendered.
	 * @param string $key Template key identifier.
	 * @return array<string, mixed> Array of template configurations with paths and tags
	 */
	public static function get_coupon_card_templates_array( $pre_rendered = true, $key = '' ) {
		$templates = apply_filters(
			'power_coupons_filter_coupon_card_templates_array',
			[
				'style-1' => [
					'path' => POWER_COUPONS_DIR . 'views/templates/card-style-1.php',
					'tags' => [
						'{power_coupon.code}'        => 'EUSHDKQO',
						'{power_coupon.discount}'    => '$10',
						'{power_coupon.description}' => 'Cart Discount',
						'{power_coupon.status}'      => 'Valid Till: 01 Feb 2022',
					],
				],
				'style-2' => [
					'path' => POWER_COUPONS_DIR . 'views/templates/card-style-2.php',
					'tags' => [
						'{power_coupon.code}'        => 'EUSHDKQO',
						'{power_coupon.discount}'    => '$10',
						'{power_coupon.description}' => 'Cart Discount',
						'{power_coupon.status}'      => 'Valid Till: 01 Feb 2022',
					],
				],
			]
		);

		if ( $pre_rendered ) {
			foreach ( $templates as &$template_args ) {
				$template_args = self::render_coupon_card_template( $template_args, false );
			}
		}

		if ( ! empty( $key ) ) {
			return isset( $templates[ $key ] ) ? $templates[ $key ] : null;
		}

		return $templates;
	}

	/**
	 * Render coupon card template with provided arguments.
	 *
	 * Takes a template configuration array and renders the template file with the provided
	 * tag replacements. Can either echo the rendered content or return it as a string.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $template_args Template arguments including path and template tags.
	 * @param bool                 $echo         Whether to echo the rendered template (default: true).
	 * @return mixed Rendered template string if $echo is false, original args if template not found.
	 */
	public static function render_coupon_card_template( $template_args, $echo = true ) {
		$template_path = isset( $template_args['path'] ) && is_string( $template_args['path'] ) ? $template_args['path'] : '';
		if ( empty( $template_path ) || ! file_exists( $template_path ) ) {
			return $template_args;
		}

		ob_start();
		include $template_path;
		$content = ob_get_clean();

		if ( false === $content ) {
			$content = '';
		}

		$tags         = isset( $template_args['tags'] ) && is_array( $template_args['tags'] ) ? $template_args['tags'] : array();
		$escaped_tags = array_map( 'esc_html', $tags );
		$content      = str_replace( array_keys( $tags ), array_values( $escaped_tags ), $content );

		if ( $echo ) {
			echo wp_kses( $content, self::get_wp_kses_allowed_html_for_svg() );
		}

		return $content;
	}

	/**
	 * Get allowed HTML tags and attributes for SVG content
	 *
	 * Returns an array of allowed HTML tags and their attributes that can be safely
	 * rendered through wp_kses() when displaying SVG content. This includes support for:
	 * - SVG root element and basic attributes
	 * - Path elements for drawing
	 * - Rectangle elements
	 * - Text elements
	 * - Foreign objects for embedding HTML
	 * - Div elements within foreign objects
	 *
	 * @since 1.0.0
	 * @return array<string, array<string, bool>> Array of allowed HTML tags and attributes
	 */
	public static function get_wp_kses_allowed_html_for_svg() {
		return [
			'svg'           => [
				'xmlns'   => true,
				'width'   => true,
				'height'  => true,
				'viewBox' => true,
				'fill'    => true,
			],
			'path'          => [
				'd'                => true,
				'fill'             => true,
				'stroke'           => true,
				'stroke-width'     => true,
				'stroke-linejoin'  => true,
				'stroke-dasharray' => true,
			],
			'rect'          => [
				'x'      => true,
				'y'      => true,
				'width'  => true,
				'height' => true,
				'rx'     => true,
				'fill'   => true,
				'stroke' => true,
			],
			'text'          => [
				'x'           => true,
				'y'           => true,
				'fill'        => true,
				'font-family' => true,
				'font-size'   => true,
				'class'       => true,
				'style'       => true,
			],
			'foreignobject' => [
				'x'      => true,
				'y'      => true,
				'width'  => true,
				'height' => true,
				'style'  => true,
			],
			'div'           => [
				'class' => true,
				'style' => true,
			],
		];
	}

	/**
	 * Helper function for getting formatted price
	 *
	 * @since 1.0.0
	 * @param float $amount Amount.
	 * @return string Formatted price.
	 */
	public static function get_formatted_price( $amount ) {
		$currency     = get_woocommerce_currency_symbol();
		$currency_pos = get_option( 'woocommerce_currency_pos' );

		switch ( $currency_pos ) {
			case 'left':
				return $currency . $amount;
			case 'left_space':
				return $currency . ' ' . $amount;
			case 'right_space':
				return $amount . ' ' . $currency;
			default:
				return $amount . $currency;
		}
	}

	/**
	 * Checks whether or not current viewing page is CartFlows Checkout page.
	 *
	 * @return bool
	 */
	public static function is_cartflows_checkout() {
		if ( function_exists( '_is_wcf_checkout_type' ) ) {
			return _is_wcf_checkout_type();
		}

		return false;
	}

	/**
	 * Determine whether a coupon was created using Power Coupons.
	 *
	 * A coupon qualifies when the store owner has intentionally opted into a
	 * Power Coupons feature on it. Detection is value-based, not presence-based:
	 * `_power_coupon_auto_apply` and `_power_coupon_show_in_slideout` are written
	 * ('yes'/'no') on every coupon saved through the WooCommerce coupon editor,
	 * so only the 'yes' value indicates a deliberate Power Coupons coupon.
	 *
	 * Free coverage: auto-apply, slideout display, and rule conditions. The
	 * `power_coupons_is_power_coupons_coupon` filter lets the PRO plugin augment
	 * detection for its own coupon types (gift cards, BOGO). The filter is a
	 * no-op when PRO is inactive.
	 *
	 * @since 1.0.2
	 * @param \WC_Coupon $coupon WooCommerce coupon object.
	 * @return bool True if the coupon was created using Power Coupons.
	 */
	public static function is_power_coupons_coupon( $coupon ) {
		if ( ! $coupon instanceof \WC_Coupon ) {
			return false;
		}

		$coupon_id = $coupon->get_id();

		if ( $coupon_id <= 0 ) {
			return false;
		}

		$is_power_coupon = 'yes' === get_post_meta( $coupon_id, '_power_coupon_auto_apply', true )
			|| 'yes' === get_post_meta( $coupon_id, '_power_coupon_show_in_slideout', true )
			|| 'yes' === get_post_meta( $coupon_id, '_pc_rule_enable_conditions', true );

		/**
		 * Filter whether a coupon was created using Power Coupons.
		 *
		 * Allows the PRO plugin to mark its own coupon types (gift cards, BOGO)
		 * as Power Coupons coupons. Returning true short-circuits detection.
		 *
		 * @since 1.0.2
		 * @param bool       $is_power_coupon Whether the coupon is a Power Coupons coupon (free detection).
		 * @param \WC_Coupon $coupon          WooCommerce coupon object.
		 */
		return (bool) apply_filters( 'power_coupons_is_power_coupons_coupon', $is_power_coupon, $coupon );
	}

	/**
	 * Whether or not to reload current page after coupon is successfully applied.
	 *
	 * @return bool
	 */
	public static function reload_page_after_coupon_is_applied() {
		$reload = true;

		if ( self::is_cartflows_checkout() ) {
			$reload = false;
		}

		return apply_filters( 'power_coupons_reload_page_after_coupon_is_applied', $reload );
	}
}
