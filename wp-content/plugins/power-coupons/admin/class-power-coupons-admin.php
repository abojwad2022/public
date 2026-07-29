<?php
/**
 * Admin Class
 *
 * @package Power_Coupons
 * @since 1.0.0
 */

namespace Power_Coupons\Admin;

use Power_Coupons\Includes\Traits\Power_Coupons_Singleton;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Power_Coupons_Admin
 */
class Power_Coupons_Admin {

	use Power_Coupons_Singleton;

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	protected function __construct() {
		$this->load_dependencies();
		$this->init_hooks();
	}

	/**
	 * Load dependencies
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function load_dependencies() {
		// Load Settings.
		require_once POWER_COUPONS_DIR . 'admin/class-power-coupons-admin-settings.php';
		Power_Coupons_Admin_Settings::get_instance();

		// Load Coupon Meta.
		require_once POWER_COUPONS_DIR . 'admin/class-power-coupons-admin-coupon-meta.php';
		Admin_Coupon_Meta::get_instance();

		// Initialize Conditional Rules Admin.
		Power_Coupons_Admin_Rules::get_instance();

		// Load Admin Notices.
		require_once POWER_COUPONS_DIR . 'admin/class-power-coupons-admin-notices.php';
		Power_Coupons_Admin_Notices::get_instance();
	}

	/**
	 * Initialize hooks
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function init_hooks() {
		add_filter( 'manage_edit-shop_coupon_columns', array( $this, 'add_short_code_column' ), 10, 1 );
		add_action( 'manage_shop_coupon_posts_custom_column', array( $this, 'add_shortcode_column_content' ), 10, 2 );

		add_filter( 'plugin_action_links_' . POWER_COUPONS_BASE, [ $this, 'action_links' ] );
	}

	/**
	 * Adds a new 'Shortcode' column header to the WooCommerce coupons table
	 * in the WordPress admin area.
	 *
	 * @since 1.0.0
	 * @param array $defaults Array of default column headers.
	 * @return array Modified array with shortcode column added
	 */
	public function add_short_code_column( $defaults ) {
		// Add new 'Shortcode' column to the columns array.
		$defaults['power-coupons-shortcode'] = esc_html__( 'Shortcode', 'power-coupons' );

		return $defaults;
	}

	/**
	 * Outputs the shortcode content for each coupon in the shortcode column
	 * of the WooCommerce coupons table.
	 *
	 * @since 1.0.0
	 * @param string $column_name The name/slug of the current column.
	 * @param int    $post_ID The ID of the coupon post.
	 * @return void
	 */
	public function add_shortcode_column_content( $column_name, $post_ID ) {
		// Only output content if this is the shortcode column.
		if ( 'power-coupons-shortcode' === $column_name ) {
			// Output the shortcode with the coupon ID, sanitized with absint().
			echo esc_html( '[power_coupons id=' . absint( $post_ID ) . ']' );
		}
	}

	/**
	 * Adds links in Plugins page.
	 *
	 * @param array<string> $links Existing links.
	 * @return array<string> Filtered links with settings added.
	 * @since 1.0.0
	 */
	public function action_links( $links ) {
		$plugin_links = apply_filters(
			'power_coupons_plugin_action_links',
			[
				'power_coupons_settings' => '<a href="' . admin_url( 'admin.php?page=power_coupons_settings' ) . '">' . __( 'Settings', 'power-coupons' ) . '</a>',
			]
		);

		return array_merge( $plugin_links, $links );
	}
}

