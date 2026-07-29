<?php
/**
 * Yazan — My Account (WooCommerce account area) wiring.
 *
 * Loads the scoped account stylesheet only on the account page and relabels the account menu tabs
 * in the brand voice. The visual layout / templates live in woocommerce/myaccount/*.php
 * (navigation, dashboard, form-login) and assets/css/my-account.css.
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WooCommerce' ) ) {
	return; // Nothing to do without WooCommerce.
}

/**
 * Enqueue the account stylesheet only on the My Account page. Depends on the base sheet so brand
 * tokens (--yz-ink, --yz-ivory, --yz-gold …) resolve first; being token-driven, it recolours with
 * the active Black / Burgundy theme automatically — no theme-specific rules needed.
 */
add_action( 'wp_enqueue_scripts', 'yazan_myaccount_enqueue', 20 );
function yazan_myaccount_enqueue() {
	if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
		return;
	}
	wp_enqueue_style(
		'yazan-my-account',
		YAZAN_URI . '/assets/css/my-account.css',
		array( 'yazan-main' ),
		YAZAN_VERSION
	);
}

/**
 * Relabel the account menu tabs in the brand voice. Endpoints (and their URLs/behaviour) are
 * unchanged — only the visible labels. Kept minimal and clear; the design does the heavy lifting.
 *
 * @param array $items Endpoint => label.
 * @return array
 */
add_filter( 'woocommerce_account_menu_items', 'yazan_account_menu_labels' );
function yazan_account_menu_labels( $items ) {
	if ( isset( $items['dashboard'] ) ) {
		$items['dashboard'] = __( 'Overview', 'yazan' );
	}
	if ( isset( $items['edit-account'] ) ) {
		$items['edit-account'] = __( 'Account Details', 'yazan' );
	}
	if ( isset( $items['customer-logout'] ) ) {
		$items['customer-logout'] = __( 'Sign Out', 'yazan' );
	}
	return $items;
}
