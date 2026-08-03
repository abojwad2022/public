<?php
/**
 * Plugin Name:       Yazan Core
 * Plugin URI:        https://yazan.local
 * Description:       Portable store architecture for Yazan — product categories, the Collections taxonomy, and the professional ring attribute set. Kept in a plugin (not the theme) so the catalog structure survives theme changes.
 * Version:           1.8.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Yazan
 * Text Domain:       yazan
 * WC requires at least: 8.0
 * WC tested up to:   10.9
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'YAZAN_CORE_VERSION', '1.8.0' );
define( 'YAZAN_CORE_FILE', __FILE__ );
define( 'YAZAN_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'YAZAN_CORE_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare HPOS (High-Performance Order Storage) compatibility so WooCommerce never flags this
 * plugin. We touch no order storage, so we are compatible by construction.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', YAZAN_CORE_FILE, true );
		}
	}
);

// First: the table-name authority. Everything with a schema below is named through it.
require YAZAN_CORE_DIR . 'includes/class-yazan-db.php';

// Raw DDL for the things dbDelta cannot express — widening a UNIQUE key, rebuilding a PRIMARY KEY.
require YAZAN_CORE_DIR . 'includes/class-yazan-schema-migrator.php';
require YAZAN_CORE_DIR . 'includes/class-yazan-store-schema.php';

/*
 * Per-store option resolution. Loaded HERE, before any module, because every module's settings
 * accessor reads through it — and those accessors are reachable from the boot calls further down
 * this file, which run synchronously at plugin-load rather than on a hook.
 */
require YAZAN_CORE_DIR . 'includes/class-yazan-store-options.php';

// Store-registry events -> yazan-core's modules. Registered at plugin-load: the engine boots later,
// and a listener attached after it has finished dispatching would never hear anything.
require YAZAN_CORE_DIR . 'includes/class-yazan-store-events.php';
Yazan_Store_Events::init();

// Catalogue tenancy: the `yazan_store` taxonomy on products and `_yazan_store_id` on orders.
require YAZAN_CORE_DIR . 'includes/class-yazan-store-catalogue.php';

/*
 * The store-aware public API. Loaded at plugin-load because `determine_current_user` can fire
 * before `init` on some paths, and a token that arrives before its verifier exists is an
 * anonymous request that returns 200 with less data — the quietest failure this surface has.
 */
require YAZAN_CORE_DIR . 'includes/api/class-yazan-api-tokens.php';
require YAZAN_CORE_DIR . 'includes/api/class-yazan-api.php';
Yazan_API::init();

require YAZAN_CORE_DIR . 'includes/class-yazan-core-taxonomies.php';
require YAZAN_CORE_DIR . 'includes/class-yazan-core-menu.php';
require YAZAN_CORE_DIR . 'includes/class-yazan-core-verify.php';
require YAZAN_CORE_DIR . 'includes/class-yazan-core-installer.php';
require YAZAN_CORE_DIR . 'includes/class-yazan-core-notifications.php';
require YAZAN_CORE_DIR . 'includes/class-yazan-core-auto-print.php';
require YAZAN_CORE_DIR . 'includes/class-yazan-core-list-print.php';
require YAZAN_CORE_DIR . 'includes/class-yazan-core-privacy.php';
require YAZAN_CORE_DIR . 'includes/class-yazan-core-headers.php';

// Answer on one host: send yazan.local ⇄ localhost:PORT traffic to whichever one WP_HOME names, so
// the dashboard's module bundle and every same-origin fetch keep working while OAuth testing moves
// the site between them.
require YAZAN_CORE_DIR . 'includes/class-yazan-canonical-host.php';
Yazan_Canonical_Host::register();

// Full-site backup & restore engine (shared by the dashboard REST controller and the wp-admin page).
require YAZAN_CORE_DIR . 'includes/backup/class-yazan-backup-engine.php';

// One-tap sign-in with Google / Apple. Inert until the provider constants are defined in
// wp-config.php — see includes/social-auth/README.md.
require YAZAN_CORE_DIR . 'includes/social-auth/class-yazan-social-auth-store.php';
require YAZAN_CORE_DIR . 'includes/social-auth/class-yazan-social-auth-jwt.php';
require YAZAN_CORE_DIR . 'includes/social-auth/class-yazan-social-auth-provider.php';
require YAZAN_CORE_DIR . 'includes/social-auth/class-yazan-social-auth-google.php';
require YAZAN_CORE_DIR . 'includes/social-auth/class-yazan-social-auth-apple.php';
require YAZAN_CORE_DIR . 'includes/social-auth/class-yazan-social-auth-users.php';
require YAZAN_CORE_DIR . 'includes/social-auth/class-yazan-social-auth-cart.php';
require YAZAN_CORE_DIR . 'includes/social-auth/class-yazan-social-auth-ui.php';
require YAZAN_CORE_DIR . 'includes/social-auth/class-yazan-social-auth.php';
Yazan_Social_Auth::register();

/*
 * Homepage Manager: the visual homepage builder module.
 *
 * Loaded BEFORE RBAC on purpose. It contributes its permissions through the
 * `yazan_permission_modules` filter, and Yazan_Permission_Registry::modules() memoises its result
 * the first time anything asks for it — so the filter has to be attached before RBAC's installer
 * runs on `init` priority 1. Inert until its own installer has created its tables.
 */
require YAZAN_CORE_DIR . 'includes/homepage/class-yazan-homepage-boot.php';
Yazan_Homepage_Boot::init();

/*
 * Role-based access control: roles, the permission catalog, and the projection of Yazan
 * permissions onto native WordPress capabilities.
 *
 * Initialised BEFORE the dashboard on purpose — the dashboard's first act on a request is a
 * capability check, and it must already see the projected capabilities. Inert until its tables
 * exist, so deploying it changes nothing until the installer has run.
 */
require YAZAN_CORE_DIR . 'includes/rbac/class-yazan-rbac-boot.php';
Yazan_RBAC_Boot::init();

// Standalone product-management dashboard (/dashboard): route, REST API, field registry, audit log.
require YAZAN_CORE_DIR . 'includes/dashboard/class-yazan-dashboard-boot.php';
Yazan_Dashboard_Boot::init();

// Tools → Yazan Backup: the wp-admin backup/restore screen (same archives as the dashboard tab).
require YAZAN_CORE_DIR . 'includes/backup/class-yazan-backup-admin.php';
Yazan_Backup_Admin::init();

/*
 * Multi-tenant schema migration.
 *
 * Priority 3 — deliberately AFTER every installer that creates the tables it alters: RBAC, the
 * rewards migrator and the payment-bridge installer all run at `init` priority 1, and the homepage
 * installer at 2. Altering a table before its creator has run is a silent no-op that would then be
 * stamped as complete; altering one after is harmless, because dbDelta is additive and the column
 * is already there.
 *
 * `init` rather than `admin_init` for the reason the sibling installers document: this store is
 * operated from /dashboard and wp-admin may never be loaded.
 */
add_action( 'init', array( 'Yazan_Store_Schema', 'maybe_migrate' ), 3 );

// Register the Collections taxonomy on every load (front + admin) so it always exists.
add_action( 'init', array( 'Yazan_Core_Taxonomies', 'register' ), 5 );

/*
 * Catalogue tenancy. Priority 4 — BEFORE the Collections taxonomy and before anything that could
 * run a product query, because a query issued before the scoping filters are attached returns every
 * tenant's catalogue and looks entirely correct doing it.
 */
add_action( 'init', array( 'Yazan_Store_Catalogue', 'register' ), 4 );
add_action( 'init', array( 'Yazan_Store_Catalogue', 'maybe_backfill' ), 20 );

// Public ring-verification route: /verify-ring/{serial}/ + the serial query var.
add_action( 'init', array( 'Yazan_Core_Verify', 'add_rewrite' ), 6 );
add_filter( 'query_vars', array( 'Yazan_Core_Verify', 'add_query_var' ) );

// New-order notifications: owner browser alert (Heartbeat), email hardening, SMS scaffold.
add_action( 'init', array( 'Yazan_Core_Notifications', 'register' ) );

// Auto-print the invoice when an order is marked Completed (kiosk-print bridge).
add_action( 'init', array( 'Yazan_Core_Auto_Print', 'register' ) );

// "Print" button next to the preview (eye) button in the orders list.
add_action( 'init', array( 'Yazan_Core_List_Print', 'register' ) );

/*
 * User-data lifecycle: anonymise audit-log and AI-generation rows when a user is
 * deleted, and expose both to WordPress's export/erase tools.
 *
 * Registered immediately rather than on `init` — a user can be deleted from
 * wp-admin, REST, WP-CLI or cron, and `deleted_user` must already be listening
 * in every one of those contexts or the rows are orphaned.
 */
Yazan_Core_Privacy::register();

// Security response headers (portable fallback; nginx is the preferred place —
// see conf/nginx/yazan-hardening.conf.example).
Yazan_Core_Headers::register();

// Register the primary menu location so the built menu (and the header builder) can target it.
add_action(
	'after_setup_theme',
	static function () {
		register_nav_menu( Yazan_Core_Menu::LOCATION, __( 'Yazan Primary', 'yazan' ) );
	}
);

/**
 * Run the idempotent installer when the structure version changes. Runs in admin only and requires
 * WooCommerce, because global attributes depend on it. Safe to run repeatedly — it never deletes or
 * overwrites existing terms, it only fills what is missing.
 */
add_action(
	'admin_init',
	static function () {
		if ( get_option( 'yazan_core_structure_version' ) === YAZAN_CORE_VERSION ) {
			return;
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			return; // Attributes need WooCommerce; try again on the next admin load.
		}
		Yazan_Core_Installer::install();
		update_option( 'yazan_core_structure_version', YAZAN_CORE_VERSION, false );
	}
);

// Flush rewrite rules once on activation so the /collection/ archive URLs resolve immediately.
register_activation_hook(
	YAZAN_CORE_FILE,
	static function () {
		Yazan_Core_Taxonomies::register();
		flush_rewrite_rules();
	}
);

register_deactivation_hook( YAZAN_CORE_FILE, 'flush_rewrite_rules' );

/**
 * Admin CSS — only on the product taxonomy list tables, to fix the SureRank column squeeze.
 *
 * @param string $hook_suffix Current admin page.
 * @return void
 */
add_action(
	'admin_enqueue_scripts',
	static function ( $hook_suffix ) {
		if ( 'edit-tags.php' !== $hook_suffix ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return; // Only product categories / collections / attribute terms.
		}
		wp_enqueue_style(
			'yazan-core-admin',
			YAZAN_CORE_URL . 'assets/css/admin.css',
			array(),
			YAZAN_CORE_VERSION
		);
	}
);

/**
 * One-time admin notice summarising what the installer created.
 */
add_action(
	'admin_notices',
	static function () {
		$report = get_transient( 'yazan_core_install_report' );
		if ( ! $report ) {
			return;
		}
		delete_transient( 'yazan_core_install_report' );
		printf(
			'<div class="notice notice-success is-dismissible"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'Yazan Core:', 'yazan' ),
			esc_html( $report )
		);
	}
);
