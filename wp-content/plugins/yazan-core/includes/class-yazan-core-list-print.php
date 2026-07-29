<?php
/**
 * Yazan Core — "Print" button in the orders list, next to the preview (eye) button.
 *
 * Adds a printer icon beside each row's WooCommerce preview button. Clicking it
 * prints that order's invoice from a hidden iframe (silent under Chrome
 * --kiosk-printing; otherwise the normal print dialog). Reuses the invoice markup
 * from Yazan_Core_Auto_Print.
 *
 * HPOS-safe: order access via wc_get_order().
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

class Yazan_Core_List_Print {

	const ACTION = 'yz_print';

	/**
	 * Register hooks. Called once on `init`.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_action_' . self::ACTION, array( __CLASS__, 'render' ) );
	}

	/**
	 * Load the button injector on the orders LIST screen only (HPOS or legacy),
	 * not the single-order editor.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! current_user_can( 'edit_shop_orders' ) || ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'woocommerce_page_wc-orders', 'edit-shop_order' ), true ) ) {
			return;
		}
		if ( isset( $_GET['action'] ) && 'edit' === $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return; // Single-order edit screen — not the list.
		}

		wp_enqueue_style(
			'yazan-list-print',
			YAZAN_CORE_URL . 'assets/css/admin-list-print.css',
			array( 'dashicons' ),
			YAZAN_CORE_VERSION
		);
		wp_enqueue_script(
			'yazan-list-print',
			YAZAN_CORE_URL . 'assets/js/admin-list-print.js',
			array( 'jquery' ),
			YAZAN_CORE_VERSION,
			true
		);
		wp_localize_script(
			'yazan-list-print',
			'yazanListPrint',
			array(
				'base'   => admin_url( 'admin.php' ),
				'action' => self::ACTION,
				'nonce'  => wp_create_nonce( self::ACTION ),
				'label'  => __( 'Print invoice', 'yazan' ),
			)
		);
	}

	/**
	 * Output the printable invoice for the requested order, then stop.
	 *
	 * @return void
	 */
	public static function render() {
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $order_id || ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You are not allowed to print this order.', 'yazan' ) );
		}
		check_admin_referer( self::ACTION );

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'yazan' ) );
		}

		Yazan_Core_Auto_Print::print_invoice_markup( $order );
		exit;
	}
}
