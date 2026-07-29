<?php
/**
 * Checkout Drawer Controller
 *
 * @package Power_Coupons
 * @since 1.0.0
 */

namespace Power_Coupons\Controllers;

use Power_Coupons\Includes\Power_Coupons_Settings_Helper;
use Power_Coupons\Includes\Power_Coupons_Utilities;
use Power_Coupons\Includes\Traits\Power_Coupons_Singleton;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Checkout_Drawer_Controller
 */
class Checkout_Drawer_Controller {

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
		// Only initialize if plugin is enabled.
		if ( ! $this->settings_helper->is_enabled() ) {
			return;
		}

		// Check if guest users can see coupons.
		if ( ! is_user_logged_in() && ! $this->settings_helper->enable_for_guests() ) {
			return;
		}

		// Add button before payment section on checkout (after order review/coupon).
		if ( $this->settings_helper->should_show_on_cart() ) {
			add_action( 'woocommerce_proceed_to_checkout', array( $this, 'render_drawer_button' ), 10 );
		}

		if ( $this->settings_helper->should_show_on_checkout() ) {
			add_action( 'woocommerce_review_order_before_payment', array( $this, 'render_drawer_button' ), 10 );
		}

		// Add drawer HTML to footer.
		add_action( 'wp_footer', array( $this, 'render_drawer_html' ) );

		// Enqueue drawer assets.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_drawer_assets' ) );

		// AJAX endpoint to get coupons for drawer.
		add_action( 'wp_ajax_power_coupons_get_drawer_coupons', array( $this, 'ajax_get_drawer_coupons' ) );
		add_action( 'wp_ajax_nopriv_power_coupons_get_drawer_coupons', array( $this, 'ajax_get_drawer_coupons' ) );
	}

	/**
	 * Render the "View Available Coupons" button
	 *
	 * @return void
	 */
	public function render_drawer_button() {
		?>
		<div class="power-coupons-drawer-trigger-wrapper">
			<button type="button" class="power-coupons-view-coupons-btn" id="power-coupons-view-coupons-btn" aria-label="<?php esc_attr_e( 'View available discount coupons', 'power-coupons' ); ?>" aria-expanded="false" aria-controls="power-coupons-drawer">
				<?php echo esc_html( $this->get_text_labels( 'trigger_button_label' ) ); ?>
			</button>
		</div>
		<?php
	}

	/**
	 * Returns text labels based on the text key provided.
	 *
	 * @param string $text_key Text label key.
	 * @return string
	 */
	private function get_text_labels( $text_key ) {
		$texts = $this->settings_helper->get_text_settings();
		$text  = isset( $texts[ $text_key ] ) && is_string( $texts[ $text_key ] ) ? $texts[ $text_key ] : '';
		return ! empty( $text ) ? esc_html( $text ) : '';
	}

	/**
	 * Render the slide-out drawer HTML
	 *
	 * @return void
	 */
	public function render_drawer_html() {
		if ( is_admin() ) {
			// Edge case: Some plugin used wp_footer(); on the admin side template. Bail early if that's the case we are dealing with here.
			return;
		}
		?>
		<?php $display_mode = $this->settings_helper->get( 'general', 'coupon_display_mode', 'drawer' ); ?>
		<div id="power-coupons-drawer" class="power-coupons-drawer" data-display-mode="<?php echo esc_attr( is_string( $display_mode ) ? $display_mode : 'drawer' ); ?>" role="dialog" aria-modal="true" aria-labelledby="power-coupons-drawer-heading" aria-hidden="true">
			<div class="power-coupons-drawer-overlay" aria-hidden="true"></div>
			<div class="power-coupons-drawer-content">
				<div class="power-coupons-drawer-header">
					<?php
					$drawer_heading = $this->get_text_labels( 'drawer_heading' );
					if ( empty( $drawer_heading ) ) {
						$drawer_heading = esc_html__( 'Available Coupons', 'power-coupons' );
					}
					?>
					<h3 id="power-coupons-drawer-heading"><?php echo esc_html( $drawer_heading ); ?></h3>
					<button type="button" class="power-coupons-drawer-close" aria-label="<?php esc_attr_e( 'Close coupon drawer', 'power-coupons' ); ?>">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
							<path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
					</button>
				</div>
				<div class="power-coupons-drawer-body" role="region" aria-label="<?php esc_attr_e( 'Available Coupons', 'power-coupons' ); ?>">
					<div class="power-coupons-drawer-loading" role="status" aria-live="polite" aria-label="<?php esc_attr_e( 'Loading coupons', 'power-coupons' ); ?>">
						<div class="power-coupons-spinner" aria-hidden="true"></div>
						<p><?php echo esc_html( $this->get_text_labels( 'coupons_loading_text' ) ); ?></p>
					</div>
					<div class="power-coupons-drawer-coupons-list">
						<?php Display_Controller::get_instance()->render_coupon_list( 'slideout' ); ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue drawer assets
	 *
	 * @return void
	 */
	public function enqueue_drawer_assets() {
		wp_enqueue_style(
			'power-coupons-drawer',
			POWER_COUPONS_URL . 'public/assets/css/checkout-drawer.css',
			array(),
			POWER_COUPONS_VERSION
		);

		wp_enqueue_script(
			'power-coupons-drawer',
			POWER_COUPONS_URL . 'public/assets/js/checkout-drawer.js',
			array( 'jquery' ),
			POWER_COUPONS_VERSION,
			true
		);

		ob_start();
		$this->render_drawer_button();
		$drawer_button = ob_get_clean();

		wp_localize_script(
			'power-coupons-drawer',
			'powerCouponsDrawer',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'power-coupons-drawer-nonce' ),
				'showOnCart'     => $this->settings_helper->should_show_on_cart(),
				'showOnCheckout' => $this->settings_helper->should_show_on_checkout(),
				'displayMode'    => $this->settings_helper->get( 'general', 'coupon_display_mode', 'drawer' ),
				'html'           => array(
					'drawerButton' => $drawer_button,
				),
			)
		);
	}

	/**
	 * AJAX handler to get coupons for drawer
	 *
	 * @return void
	 */
	public function ajax_get_drawer_coupons() {
		check_ajax_referer( 'power-coupons-drawer-nonce', 'nonce' );

		$coupons = $this->get_available_coupons();

		if ( empty( $coupons ) ) {
			wp_send_json_success(
				array(
					'html' => '<div class="power-coupons-no-coupons"><p>' . esc_html( $this->get_text_labels( 'no_coupons_text' ) ) . '</p></div>',
				)
			);
		}

		ob_start();
		Display_Controller::get_instance()->render_coupon_list( 'slideout' );
		$html = ob_get_clean();
		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * Get available coupons
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_available_coupons() {
		$args = array(
			'post_type'      => 'shop_coupon',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
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

		$coupons_query = new \WP_Query( $args );
		$coupons       = array();

		// Get the rules validator instance.
		$rules_validator = \Power_Coupons\Public_Folder\Power_Coupons_Frontend_Rules::get_instance();

		$general_settings = $this->settings_helper->get_general_settings();

		$show_applied_coupons = ! empty( $general_settings['show_applied_coupons'] );

		if ( $coupons_query->have_posts() ) {
			while ( $coupons_query->have_posts() ) {
				$coupons_query->the_post();
				$coupon_id = get_the_ID();

				if ( false === $coupon_id ) {
					continue;
				}

				$coupon = new \WC_Coupon( $coupon_id );

				$code = $coupon->get_code();

				$is_applied = $this->is_coupon_applied( $code );

				if ( ! $show_applied_coupons && $is_applied ) {
					// Hide the applied coupons if show applied coupons setting is disabled.
					continue;
				}

				// Check if coupon meets conditional rules (if enabled).
				if ( is_int( $coupon_id ) && ! $rules_validator->is_coupon_valid( $coupon_id ) ) {
					continue;
				}

				// Skip expired or not started coupons.
				if ( is_int( $coupon_id ) && Power_Coupons_Utilities::is_coupon_not_started( array( 'start_date' => get_post_meta( $coupon_id, '_power_coupon_start_date', true ) ) ) ) {
					continue;
				}

				if ( Power_Coupons_Utilities::is_coupon_expired( array( 'expiry_date' => $coupon->get_date_expires() ) ) ) {
					continue;
				}

				$coupon_type   = $coupon->get_discount_type();
				$coupon_expiry = $coupon->get_date_expires();

				$coupons[] = array(
					'id'          => $coupon_id,
					'code'        => $code,
					'description' => $coupon->get_description(),
					'amount'      => $coupon->get_amount(),
					'type'        => $coupon_type,
					'type_text'   => $this->get_coupon_type_text( $coupon_type ),
					'expiry_date' => ! empty( $coupon_expiry ) ? $coupon_expiry : __( 'NA', 'power-coupons' ),
					'is_applied'  => $is_applied,
				);
			}
			wp_reset_postdata();
		}

		/**
		 * Filter the available coupons before display.
		 *
		 * @since 1.0.1
		 *
		 * @param array  $coupons Array of coupon data arrays.
		 * @param string $context Display context — 'checkout_drawer'.
		 */
		$coupons = apply_filters( 'power_coupons_available_coupons', $coupons, 'checkout_drawer' );

		return $coupons;
	}

	/**
	 * Render coupons list for drawer
	 *
	 * @param array $coupons Coupons array.
	 * @return void
	 */

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
	 * Get human-readable text for coupon type
	 *
	 * @param string $type Coupon discount type.
	 * @return string
	 */
	private function get_coupon_type_text( $type = '' ) {
		if ( empty( $type ) ) {
			return '';
		}

		switch ( $type ) {
			case 'percent':
				return __( 'Percent Discount', 'power-coupons' );
			case 'fixed_cart':
				return __( 'Cart Discount', 'power-coupons' );
			case 'fixed_product':
				return __( 'Product Discount', 'power-coupons' );
			default:
				return '';
		}
	}
}
