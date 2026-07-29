<?php
/**
 * Plugin Name:       YAZAN Social Rewards & Ambassador Platform
 * Plugin URI:        https://yazan.local
 * Description:       Independent, modular loyalty, ambassador, referral, and social-engagement platform for the YAZAN luxury store. Points, store-credit wallet, rewards, campaigns, achievements/tiers, referrals, UGC/social, anti-fraud, analytics and multi-channel notifications — all built on WooCommerce without touching core, WooCommerce, the theme, or other plugins.
 * Version:           1.12.7
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Yazan
 * License:           GPL-2.0-or-later
 * Text Domain:       yazan-rewards
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   10.9
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/* -------------------------------------------------------------------------
 * Constants
 * ---------------------------------------------------------------------- */

define( 'YAZAN_REWARDS_VERSION', '1.12.7' );
define( 'YAZAN_REWARDS_FILE', __FILE__ );
define( 'YAZAN_REWARDS_DIR', plugin_dir_path( __FILE__ ) );
define( 'YAZAN_REWARDS_URL', plugin_dir_url( __FILE__ ) );
define( 'YAZAN_REWARDS_BASENAME', plugin_basename( __FILE__ ) );

/* -------------------------------------------------------------------------
 * Autoloading
 *
 * Prefer Composer's optimised autoloader when the package has been installed,
 * but fall back to a self-contained PSR-4 autoloader so the plugin runs on a
 * stock server with no build step (matching this project's "no tooling on
 * PATH" reality). Both map the `Yazan\Rewards\` namespace to `src/`.
 * ---------------------------------------------------------------------- */

if ( is_readable( YAZAN_REWARDS_DIR . 'vendor/autoload.php' ) ) {
	require YAZAN_REWARDS_DIR . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( $class ) {
			$prefix = 'Yazan\\Rewards\\';
			$len    = strlen( $prefix );
			if ( 0 !== strncmp( $prefix, $class, $len ) ) {
				return; // Not ours.
			}
			$relative = substr( $class, $len );
			$path     = YAZAN_REWARDS_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $path ) ) {
				require $path;
			}
		}
	);
}

/* -------------------------------------------------------------------------
 * Developer registration API — global yazan_register_*() helpers for add-ons.
 * PSR-4 autoloads classes only, so this procedural file is required explicitly,
 * before the module/connector/event filters fire at plugins_loaded priority 20.
 * ---------------------------------------------------------------------- */

require YAZAN_REWARDS_DIR . 'inc/registration.php';

/* -------------------------------------------------------------------------
 * WooCommerce HPOS (custom order tables) compatibility.
 *
 * This plugin only ever touches orders through the WooCommerce CRUD API, so it
 * is compatible by construction. Declared before WooCommerce initialises.
 * ---------------------------------------------------------------------- */

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', YAZAN_REWARDS_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', YAZAN_REWARDS_FILE, true );
		}
	}
);

/* -------------------------------------------------------------------------
 * Lifecycle hooks — thin wrappers that delegate to the framework.
 * ---------------------------------------------------------------------- */

register_activation_hook(
	YAZAN_REWARDS_FILE,
	static function () {
		\Yazan\Rewards\Core\Install\Activator::activate();
	}
);

register_deactivation_hook(
	YAZAN_REWARDS_FILE,
	static function () {
		\Yazan\Rewards\Core\Install\Deactivator::deactivate();
	}
);

/* -------------------------------------------------------------------------
 * Boot the platform once all plugins (incl. WooCommerce) are loaded.
 * ---------------------------------------------------------------------- */

add_action(
	'plugins_loaded',
	static function () {
		\Yazan\Rewards\Core\Plugin::instance()->boot();
	},
	20 // After WooCommerce (which boots at the default priority).
);
