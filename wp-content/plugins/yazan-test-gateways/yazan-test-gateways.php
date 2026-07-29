<?php
/**
 * Plugin Name:       Yazan Test Payment Gateways
 * Plugin URI:        https://yazan.local
 * Description:       DEVELOPMENT ONLY — simulated Apple Pay, Google Pay, PayPal and card gateways that complete an order without moving any money. Lets the full checkout → thank-you → payment-event flow be exercised locally, where the real gateways cannot run. Refuses to register outside a local/development environment.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Yazan
 * License:           GPL-2.0-or-later
 * Text Domain:       yazan-test-gateways
 * WC requires at least: 8.0
 * WC tested up to:   10.9
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS A SEPARATE PLUGIN
 *
 * These gateways mark orders as PAID without taking payment. That is exactly what
 * is needed to develop and test a checkout locally, and exactly what must never
 * reach a live store. Keeping them in their own plugin means removal is a single
 * deactivate-and-delete, with nothing left behind in the theme or in yazan-core.
 *
 * Two independent safeguards back that up:
 *   1. The gateways only register when wp_get_environment_type() is `local` or
 *      `development`. On production the plugin loads but registers nothing, so it
 *      cannot appear at checkout even if someone activates it by mistake.
 *   2. An always-on admin notice states plainly that simulated gateways are live.
 * ---------------------------------------------------------------------------
 *
 * @package Yazan_Test_Gateways
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'YAZAN_TG_VERSION', '1.0.0' );
define( 'YAZAN_TG_FILE', __FILE__ );
define( 'YAZAN_TG_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Whether simulated gateways may run in this environment.
 *
 * `wp-config.php` sets WP_ENVIRONMENT_TYPE to 'local' for this site. A staging box
 * that genuinely needs them can define YAZAN_TG_FORCE — deliberately awkward, so it
 * is never switched on by accident.
 *
 * @return bool
 */
function yazan_tg_allowed() {
	if ( defined( 'YAZAN_TG_FORCE' ) && YAZAN_TG_FORCE ) {
		return true;
	}

	$env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';

	return in_array( $env, array( 'local', 'development' ), true );
}

/**
 * HPOS compatibility. Orders are only ever touched through the WooCommerce CRUD
 * API, so this is compatible by construction.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', YAZAN_TG_FILE, true );
		}
	}
);

/**
 * Load the gateway classes once WooCommerce has defined WC_Payment_Gateway.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! yazan_tg_allowed() || ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		require_once YAZAN_TG_DIR . 'includes/class-yazan-test-gateway.php';
		require_once YAZAN_TG_DIR . 'includes/gateways.php';
	},
	20 // After WooCommerce.
);

/**
 * Register the gateways with WooCommerce.
 *
 * @param array $gateways Registered gateway class names.
 * @return array
 */
add_filter( 'woocommerce_payment_gateways', 'yazan_tg_register_gateways' );
function yazan_tg_register_gateways( $gateways ) {
	if ( ! yazan_tg_allowed() || ! class_exists( 'Yazan_Test_Gateway' ) ) {
		return $gateways;
	}

	return array_merge(
		(array) $gateways,
		array(
			'Yazan_Test_Gateway_Card',
			'Yazan_Test_Gateway_Apple_Pay',
			'Yazan_Test_Gateway_Google_Pay',
			'Yazan_Test_Gateway_PayPal',
		)
	);
}

/**
 * Teach the theme's accepted-payment row about these gateway ids, so the row stays
 * *derived* from what the store genuinely offers instead of falling back to its
 * pre-launch placeholder set. The theme owns the map; this plugin only adds to it.
 *
 * @param array $map gateway id => mark slugs.
 * @return array
 */
add_filter( 'yazan_payment_gateway_marks', 'yazan_tg_payment_marks' );
function yazan_tg_payment_marks( $map ) {
	$map['yz_test_card']       = array( 'visa', 'mastercard', 'amex' );
	$map['yz_test_apple_pay']  = array( 'apple-pay' );
	$map['yz_test_google_pay'] = array( 'google-pay' );
	$map['yz_test_paypal']     = array( 'paypal' );

	return $map;
}

/**
 * Standing reminder in wp-admin. Deliberately not dismissible: the whole point is
 * that nobody forgets simulated payments are switched on.
 *
 * @return void
 */
add_action( 'admin_notices', 'yazan_tg_admin_notice' );
function yazan_tg_admin_notice() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	if ( ! yazan_tg_allowed() ) {
		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Yazan Test Payment Gateways is inert.', 'yazan-test-gateways' ),
			esc_html__( 'This environment is not local or development, so no simulated gateways were registered. Delete the plugin if it is not needed here.', 'yazan-test-gateways' )
		);
		return;
	}

	$live = yazan_tg_enabled_titles();
	if ( ! $live ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p><strong>%s</strong> %s <em>%s</em></p></div>',
		esc_html__( 'Simulated payments are active.', 'yazan-test-gateways' ),
		esc_html__( 'These gateways mark orders as paid without taking any money. Never enable them on a live store. Currently enabled:', 'yazan-test-gateways' ),
		esc_html( implode( ', ', $live ) )
	);
}

/**
 * Titles of the simulated gateways that are currently switched on.
 *
 * Reads the stored settings directly rather than booting the gateway objects, so it
 * is safe to call early on any admin screen.
 *
 * @return string[]
 */
function yazan_tg_enabled_titles() {
	$ids = array(
		'yz_test_card'       => __( 'Card', 'yazan-test-gateways' ),
		'yz_test_apple_pay'  => __( 'Apple Pay', 'yazan-test-gateways' ),
		'yz_test_google_pay' => __( 'Google Pay', 'yazan-test-gateways' ),
		'yz_test_paypal'     => __( 'PayPal', 'yazan-test-gateways' ),
	);

	$live = array();

	foreach ( $ids as $id => $label ) {
		$settings = get_option( 'woocommerce_' . $id . '_settings' );
		if ( is_array( $settings ) && isset( $settings['enabled'] ) && 'yes' === $settings['enabled'] ) {
			$live[] = $label;
		}
	}

	return $live;
}

/**
 * Settings shortcut on the plugins list.
 *
 * @param array $links Existing action links.
 * @return array
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'yazan_tg_action_links' );
function yazan_tg_action_links( $links ) {
	$url = admin_url( 'admin.php?page=wc-settings&tab=checkout' );

	array_unshift(
		$links,
		'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Payment settings', 'yazan-test-gateways' ) . '</a>'
	);

	return $links;
}
