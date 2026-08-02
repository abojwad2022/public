<?php
/**
 * Yazan RBAC — projection into native WordPress capabilities.
 *
 * Yazan permissions are the source of truth, but WooCommerce, WordPress core and every other
 * plugin still ask `current_user_can()`. Without a bridge, a "Sales" user would pass the Yazan
 * gate and then be refused by WC_Order::save(). This class is that bridge.
 *
 * It is a RUNTIME FILTER, not a writer of real roles, and that choice is the whole safety story:
 *
 *   - It is purely ADDITIVE. It only ever does `$allcaps[$cap] = true`. It never writes false,
 *     never unsets, never emits do_not_allow. Nobody's WordPress role is modified, so the worst
 *     failure is "someone kept a WooCommerce capability inside wp-admin" rather than "the owner
 *     lost manage_options and cannot get back in to undo it".
 *   - `wp_user_roles` is a single autoloaded serialized option, and add_cap() read-modify-writes
 *     the whole thing. Two concurrent role saves would lose one permanently, with no error.
 *   - Deactivating the plugin removes every projected capability cleanly.
 *   - WooCommerce grants `edit_users` to shop managers through this exact hook, so the pattern is
 *     already idiomatic in this stack.
 *
 * RECURSION IS THE ONE REAL HAZARD. WP_User::has_cap() runs map_meta_cap() and then this filter,
 * so any current_user_can()/user_can()/has_cap() inside the filter body re-enters it forever and
 * takes down every page including wp-login.php. The body therefore calls no capability API at
 * all, and a static re-entrancy guard backs that up in case a future edit forgets.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps Yazan permissions onto the WordPress capabilities the stack actually checks.
 */
class Yazan_Cap_Projection {

	/**
	 * Bespoke capability meaning "may reach the Yazan dashboard".
	 *
	 * This IS the dashboard gate now — Yazan_Dashboard_Auth::CAP was flipped from `edit_products`,
	 * so staff are no longer over-granted product editing merely to get through the door.
	 *
	 * ⚠️ The flip was NOT the "one-line change with no migration" this docblock used to promise.
	 * project() returns early for WordPress administrators (below), so an administrator never
	 * receives this capability; gating on it alone locked every administrator out of /dashboard.
	 * Yazan_Dashboard_Auth::can_access() carries the second clause that makes it safe. Check
	 * through that method, never against this constant directly.
	 */
	const ACCESS_CAP = 'yazan_dashboard_access';

	/**
	 * Re-entrancy guard, keyed by "user id|store id".
	 *
	 * @var array<string,bool>
	 */
	private static $busy = array();

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function register() {
		// Priority 20 — after WooCommerce's own two user_has_cap filters. Ordering barely matters
		// for a purely additive filter, but running last keeps the intent obvious.
		add_filter( 'user_has_cap', array( __CLASS__, 'project' ), 20, 4 );
	}

	/**
	 * `user_has_cap`: OR in the capabilities this user's Yazan permissions imply.
	 *
	 * @param array   $allcaps All capabilities for the user.
	 * @param string[] $caps   Primitive capabilities being checked (unused — we project everything).
	 * @param array   $args    Check arguments (unused).
	 * @param WP_User $user    The user being checked.
	 * @return array
	 */
	public static function project( $allcaps, $caps, $args, $user ) {
		if ( ! ( $user instanceof WP_User ) || $user->ID <= 0 ) {
			return $allcaps;
		}

		$user_id = (int) $user->ID;

		/*
		 * The store this projection is for.
		 *
		 * Read through a class_exists() guard rather than assuming the mu-plugin: this runs inside
		 * `user_has_cap`, the single most load-bearing filter in WordPress, and a fatal here takes
		 * out wp-login.php along with everything else.
		 *
		 * ⚠️ THE STRUCTURAL LIMIT, stated plainly because it does not go away with more code:
		 * WooCommerce and WordPress ask `current_user_can( 'edit_products' )` with no store
		 * argument, so a capability projected here is necessarily projected for whichever store the
		 * REQUEST resolved to. Inside /dashboard that is right. Inside wp-admin, where there is no
		 * per-request store, staff of one store will hold WooCommerce capabilities over another
		 * store's data. No projection can fix that, because the projection is additive-only and can
		 * never REMOVE a capability. The mitigation is a policy — store staff live in /dashboard,
		 * and wp-admin stays with platform users — not a mechanism.
		 */
		$store_id = class_exists( 'Yazan_Store_Context' ) ? Yazan_Store_Context::current() : 1;

		/*
		 * Re-entrancy guard, keyed by user AND store.
		 *
		 * Keyed by user alone, a projection running inside Yazan_Store_Context::run_for() for a
		 * different store would collide with the outer one still on the stack and silently return
		 * un-projected capabilities — a permission check that fails for a reason nothing reports.
		 */
		$busy_key = $user_id . '|' . $store_id;

		if ( isset( self::$busy[ $busy_key ] ) ) {
			return $allcaps;
		}

		// Before install this whole subsystem is a no-op, so deploying it changes nothing.
		if ( ! Yazan_RBAC_Schema::is_installed() ) {
			return $allcaps;
		}

		// A real WordPress administrator already holds everything. Read the roles array directly —
		// user_can() here would recurse.
		if ( in_array( 'administrator', (array) $user->roles, true ) ) {
			return $allcaps;
		}

		self::$busy[ $busy_key ] = true;

		try {
			// A suspended account is projected NOTHING. That single fact is what makes suspension
			// work at the dashboard shell — current_user_can( Yazan_Dashboard_Auth::CAP ) turns
			// false and they get the sign-in screen, with no extra code anywhere.
			if ( Yazan_Users::is_suspended( $user_id ) ) {
				return $allcaps;
			}

			$set = Yazan_Permissions::for_user( $user_id, $store_id );

			// No Yazan role at all — a shopper, a subscriber. Leave them exactly as they were.
			if ( empty( $set['roles'] ) && empty( $set['super'] ) ) {
				return $allcaps;
			}

			foreach ( self::base_caps() as $cap ) {
				$allcaps[ $cap ] = true;
			}

			$map = self::map();
			foreach ( array_keys( $set['perms'] ) as $slug ) {
				if ( empty( $map[ $slug ] ) ) {
					continue;
				}
				foreach ( $map[ $slug ] as $cap ) {
					$allcaps[ $cap ] = true;
				}
			}

			return $allcaps;
		} finally {
			unset( self::$busy[ $busy_key ] );
		}
	}

	/**
	 * Capabilities every active Yazan-role holder gets.
	 *
	 * ACCESS_CAP is what opens the dashboard, and it is the reason this list exists.
	 *
	 * `edit_products` is still granted, but no longer because the door needs it — the gate moved to
	 * ACCESS_CAP. It stays because WooCommerce checks it on paths a staff member reaches after
	 * signing in (the product list table, media attachment to a product), and removing it would
	 * turn a clean permission denial into a WooCommerce-internal failure. Narrowing it is a
	 * separate change with its own testing, not a side effect of the gate flip.
	 *
	 * @return string[]
	 */
	private static function base_caps() {
		return array(
			'read',
			self::ACCESS_CAP,
			'edit_products',
			'upload_files',
		);
	}

	/**
	 * Yazan permission slug => WordPress capabilities to OR in.
	 *
	 * Each entry exists because something in the request path actually checks that capability —
	 * either one of our own REST controllers, or WooCommerce/WordPress internals during a save.
	 *
	 * @return array<string,string[]>
	 */
	public static function map() {
		static $map = null;
		if ( null !== $map ) {
			return $map;
		}

		$product_write = array(
			'edit_products',
			'edit_others_products',
			'edit_published_products',
			'edit_private_products',
			'publish_products',
			'read_private_products',
			'assign_product_terms',
			'upload_files',
		);

		$product_delete = array(
			'delete_products',
			'delete_others_products',
			'delete_published_products',
			'delete_private_products',
		);

		$term_manage = array(
			'manage_product_terms',
			'edit_product_terms',
			'delete_product_terms',
			'assign_product_terms',
			'manage_categories',
		);

		$order_read = array(
			'edit_shop_orders',
			'edit_others_shop_orders',
			'read_private_shop_orders',
		);

		$order_write = array_merge(
			$order_read,
			array(
				'publish_shop_orders',
				'edit_published_shop_orders',
				'edit_private_shop_orders',
			)
		);

		$coupon_write = array(
			'edit_shop_coupons',
			'edit_others_shop_coupons',
			'publish_shop_coupons',
			'edit_published_shop_coupons',
			'read_private_shop_coupons',
		);

		$woo_admin = array( 'manage_woocommerce' );
		$site_admin = array( 'manage_options' );

		$map = array(

			/* Dashboard */
			'dashboard.access'      => array( self::ACCESS_CAP, 'read' ),
			'dashboard.view'        => array( 'edit_products' ),

			/* Products */
			'products.view'         => array( 'edit_products', 'read_private_products' ),
			'products.create'       => $product_write,
			'products.edit'         => $product_write,
			'products.publish'      => $product_write,
			'products.duplicate'    => $product_write,
			'products.change_price' => $product_write,
			'products.change_stock' => $product_write,
			'products.bulk'         => $product_write,
			'products.restore'      => array_merge( $product_write, $product_delete ),
			'products.delete'       => $product_delete,
			'products.export'       => array( 'edit_products' ),
			'products.import'       => $product_write,

			/* Categories & attributes */
			'categories.view'       => array( 'edit_products' ),
			'categories.create'     => $term_manage,
			'categories.edit'       => $term_manage,
			'categories.delete'     => $term_manage,
			'attributes.view'       => array( 'edit_products' ),
			'attributes.create'     => $woo_admin,
			'attributes.edit'       => $woo_admin,
			'attributes.delete'     => $woo_admin,

			/* Inventory */
			'inventory.view'        => array( 'edit_products' ),
			'inventory.edit'        => $product_write,
			'inventory.export'      => array( 'edit_products' ),
			'inventory.import'      => $product_write,

			/* Media */
			'media.view'            => array( 'upload_files', 'edit_posts' ),
			'media.upload'          => array( 'upload_files', 'edit_posts' ),
			'media.delete'          => array( 'upload_files', 'edit_posts', 'delete_posts' ),

			/* Orders */
			'orders.view'           => $order_read,
			'orders.create'         => $order_write,
			'orders.edit'           => $order_write,
			'orders.status'         => $order_write,
			'orders.cancel'         => $order_write,
			'orders.notes'          => $order_write,
			'orders.items'          => $order_write,
			'orders.addresses'      => $order_write,
			'orders.coupons'        => $order_write,
			'orders.refund'         => $order_write,
			// The one deliberate over-grant: Yazan_REST_Order_Write gates gateway refunds on
			// manage_woocommerce, so a fine-grained permission has to project a coarse capability.
			// Contained, because only a role that actually holds this slug receives it.
			'orders.refund_gateway' => array_merge( $order_write, $woo_admin ),
			'orders.restore'        => $order_write,
			'orders.delete'         => array_merge(
				$order_write,
				array( 'delete_shop_orders', 'delete_others_shop_orders', 'delete_published_shop_orders' )
			),
			'orders.export'         => $order_read,

			/* Customers */
			'customers.view'        => array_merge( $order_read, array( 'list_users' ) ),
			'customers.export'      => array_merge( $order_read, array( 'list_users' ) ),

			/* Coupons */
			'coupons.view'          => $coupon_write,
			'coupons.create'        => $coupon_write,
			'coupons.edit'          => $coupon_write,
			'coupons.export'        => $coupon_write,
			'coupons.import'        => $coupon_write,
			'coupons.delete'        => array_merge(
				$coupon_write,
				array( 'delete_shop_coupons', 'delete_others_shop_coupons', 'delete_published_shop_coupons' )
			),

			/* Reports */
			'reports.view'          => array( 'view_woocommerce_reports' ),
			'reports.export'        => array( 'view_woocommerce_reports' ),

			/* AI */
			'ai.use'                => array( 'edit_products' ),
			'ai.insights_view'      => $woo_admin,
			'ai.configure'          => $woo_admin,
			'ai.view_logs'          => $woo_admin,

			/* Rewards (yazan-social-rewards) */
			'rewards.view'          => array( 'view_yazan_rewards' ),
			'rewards.manage'        => array( 'view_yazan_rewards', 'manage_yazan_rewards' ),

			/* Wishlist */
			'wishlist.view'         => array( 'read' ),

			/* Store configuration — every one of these controllers gates on manage_woocommerce */
			'settings.view'         => $woo_admin,
			'settings.edit'         => $woo_admin,
			'settings.export'       => $woo_admin,
			'settings.import'       => $woo_admin,
			'tax.view'              => $woo_admin,
			'tax.edit'              => $woo_admin,
			'shipping.view'         => $woo_admin,
			'shipping.edit'         => $woo_admin,
			'gateways.view'         => $woo_admin,
			'gateways.edit'         => $woo_admin,
			'emails.view'           => $woo_admin,
			'emails.edit'           => $woo_admin,
			'webhooks.view'         => $woo_admin,
			'webhooks.create'       => $woo_admin,
			'webhooks.edit'         => $woo_admin,
			'webhooks.delete'       => $woo_admin,
			'porting.export'        => $woo_admin,
			'porting.import'        => $woo_admin,
			'status.view'           => $woo_admin,

			/* Site-level operations */
			'status.tools'          => array_merge( $woo_admin, $site_admin ),
			'backup.view'           => $site_admin,
			'backup.create'         => $site_admin,
			'backup.restore'        => $site_admin,
			'backup.download'       => $site_admin,
			'backup.delete'         => $site_admin,

			/* Audit log */
			'audit.view'            => $woo_admin,
			'audit.export'          => $woo_admin,
			'audit.purge'           => $site_admin,

			/*
			 * users.* and roles.* project NOTHING, on purpose. The Yazan staff screen must never
			 * become a lever for minting a WordPress administrator. Our own controllers enforce
			 * with permission slugs; editing real WordPress users stays with real WP admins.
			 */
		);

		return $map;
	}
}
