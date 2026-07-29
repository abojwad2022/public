<?php
/**
 * Admin Notices Class
 *
 * @package Power_Coupons
 * @since 1.0.2
 */

namespace Power_Coupons\Admin;

use Power_Coupons\Includes\Traits\Power_Coupons_Singleton;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Power_Coupons_Admin_Notices
 *
 * @since 1.0.2
 */
class Power_Coupons_Admin_Notices {

	use Power_Coupons_Singleton;

	/**
	 * Allowed admin screens to display notices.
	 *
	 * @var array<string>
	 * @since 1.0.2
	 */
	private $allowed_screens = array( 'dashboard', 'plugins' );

	/**
	 * Constructor
	 *
	 * @since 1.0.2
	 */
	protected function __construct() {
		// Load Astra Notices library.
		if ( ! class_exists( 'Astra_Notices' ) ) {
			require_once POWER_COUPONS_DIR . 'libraries/astra-notices/class-astra-notices.php';
		}

		add_action( 'admin_notices', array( $this, 'show_review_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_notice_styles' ) );
	}

	/**
	 * Enqueue notice styles.
	 *
	 * @since 1.0.2
	 * @return void
	 */
	public function enqueue_notice_styles() {
		if ( ! $this->is_allowed_screen() ) {
			return;
		}

		wp_enqueue_style(
			'power-coupons-notices',
			POWER_COUPONS_URL . 'admin/assets/css/notices.css',
			array(),
			POWER_COUPONS_VERSION
		);
	}

	/**
	 * Register the 5-star review notice.
	 *
	 * @since 1.0.2
	 * @return void
	 */
	public function show_review_notice() {
		if ( ! $this->is_allowed_screen() ) {
			return;
		}

		// Gate the notice on a real, successful outcome: the 14-day install delay
		// has elapsed AND a Power Coupons coupon has been redeemed in a placed order.
		// Both conditions are enforced here, so Astra's own display delay is disabled
		// below (otherwise it would start a second clock once show_if turns true).
		if ( ! $this->is_review_notice_ready() ) {
			return;
		}

		$logo_url = esc_url( POWER_COUPONS_URL . 'admin/assets/images/logo.svg' );

		\Astra_Notices::add_notice(
			array(
				'id'                   => 'power-coupons-5-star-notice',
				'type'                 => 'info',
				'class'                => 'power-coupons-5-star',
				'show_if'              => true,
				/* translators: %1$s: logo URL, %2$s: notice heading, %3$s: notice description, %4$s: review URL, %5$s: button label, %6$s: repeat after value, %7$s: button label, %8$s: button label */
				'message'              => sprintf(
					'<div class="notice-image" style="display: flex;">
						<img src="%1$s" class="custom-logo" alt="Power Coupons Icon" itemprop="logo" style="max-width: 90px;">
					</div>
					<div class="notice-content">
						<div class="notice-heading">%2$s</div>
						<div class="notice-description">%3$s</div>
						<div class="astra-review-notice-container">
							<a href="%4$s" class="astra-notice-close astra-review-notice button-primary" target="_blank">
								<span class="dashicons dashicons-yes"></span>
								%5$s
							</a>
							<a href="#" data-repeat-notice-after="%6$s" class="astra-notice-close astra-review-notice">
								<span class="dashicons dashicons-calendar"></span>
								<u>%7$s</u>
							</a>
							<a href="#" class="astra-notice-close astra-review-notice">
								<span class="dashicons dashicons-smiley"></span>
								<u>%8$s</u>
							</a>
						</div>
					</div>',
					$logo_url,
					esc_html__( 'Your coupons are converting &mdash; want to help others do the same?', 'power-coupons' ),
					esc_html__( 'A quick 5-star review helps other WooCommerce store owners discover Power Coupons. It takes 30 seconds and means a lot to our team.', 'power-coupons' ),
					'https://wordpress.org/support/plugin/power-coupons/reviews/?filter=5#new-post',
					esc_html__( 'Ok, you deserve it', 'power-coupons' ),
					MONTH_IN_SECONDS,
					esc_html__( 'Nope, maybe later', 'power-coupons' ),
					esc_html__( 'I already did', 'power-coupons' )
				),
				'repeat-notice-after'  => MONTH_IN_SECONDS,
				// Delay is enforced via is_review_notice_ready() (measured from install,
				// independent of redemption), so Astra must not add its own delay.
				'display-notice-after' => false,
			)
		);
	}

	/**
	 * Whether the 5-star review notice is eligible to display.
	 *
	 * Requires BOTH conditions to be true:
	 *  1. The 14-day delay since install has elapsed (independent of redemption).
	 *  2. A coupon created using Power Coupons has been redeemed in a placed order.
	 *
	 * @since 1.0.2
	 * @return bool
	 */
	private function is_review_notice_ready() {
		// Condition 2: a Power Coupons coupon was redeemed in a placed order.
		if ( ! get_option( 'power_coupons_first_coupon_redeemed' ) ) {
			return false;
		}

		// Condition 1: the 14-day install delay has elapsed.
		$raw_time     = get_option( 'power_coupons_usage_installed_time', 0 );
		$install_time = is_numeric( $raw_time ) ? (int) $raw_time : 0;

		if ( $install_time <= 0 ) {
			return false;
		}

		return ( time() - $install_time ) >= ( 2 * WEEK_IN_SECONDS );
	}

	/**
	 * Check if current screen is allowed to display notices.
	 *
	 * @since 1.0.2
	 * @return bool
	 */
	private function is_allowed_screen() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$current_screen = get_current_screen();

		if ( ! $current_screen ) {
			return false;
		}

		return in_array( $current_screen->id, $this->allowed_screens, true );
	}
}
