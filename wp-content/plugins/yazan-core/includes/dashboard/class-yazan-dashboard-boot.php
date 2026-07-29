<?php
/**
 * Yazan Dashboard — subsystem bootstrap.
 *
 * Loads the dashboard classes + REST controllers and registers every hook the standalone dashboard
 * needs. Kept as one entry point so yazan-core.php only gains a single require + init() call.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the dashboard route, field registry, audit log, and REST controllers.
 */
class Yazan_Dashboard_Boot {

	/**
	 * Require classes and register hooks. Call once at plugin load.
	 *
	 * @return void
	 */
	public static function init() {
		$dir = YAZAN_CORE_DIR . 'includes/dashboard/';

		require_once $dir . 'class-yazan-dashboard-fields.php';
		require_once $dir . 'class-yazan-dashboard-audit.php';
		require_once $dir . 'class-yazan-dashboard-auth.php';
		require_once $dir . 'class-yazan-dashboard.php';
		require_once $dir . 'rest/class-yazan-rest-products.php';
		require_once $dir . 'rest/class-yazan-rest-media.php';
		require_once $dir . 'rest/class-yazan-rest-meta.php';
		require_once $dir . 'rest/class-yazan-rest-orders.php';
		require_once $dir . 'rest/class-yazan-rest-order-write.php';
		require_once $dir . 'rest/class-yazan-rest-customers.php';
		require_once $dir . 'rest/class-yazan-rest-terms.php';
		require_once $dir . 'rest/class-yazan-rest-attributes.php';
		require_once $dir . 'rest/class-yazan-rest-inventory.php';
		require_once $dir . 'rest/class-yazan-rest-settings.php';
		require_once $dir . 'rest/class-yazan-rest-audit.php';
		require_once $dir . 'rest/class-yazan-rest-coupons.php';
		require_once $dir . 'rest/class-yazan-rest-reports.php';
		require_once $dir . 'rest/class-yazan-rest-tax.php';
		require_once $dir . 'rest/class-yazan-rest-shipping.php';
		require_once $dir . 'rest/class-yazan-rest-gateways.php';
		require_once $dir . 'rest/class-yazan-rest-emails.php';
		require_once $dir . 'rest/class-yazan-rest-porting.php';
		require_once $dir . 'rest/class-yazan-rest-webhooks.php';
		require_once $dir . 'rest/class-yazan-rest-status.php';
		require_once $dir . 'rest/class-yazan-rest-backup.php';
		require_once $dir . 'rest/class-yazan-rest-wishlist.php';

		// AI subsystem (gateway, providers, pipelines, /ai/* REST). Self-contained: it requires its
		// own classes and registers its own rest_api_init hook.
		require_once YAZAN_CORE_DIR . 'includes/ai/class-yazan-ai-boot.php';
		Yazan_AI_Boot::init();

		// Route + query var + shell.
		add_action( 'init', array( 'Yazan_Dashboard', 'add_rewrite' ), 7 );
		add_filter( 'query_vars', array( 'Yazan_Dashboard', 'add_query_var' ) );
		add_action( 'template_redirect', array( 'Yazan_Dashboard', 'maybe_render' ) );

		// Register the Yazan product meta once WooCommerce/init is up.
		add_action( 'init', array( 'Yazan_Dashboard_Fields', 'register_meta' ), 11 );

		// REST controllers.
		add_action(
			'rest_api_init',
			static function () {
				Yazan_Dashboard_Auth::register_routes();
				Yazan_REST_Products::register_routes();
				Yazan_REST_Media::register_routes();
				Yazan_REST_Meta::register_routes();
				Yazan_REST_Orders::register_routes();
				// Registered after the read controller — WordPress merges the extra POST /orders
				// endpoint onto the existing route.
				Yazan_REST_Order_Write::register_routes();
				Yazan_REST_Customers::register_routes();
				Yazan_REST_Terms::register_routes();
				Yazan_REST_Attributes::register_routes();
				Yazan_REST_Inventory::register_routes();
				Yazan_REST_Settings::register_routes();
				Yazan_REST_Audit::register_routes();
				Yazan_REST_Coupons::register_routes();
				Yazan_REST_Reports::register_routes();
				Yazan_REST_Tax::register_routes();
				Yazan_REST_Shipping::register_routes();
				Yazan_REST_Gateways::register_routes();
				Yazan_REST_Emails::register_routes();
				Yazan_REST_Porting::register_routes();
				Yazan_REST_Webhooks::register_routes();
				Yazan_REST_Status::register_routes();
				Yazan_REST_Backup::register_routes();
				Yazan_REST_Wishlist::register_routes();
			}
		);
	}
}
