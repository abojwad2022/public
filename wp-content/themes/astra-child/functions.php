<?php
/**
 * Yazan (Astra Child) — theme bootstrap.
 *
 * All custom code for the Yazan store belongs here (or in the /inc modules loaded below).
 * Never edit the Astra parent theme, WooCommerce, or WordPress core.
 *
 * Implements the code layer of the "Yazan — Premium eCommerce Design Blueprint" and the
 * "Yazan — Motion & Animation Blueprint". The Customizer / plugin / content layers are set up
 * separately (see IMPLEMENTATION.md).
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'YAZAN_VERSION', '2.8.0' );
define( 'YAZAN_DIR', get_stylesheet_directory() );
define( 'YAZAN_URI', get_stylesheet_directory_uri() );

/**
 * Theme setup: translations + WooCommerce gallery features.
 */
add_action( 'after_setup_theme', 'yazan_setup' );
function yazan_setup() {
	load_child_theme_textdomain( 'yazan', YAZAN_DIR . '/languages' );

	// Declare WooCommerce + product-gallery support (belt-and-suspenders with Astra).
	add_theme_support( 'woocommerce' );
	add_theme_support( 'wc-product-gallery-zoom' );
	add_theme_support( 'wc-product-gallery-lightbox' );
	add_theme_support( 'wc-product-gallery-slider' );
}

/**
 * Add a body class so brand styles can target the store cleanly.
 */
add_filter( 'body_class', 'yazan_body_class' );
function yazan_body_class( array $classes ) {
	$classes[] = 'yazan';
	return $classes; // Filters must always return the value.
}

/**
 * Load modules.
 */
require YAZAN_DIR . '/inc/enqueue.php';      // Fonts, styles, scripts (incl. conditional GSAP).
require YAZAN_DIR . '/inc/header.php';       // Luxury header body flags + Astra-hook integration.
require YAZAN_DIR . '/inc/theme-options.php'; // Customizer: Obsidian/Maroon theme switcher.
require YAZAN_DIR . '/inc/theme-switcher.php'; // Interchangeable Black/Burgundy themes: no-FOUC boot + floating switcher.
require YAZAN_DIR . '/inc/homepage.php';     // Front-page content getters, ACF options, layout.
require YAZAN_DIR . '/inc/demo-content.php'; // Dummy images for empty homepage slots (toggle off via filter).
require YAZAN_DIR . '/inc/home-content.php'; // Real Featured-Collections + Best-Sellers (overrides demo, pri 99).
require YAZAN_DIR . '/inc/payment-marks.php'; // Accepted-payment row (product/cart/checkout/footer).
require YAZAN_DIR . '/inc/woocommerce.php';  // Loop/product hooks: subject line, badges, gallery, ATC.
require YAZAN_DIR . '/inc/cartflows-compat.php'; // Fix CartFlows Instant-Checkout thank-you template on Windows/Local.
require YAZAN_DIR . '/inc/thankyou-enhancements.php'; // Thank-you page: progress stepper, delivery ETA, brand note, support.
require YAZAN_DIR . '/inc/my-account.php';   // My Account: scoped styling + brand-voiced menu labels.
require YAZAN_DIR . '/inc/signin-card.php';  // Sign-in / register card: account page + header dialog.
require YAZAN_DIR . '/inc/taxonomy.php';     // Redesigned IA: extra shop facets (wearer/style/shape/…).
require YAZAN_DIR . '/inc/page-authentication.php'; // /authentication/ — luxury assurance page.
require YAZAN_DIR . '/inc/page-verify-ring.php';    // /verify-ring/ — public serial lookup + certificate.
require YAZAN_DIR . '/inc/shop-filters.php'; // Shop archive: filter sidebar + toolbar (replaces chips).
require YAZAN_DIR . '/inc/promo-popup.php'; // First-order email-capture popup → WELCOME10 coupon.
require YAZAN_DIR . '/inc/chat-widget.php'; // Storefront AI concierge (yazan-core yazan/v1/ai/chat).
require YAZAN_DIR . '/inc/wishlist.php';    // Customer favourites: heart toggle + My Account tab (yazan/v1/wishlist).
require YAZAN_DIR . '/inc/schema.php';       // AI/search visibility: Product JSON-LD + dynamic /llms.txt.


/*
 * ---------------------------------------------------------------------------
 * Multi-store: the Motion Engine declares itself as a per-store module.
 * ---------------------------------------------------------------------------
 *
 * Motion is the one Yazan module with no database and no options — four asset files and an
 * IntersectionObserver. "Store-aware" therefore means two things and not a third:
 *
 *   1. it can be turned off for a store, and
 *   2. its intensity is a per-store setting,
 *
 * both read through the same `Yazan_Store_Options` mechanism every other module uses, so motion is
 * not a special case with its own configuration path.
 *
 * The theme loads AFTER every plugin, which would be fatal for the permission filter (memoised at
 * `init` priority 1) but is fine here: the module catalogue is read at request time, not at boot.
 * Motion has no permissions of its own — its settings are edited through `stores.settings`, which
 * already exists and is already store-scoped.
 */
add_filter(
	'yazan_stores_module_catalogue',
	static function ( $modules ) {
		$modules['motion'] = true;

		return $modules;
	}
);

/**
 * This store's motion settings, merged over the platform defaults.
 *
 * @return array{enabled:bool,parallax:bool,intensity:float}
 */
function yazan_motion_settings() {
	$defaults = array(
		'enabled'   => true,
		'parallax'  => true,
		'intensity' => 1.0,
	);

	$stored = class_exists( 'Yazan_Store_Options' )
		? Yazan_Store_Options::get( 'yazan_motion_settings', array() )
		: get_option( 'yazan_motion_settings', array() );

	$stored = is_array( $stored ) ? $stored : array();
	$merged = array_merge( $defaults, $stored );

	return array(
		'enabled'   => (bool) $merged['enabled'],
		'parallax'  => (bool) $merged['parallax'],
		// Clamped rather than trusted: this value reaches CSS, and a store's own setting should not
		// be able to produce a transform that breaks the layout.
		'intensity' => max( 0.0, min( 2.0, (float) $merged['intensity'] ) ),
	);
}

/**
 * Whether a per-store module is enabled.
 *
 * DEFAULT-OPEN, matching `StoreContext::module_enabled()`: a site without the store engine, or a
 * store that has said nothing, gets a working storefront rather than a blank one.
 *
 * @param string $module Module slug.
 * @return bool
 */
function yazan_store_module_on( $module ) {
	if ( ! class_exists( '\Yazan\Stores\Core\Plugin' ) ) {
		return true;
	}

	try {
		$context = \Yazan\Stores\Core\Plugin::instance()->container()->get( \Yazan\Stores\Application\StoreContext::class );
	} catch ( \Throwable $e ) {
		return true;
	}

	return $context->module_enabled( (string) $module );
}
