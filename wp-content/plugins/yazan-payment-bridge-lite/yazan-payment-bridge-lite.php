<?php
/**
 * Plugin Name:       YAZAN Payment Bridge Lite
 * Plugin URI:        https://yazan.local
 * Description:       Lightweight, secure integration layer between WooCommerce's server-verified payment states and the YAZAN digital ecosystem. Records an auditable payment-event ledger with database-enforced exactly-once semantics, then notifies the Ownership and Warranty seams. Never processes payments and never touches card data.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.3
 * Author:            Yazan
 * License:           GPL-2.0-or-later
 * Text Domain:       yazan-payment-bridge
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   10.9
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/* -------------------------------------------------------------------------
 * Constants
 * ---------------------------------------------------------------------- */

define( 'YAZAN_PB_VERSION', '1.0.0' );
define( 'YAZAN_PB_FILE', __FILE__ );
define( 'YAZAN_PB_DIR', plugin_dir_path( __FILE__ ) );
define( 'YAZAN_PB_URL', plugin_dir_url( __FILE__ ) );
define( 'YAZAN_PB_BASENAME', plugin_basename( __FILE__ ) );

/* -------------------------------------------------------------------------
 * Autoloading
 *
 * Prefer Composer's optimised autoloader when the package has been installed,
 * but fall back to a self-contained PSR-4 autoloader so the plugin runs on a
 * stock server with no build step. Both map `Yazan\PaymentBridge\` to `src/`.
 * ---------------------------------------------------------------------- */

if ( is_readable( YAZAN_PB_DIR . 'vendor/autoload.php' ) ) {
	require YAZAN_PB_DIR . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( $class ) {
			$prefix = 'Yazan\\PaymentBridge\\';
			$len    = strlen( $prefix );
			if ( 0 !== strncmp( $prefix, $class, $len ) ) {
				return; // Not ours.
			}
			$relative = substr( $class, $len );
			$path     = YAZAN_PB_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $path ) ) {
				require $path;
			}
		}
	);
}

/* -------------------------------------------------------------------------
 * WooCommerce HPOS (custom order tables) compatibility.
 *
 * This plugin only ever reads orders through the WooCommerce CRUD API, so it is
 * compatible by construction. `cart_checkout_blocks` is deliberately NOT
 * declared: the Bridge never renders or alters checkout.
 * ---------------------------------------------------------------------- */

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', YAZAN_PB_FILE, true );
		}
	}
);

/* -------------------------------------------------------------------------
 * Multi-store declaration.
 *
 * ⚠️ BOTH FILTERS ARE ATTACHED HERE, AT PLUGIN-LOAD, AND NOT IN Plugin::boot().
 *
 * `Yazan_Permission_Registry::modules()` memoises its result into a static that
 * `Yazan_RBAC_Boot` reads on `init` priority 1. A filter attached any later is
 * never seen — and the failure is completely silent: no error, no section in the
 * Role Editor, and every `payments.*` check resolving false for everyone but a
 * platform super admin. The store engine and the homepage module both attach at
 * plugin-load for exactly this reason.
 * ---------------------------------------------------------------------- */

add_filter(
	'yazan_permission_modules',
	static function ( $modules ) {
		$modules['payments'] = array(
			'label'   => __( 'Payments', 'yazan-payment-bridge' ),
			'sort'    => 460,
			'actions' => array(
				'view'      => __( 'See the payment event log for this store.', 'yazan-payment-bridge' ),
				'retry'     => __( 'Re-run a failed payment integration.', 'yazan-payment-bridge' ),
				'configure' => __( 'Change the gateway settings for this store.', 'yazan-payment-bridge' ),
			),
		);

		return $modules;
	}
);

/*
 * The per-store feature flag. Unlike the permission filter above, the catalogue is read at request
 * time rather than memoised at boot, so its timing is relaxed — it is registered here only to keep
 * both declarations in one readable place.
 */
add_filter(
	'yazan_stores_module_catalogue',
	static function ( $modules ) {
		$modules['payment_bridge'] = true;

		return $modules;
	}
);

/* -------------------------------------------------------------------------
 * Lifecycle hooks — thin wrappers that delegate to the installer.
 * Deactivation never deletes data; see uninstall.php for the opt-in purge.
 * ---------------------------------------------------------------------- */

register_activation_hook(
	YAZAN_PB_FILE,
	static function () {
		\Yazan\PaymentBridge\Database\Installer::activate();
	}
);

/* -------------------------------------------------------------------------
 * Boot once all plugins (including WooCommerce) are loaded.
 * ---------------------------------------------------------------------- */

add_action(
	'plugins_loaded',
	static function () {
		\Yazan\PaymentBridge\Core\Plugin::instance()->boot();
	},
	20 // After WooCommerce (which boots at the default priority).
);
