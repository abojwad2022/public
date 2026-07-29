<?php
/**
 * Power Coupons - BSF Analytics Integration
 *
 * @package Power_Coupons
 * @since 1.1.0
 */

namespace Power_Coupons\Admin;

use Power_Coupons\Includes\Traits\Power_Coupons_Singleton;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analytics class.
 *
 * Handles BSF Analytics integration: opt-in, deactivation survey, and stats payload.
 *
 * @since 1.1.0
 */
class Power_Coupons_Analytics {

	use Power_Coupons_Singleton;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 */
	protected function __construct() {

		// Only run analytics in admin context.
		if ( ! is_admin() ) {
			return;
		}

		// Load BSF Analytics library.
		if ( ! class_exists( 'BSF_Analytics_Loader' ) ) {
			require_once POWER_COUPONS_DIR . 'inc/lib/bsf-analytics/class-bsf-analytics-loader.php';
		}

		// Load Astra Notices for opt-in UI.
		if ( ! class_exists( 'Astra_Notices' ) ) {
			require_once POWER_COUPONS_DIR . 'inc/lib/astra-notices/class-astra-notices.php';
		}

		$bsf_analytics = \BSF_Analytics_Loader::get_instance();

		$bsf_analytics->set_entity(
			[
				'power-coupons' => [
					'product_name'        => 'Power Coupons for WooCommerce',
					'path'                => POWER_COUPONS_DIR . 'inc/lib/bsf-analytics',
					'author'              => 'Brainstorm Force',
					'time_to_display'     => '+24 hours',
					// IMPORTANT: deactivation_survey MUST be an array of arrays.
					// BSF_Analytics iterates and passes each item to show_feedback_form().
					'deactivation_survey' => [
						[
							'id'                => 'deactivation-survey-power-coupons',
							'popup_logo'        => POWER_COUPONS_URL . 'admin/assets/images/logo.svg',
							'plugin_slug'       => 'power-coupons',
							'popup_title'       => 'Quick Feedback',
							'support_url'       => 'https://brainstormforce.com/support/',
							'popup_description' => 'If you have a moment, please share why you are deactivating Power Coupons:',
							'show_on_screens'   => [ 'plugins' ],
							'plugin_version'    => POWER_COUPONS_VERSION,
						],
					],
				],
			]
		);

		// Stats payload filter.
		add_filter( 'bsf_core_stats', [ $this, 'add_analytics_data' ] );
	}

	/**
	 * Add Power Coupons analytics data to the BSF core stats payload.
	 *
	 * @param array $stats_data Existing stats data.
	 * @since 1.1.0
	 * @return array Modified stats data.
	 */
	public function add_analytics_data( $stats_data ) {
		$settings = get_option( 'power_coupons_settings', [] );

		$stats_data['plugin_data']['power-coupons'] = [
			'free_version'  => POWER_COUPONS_VERSION,
			'site_language' => get_locale(),
		];

		// Snapshot counts.
		$stats_data['plugin_data']['power-coupons']['numeric_values'] = [
			'total_coupons'          => $this->get_published_coupons_count(),
			'total_rules_enabled'    => $this->get_coupons_with_rules_count(),
			'total_auto_apply'       => $this->get_auto_apply_coupons_count(),
			'total_bogo_offers'      => $this->get_bogo_offers_count(),
			'total_points_campaigns' => $this->get_points_campaigns_count(),
		];

		// Feature flags.
		$stats_data['plugin_data']['power-coupons']['boolean_values'] = [
			'pro_active'                => defined( 'POWER_COUPONS_PRO_VERSION' ),
			'plugin_enabled'            => ! empty( $settings['general']['enable_plugin'] ),
			'show_on_cart'              => ! empty( $settings['general']['show_on_cart'] ),
			'show_on_checkout'          => ! empty( $settings['general']['show_on_checkout'] ),
			'enable_for_guests'         => ! empty( $settings['general']['enable_for_guests'] ),
			'hide_wc_coupon_field'      => ! empty( $settings['general']['hide_wc_coupon_field'] ),
			'auto_apply_used'           => $this->get_auto_apply_coupons_count() > 0,
			'rules_used'                => $this->get_coupons_with_rules_count() > 0,
			'bogo_used'                 => $this->get_bogo_offers_count() > 0,
			'points_enabled'            => $this->is_points_enabled(),
			'cart_progress_bar_enabled' => ! empty( $settings['cart_progress_bar']['enable'] ),
			'coupon_display_mode'       => ! empty( $settings['general']['coupon_display_mode'] ) ? $settings['general']['coupon_display_mode'] : 'drawer',
		];

		// Internal referrer.
		$bsf_referrers = get_option( 'bsf_product_referers', [] );
		$stats_data['plugin_data']['power-coupons']['internal_referer'] = ! empty( $bsf_referrers['power-coupons'] ) ? $bsf_referrers['power-coupons'] : '';

		return $stats_data;
	}

	/**
	 * Get total published WooCommerce coupons count.
	 *
	 * @since 1.1.0
	 * @return int
	 */
	private function get_published_coupons_count() {
		$counts = wp_count_posts( 'shop_coupon' );
		return isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	/**
	 * Get count of coupons with conditional rules enabled.
	 *
	 * @since 1.1.0
	 * @return int
	 */
	private function get_coupons_with_rules_count() {
		$query = new \WP_Query(
			[
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required for count.
					[
						'key'   => '_pc_rule_enable_conditions',
						'value' => 'yes',
					],
				],
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => false,
			]
		);

		$count = (int) $query->found_posts;
		wp_reset_postdata();

		return $count;
	}

	/**
	 * Get count of auto-apply coupons.
	 *
	 * @since 1.1.0
	 * @return int
	 */
	private function get_auto_apply_coupons_count() {
		$query = new \WP_Query(
			[
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required for count.
					[
						'key'   => '_power_coupon_auto_apply',
						'value' => 'yes',
					],
				],
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => false,
			]
		);

		$count = (int) $query->found_posts;
		wp_reset_postdata();

		return $count;
	}

	/**
	 * Get count of BOGO offers.
	 *
	 * @since 1.1.0
	 * @return int
	 */
	private function get_bogo_offers_count() {
		if ( ! defined( 'POWER_COUPONS_PRO_VERSION' ) ) {
			return 0;
		}

		$query = new \WP_Query(
			[
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required for count.
					[
						'key'     => 'power_coupons_bogo_offer_type',
						'compare' => 'EXISTS',
					],
				],
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => false,
			]
		);

		$count = (int) $query->found_posts;
		wp_reset_postdata();

		return $count;
	}

	/**
	 * Get count of active points campaigns.
	 *
	 * @since 1.1.0
	 * @return int
	 */
	private function get_points_campaigns_count() {
		if ( ! defined( 'POWER_COUPONS_PRO_VERSION' ) ) {
			return 0;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'power_coupons_points_campaigns';

		// Check if table exists before querying.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
		);

		if ( ! $table_exists ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM {$table_name} WHERE status = 'active'"
		);
	}

	/**
	 * Check if Points & Rewards is enabled.
	 *
	 * @since 1.1.0
	 * @return bool
	 */
	private function is_points_enabled() {
		if ( ! defined( 'POWER_COUPONS_PRO_VERSION' ) ) {
			return false;
		}

		$points_settings = get_option( 'power_coupons_points_settings', [] );
		return ! empty( $points_settings['general']['enable'] );
	}
}
