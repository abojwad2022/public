<?php
/**
 * Settings Helper Class
 *
 * Centralized settings retrieval with caching and default values
 *
 * @package Power_Coupons
 * @since 1.0.0
 */

namespace Power_Coupons\Includes;

use Power_Coupons\Includes\Traits\Power_Coupons_Singleton;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Power_Coupons_Settings_Helper
 */
class Power_Coupons_Settings_Helper {

	use Power_Coupons_Singleton;

	/**
	 * Get default settings.
	 *
	 * @param bool $translate Whether to apply translations to text strings.
	 * @return array<string, mixed>
	 */
	public static function get_default_settings( $translate = false ) {
		$defaults = array(
			'general'           => array(
				'enable_plugin'             => true,
				'show_on_cart'              => true,
				'show_on_checkout'          => true,
				'enable_for_guests'         => true,
				'checkout_display_position' => 'before_checkout_form',
				'hide_wc_coupon_field'      => false,
				'show_applied_coupons'      => true,
				'show_expiry_info'          => true,
				'coupon_display_mode'       => 'drawer',
				'enable_usage_tracking'     => false,
			),
			'coupon_styling'    => array(
				'coupon_style' => 'style-1',
			),
			'text'              => array(
				'drawer_heading'       => 'Available Coupons',
				'trigger_button_label' => 'View Available Coupons',
				'coupon_applying_text' => 'Applying...',
				'coupon_applied_text'  => 'Applied',
				'no_coupons_text'      => 'No coupons available at this time.',
				'coupons_loading_text' => 'Loading coupons...',
			),
			'cart_progress_bar' => array(
				'enable'                => false,
				'bar_color'             => '#f97316',
				'bar_bg_color'          => '#e5e7eb',
				'success_color'         => '#16a34a',
				'animate'               => true,
				'free_shipping_sources' => array(),
			),
		);

		// Apply translations if requested and if we're past the init hook.
		if ( $translate && did_action( 'init' ) ) {
			$defaults['text'] = array(
				'drawer_heading'       => esc_html__( 'Available Coupons', 'power-coupons' ),
				'trigger_button_label' => esc_html__( 'View Available Coupons', 'power-coupons' ),
				'coupon_applying_text' => esc_html__( 'Applying...', 'power-coupons' ),
				'coupon_applied_text'  => esc_html__( 'Applied', 'power-coupons' ),
				'no_coupons_text'      => esc_html__( 'No coupons available at this time.', 'power-coupons' ),
				'coupons_loading_text' => esc_html__( 'Loading coupons...', 'power-coupons' ),
			);
		}

		return $defaults;
	}

	/**
	 * Settings cache
	 *
	 * @var array<string, mixed>|null
	 */
	private $settings = null;

	/**
	 * Cache key for transient
	 *
	 * @var string
	 */
	const CACHE_KEY = 'power_coupons_settings_cache';

	/**
	 * Constructor
	 */
	protected function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 *
	 * @return void
	 */
	private function init_hooks() {
		// Clear cache when settings are updated.
		add_action( 'update_option_power_coupons_settings', array( $this, 'clear_cache' ) );
	}

	/**
	 * Get all settings
	 *
	 * @return array<string, mixed>
	 */
	public function get_all_settings(): array {
		if ( null === $this->settings ) {
			$this->settings = $this->load_settings();
		}
		return $this->settings;
	}

	/**
	 * Get general settings
	 *
	 * @return array<string, mixed>
	 */
	public function get_general_settings(): array {
		$settings = $this->get_all_settings();
		/**
		 * Type assertion for settings value.
		 *
		 * @var mixed $general
		 */
		$general = $settings['general'] ?? array();
		return is_array( $general ) ? $general : array();
	}

	/**
	 * Get coupon styling settings
	 *
	 * @return array<string, mixed>
	 */
	public function get_coupon_styling_settings(): array {
		$settings = $this->get_all_settings();
		/**
		 * Type assertion for settings value.
		 *
		 * @var mixed $styling
		 */
		$styling = $settings['coupon_styling'] ?? array();
		return is_array( $styling ) ? $styling : array();
	}

	/**
	 * Get text settings
	 *
	 * @return array<string, mixed>
	 */
	public function get_text_settings(): array {
		$settings = $this->get_all_settings();
		/**
		 * Type assertion for settings value.
		 *
		 * @var mixed $text
		 */
		$text = $settings['text'] ?? array();
		return is_array( $text ) ? $text : array();
	}

	/**
	 * Get individual setting with default
	 *
	 * @param string $section Section name (general, display, text, etc.).
	 * @param string $key Setting key.
	 * @param mixed  $default Default value if not set.
	 * @return mixed
	 */
	public function get( $section, $key, $default = '' ) {
		$settings = $this->get_all_settings();
		if ( ! isset( $settings[ $section ] ) || ! is_array( $settings[ $section ] ) ) {
			return $default;
		}
		return $settings[ $section ][ $key ] ?? $default;
	}

	/**
	 * Check if plugin is enabled
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return (bool) $this->get( 'general', 'enable_plugin', true );
	}

	/**
	 * Check if should show on cart
	 *
	 * @return bool
	 */
	public function should_show_on_cart() {
		return (bool) $this->get( 'general', 'show_on_cart', true );
	}

	/**
	 * Check if should show on checkout
	 *
	 * @return bool
	 */
	public function should_show_on_checkout() {
		return (bool) $this->get( 'general', 'show_on_checkout', true );
	}

	/**
	 * Check if guests can see coupons
	 *
	 * @return bool
	 */
	public function enable_for_guests() {
		return (bool) $this->get( 'general', 'enable_for_guests', true );
	}

	/**
	 * Load settings from database with caching
	 *
	 * @return array<string, mixed>
	 */
	private function load_settings() {
		// Try to get from cache first.
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		// Get from database.
		$raw_settings = get_option( 'power_coupons_settings', array() );
		/**
		 * Type assertion for settings value.
		 *
		 * @var array<string, mixed> $settings
		 */
		$settings = is_array( $raw_settings ) ? $raw_settings : array();

		// Merge with defaults (with translations if available).
		$defaults = self::get_default_settings( true );
		foreach ( $defaults as $section => $section_defaults ) {
			if ( ! isset( $settings[ $section ] ) || ! is_array( $settings[ $section ] ) ) {
				$settings[ $section ] = $section_defaults;
			} else {
				$section_settings       = is_array( $settings[ $section ] ) ? $settings[ $section ] : array();
				$section_defaults_array = is_array( $section_defaults ) ? $section_defaults : array();
				$settings[ $section ]   = wp_parse_args( $section_settings, $section_defaults_array );
			}
		}

		// Cache for 1 hour.
		set_transient( self::CACHE_KEY, $settings, HOUR_IN_SECONDS );

		return $settings;
	}

	/**
	 * Clear settings cache
	 *
	 * @return void
	 */
	public function clear_cache() {
		delete_transient( self::CACHE_KEY );
		$this->settings = null;
	}
}
