<?php
/**
 * Admin Rules Class
 *
 * Handles rendering and saving conditional rule fields in WooCommerce coupon admin.
 * Uses React for the UI interface.
 *
 * @package    Power_Coupons
 * @subpackage Power_Coupons/Admin
 * @since      1.0.0
 */

namespace Power_Coupons\Admin;

use Power_Coupons\Includes\Power_Coupons_Rules_Registry;
use Power_Coupons\Includes\Traits\Power_Coupons_Singleton;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Power_Coupons_Admin_Rules
 *
 * Manages the admin interface for conditional rules in WooCommerce coupon edit screen.
 */
class Power_Coupons_Admin_Rules {

	use Power_Coupons_Singleton;

	/**
	 * Constructor
	 */
	protected function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize WordPress hooks
	 */
	private function init_hooks() {
		// Render conditional rules panel.
		add_action( 'woocommerce_coupon_data_panels', array( $this, 'render_rules_panel' ), 10 );

		// Save conditional rules.
		add_action( 'woocommerce_coupon_options_save', array( $this, 'save_rules_panel' ), 20 );

		// Enqueue admin scripts and styles.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Enqueue admin assets
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		// Only load on coupon edit screen.
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		global $post;
		if ( ! $post || 'shop_coupon' !== $post->post_type ) {
			return;
		}

		// Enqueue React build.
		$asset_file = plugin_dir_path( dirname( __FILE__ ) ) . 'admin/assets/build/rule-engine/index.asset.php';

		if ( file_exists( $asset_file ) ) {
			$asset = include $asset_file;

			// Load Google Fonts for admin UI (disclosed in readme.txt - Third-Party Services section).
			wp_enqueue_style( 'power-coupons-font', 'https://fonts.googleapis.com/css2?family=Figtree:wght@300;400;500;600&display=swap', array(), '1.0.0' );

			wp_enqueue_style(
				'power-coupons-rule-engine',
				plugin_dir_url( dirname( __FILE__ ) ) . 'admin/assets/build/rule-engine/index.css',
				array(),
				$asset['version']
			);

			wp_enqueue_script(
				'power-coupons-rule-engine',
				plugin_dir_url( dirname( __FILE__ ) ) . 'admin/assets/build/rule-engine/index.js',
				$asset['dependencies'],
				$asset['version'],
				true
			);

			// Get existing rule data.
			$rules_data = Power_Coupons_Rules_Registry::get_all_meta( get_the_ID() );

			// Setup WooCommerce REST API authentication for apiFetch.
			wp_localize_script(
				'power-coupons-rule-engine',
				'powerCouponsRules',
				array(
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'restUrl' => rest_url(),
					'data'    => array(
						'enabled' => $rules_data['enabled'] ? 'yes' : 'no',
						'groups'  => $rules_data['groups'],
					),
				)
			);
		}
	}

	/**
	 * Render the conditional rules panel
	 */
	public function render_rules_panel() {
		// Add nonce field for security.
		wp_nonce_field( 'power_coupons_save_rules', 'power_coupons_rules_nonce' );
		?>
		<div id="power_coupons_rules_tab" class="panel woocommerce_options_panel power-coupons-rules__toggle-rules">
			<!-- React will mount here -->
		</div>
		<?php
	}

	/**
	 * Save conditional rules metadata
	 *
	 * @param int $coupon_id Coupon post ID.
	 */
	public function save_rules_panel( $coupon_id ) {
		// Verify nonce for security.
		if ( ! isset( $_POST['power_coupons_rules_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['power_coupons_rules_nonce'] ) ), 'power_coupons_save_rules' ) ) {
			return;
		}

		// Check user capabilities.
		if ( ! current_user_can( 'edit_shop_coupons' ) ) {
			return;
		}

		// Verify we're on the right post type.
		if ( 'shop_coupon' !== get_post_type( $coupon_id ) ) {
			return;
		}

		// Enable conditions checkbox.
		$enabled = isset( $_POST['_pc_rule_enable_conditions'] ) ? sanitize_text_field( wp_unslash( $_POST['_pc_rule_enable_conditions'] ) ) : 'no';

		// Get rule groups from POST data.
		$groups = isset( $_POST['pc_rule_groups'] ) && is_array( $_POST['pc_rule_groups'] ) ? wp_unslash( $_POST['pc_rule_groups'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- We are sanitizing values below using Power_Coupons_Rules_Registry::sanitize_meta_value_array_data.

		// Prepare data array.
		$rules_data = array(
			'_pc_rule_enable_conditions' => $enabled,
			'_pc_rule_groups'            => $groups,
		);

		// Sanitize rules data.
		$sanitized_rules_data = Power_Coupons_Rules_Registry::sanitize_meta_value_array_data( $rules_data );

		// Save using registry (includes sanitization).
		Power_Coupons_Rules_Registry::save_meta( $coupon_id, $sanitized_rules_data );

		// Analytics flag-setter: first rule created with conditions enabled.
		if ( 'yes' === $enabled && ! get_option( 'power_coupons_first_rule_created' ) ) {
			update_option( 'power_coupons_first_rule_created', true );
		}

		// Clear all relevant caches to ensure updated rules take effect immediately.
		$this->clear_coupon_caches( $coupon_id );
	}

	/**
	 * Clear all caches related to a coupon
	 *
	 * This ensures that updated rules take effect immediately on the frontend.
	 *
	 * @param int $coupon_id Coupon post ID.
	 */
	private function clear_coupon_caches( $coupon_id ) {
		// Clear WordPress post meta cache.
		wp_cache_delete( $coupon_id, 'post_meta' );

		// Clean post cache.
		clean_post_cache( $coupon_id );

		// Clear WooCommerce coupon cache.
		wp_cache_delete( 'coupon-' . $coupon_id, 'coupons' );

		// Clear WooCommerce cache with prefix (if WC_Cache_Helper exists).
		if ( class_exists( '\WC_Cache_Helper' ) ) {
			$cache_prefix = \WC_Cache_Helper::get_cache_prefix( 'coupons' );
			wp_cache_delete( $cache_prefix . 'coupon-' . $coupon_id, 'coupons' );
			\WC_Cache_Helper::invalidate_cache_group( 'coupons' );
		}

		// Clear object cache if a persistent cache is being used.
		if ( wp_using_ext_object_cache() ) {
			wp_cache_flush_group( 'coupons' );
		}

		// Delete any WooCommerce transients related to coupons.
		delete_transient( 'wc_coupon_' . $coupon_id );
		delete_transient( 'wc_coupons_' . $coupon_id );

		// Clear the cart to force recalculation (if cart is available).
		if ( function_exists( 'WC' ) && ! is_null( \WC()->cart ) ) {
			\WC()->cart->calculate_totals();
		}

		// Trigger action for custom cache clearing.
		do_action( 'power_coupons_clear_coupon_cache', $coupon_id );
	}
}
