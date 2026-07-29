<?php
/**
 * Fired during plugin activation
 *
 * @package Power_Coupons
 * @since 1.0.0
 */

namespace Power_Coupons\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Power_Coupons_Activator
 */
class Power_Coupons_Activator {

	/**
	 * Activate plugin
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function activate() {

		// Create custom post type for power coupons.
		self::create_post_type();

		// Flush rewrite rules.
		flush_rewrite_rules();

		// Run data migrations (idempotent — safe to run on every activation).
		require_once POWER_COUPONS_DIR . 'includes/class-power-coupons-migration.php';
		Power_Coupons_Migration::run_on_activation();

		// Set activation flag.
		set_transient( 'power_coupons_activated', true, 30 );

		// Set onboarding redirect transient (only if onboarding not already completed).
		$is_onboarding_complete = get_option( 'power_coupons_is_onboarding_complete', 'no' );
		if ( 'yes' !== $is_onboarding_complete ) {
			set_transient( 'power_coupons_redirect_to_onboarding', 'yes' );
		}

		// Record install time for analytics (days_since_install calculation).
		if ( ! get_option( 'power_coupons_usage_installed_time' ) ) {
			update_option( 'power_coupons_usage_installed_time', time() );
		}
	}

	/**
	 * Deactivate plugin
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function deactivate() {
		// Flush rewrite rules.
		flush_rewrite_rules();

		// Clear any transients.
		delete_transient( 'power_coupons_activated' );
	}

	/**
	 * Create custom post type for power coupons
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private static function create_post_type() {
		$args = array(
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => false,
			'query_var'          => true,
			'rewrite'            => false,
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'supports'           => array( 'title' ),
		);

		register_post_type( 'power_coupon', $args );
	}
}

