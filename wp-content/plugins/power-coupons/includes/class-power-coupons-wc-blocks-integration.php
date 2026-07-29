<?php
/**
 * WooCommerce Blocks Integration
 *
 * @package Power_Coupons
 * @since 1.0.0
 */

namespace Power_Coupons\Includes;

use Power_Coupons\Controllers\Display_Controller;
use Power_Coupons\Includes\Traits\Power_Coupons_Singleton;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Power_Coupons_WC_Blocks_Integration
 */
class Power_Coupons_WC_Blocks_Integration {

	use Power_Coupons_Singleton;

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
	 * Initialize hooks
	 *
	 * @return void
	 */
	private function init_hooks() {
		// Check if plugin is enabled.
		if ( ! $this->settings_helper->is_enabled() ) {
			return;
		}

		// Hook into WooCommerce Blocks rendering.
		add_filter( 'render_block', array( $this, 'render_coupons_in_blocks' ), 10, 2 );
	}

	/**
	 * Render coupons in WooCommerce blocks
	 *
	 * @param string               $block_content Block content.
	 * @param array<string, mixed> $block Block data.
	 * @return string
	 */
	public function render_coupons_in_blocks( $block_content, $block ) {
		// Early exit for non-WooCommerce blocks.
		$block_name_val = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
		if ( empty( $block_name_val ) || strpos( $block_name_val, 'woocommerce/' ) !== 0 ) {
			return $block_content;
		}

		$block_name = $block_name_val;

		// Handle cart block.
		if ( 'woocommerce/cart' === $block_name && $this->settings_helper->should_show_on_cart() ) {
			// Add before or after based on position.
			return $block_content;
		}

		// Handle checkout block.
		if ( 'woocommerce/checkout' === $block_name && $this->settings_helper->should_show_on_checkout() ) {
			return $block_content;
		}

		return $block_content;
	}
}
