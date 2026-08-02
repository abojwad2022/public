<?php
/**
 * Yazan RBAC — the permission catalog.
 *
 * This file is the SOURCE OF TRUTH for what permissions exist. Runtime resolution
 * ({@see Yazan_Permissions::can()}) reads this array and never queries the `yazan_permissions`
 * table — the table is a materialised projection kept in sync by {@see self::sync_catalog()} so
 * the catalog can be reported on in SQL, shipped in a database backup, and inspected by ops.
 *
 * Permissions are `module.action`. Modules that this store has not built yet are declared with
 * `'status' => 'planned'`: they exist in the catalog, can be granted, are rendered greyed-out in
 * the Role Editor, and start working the day the module ships — no migration, no re-seed.
 *
 * Adding a permission: add it below and bump REGISTRY_VERSION.
 * Renaming one: add the old→new pair to RENAMES *and* bump REGISTRY_VERSION, so existing grants
 * are carried across instead of silently disappearing.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The code-defined permission catalog.
 */
class Yazan_Permission_Registry {

	/** Bump whenever the catalog below changes. Drives sync_catalog() and the cache key. */
	const REGISTRY_VERSION = '4';

	/** Option storing the synced registry version. */
	const VERSION_OPTION = 'yazan_rbac_registry_version';

	/** A permission that exists and is enforced today. */
	/**
	 * Permission SCOPE — the axis that decides which grants can satisfy a slug.
	 *
	 * Orthogonal to STATUS (shipped / not shipped). Status answers "does this exist yet"; scope
	 * answers "whose data does using it touch".
	 *
	 * ⚠️ THIS IS THE PROPERTY WHOSE ABSENCE WAS THE ESCALATION BUG. Role memberships became
	 * per-store, but nothing recorded that `stores.create` reaches the whole platform while
	 * `products.edit` reaches one tenant — so a role granted only in store 2 could hold
	 * `stores.create` and genuinely create stores. The check was scoped; the effect was not.
	 */
	const SCOPE_PLATFORM = 'platform';

	/** Affects one tenant's data only. The default — a new module is store-scoped unless it says otherwise. */
	const SCOPE_STORE = 'store';

	const STATUS_ACTIVE = 'active';

	/** A permission for a module that has not shipped yet. Grantable, inert until the module exists. */
	const STATUS_PLANNED = 'planned';

	/** A permission that left the catalog. Never deleted, so existing grants stay visible. */
	const STATUS_RETIRED = 'retired';

	/**
	 * Slug renames, applied once per registry version as an UPDATE on the grants pivot.
	 *
	 * @var array<string,string> old slug => new slug.
	 */
	const RENAMES = array();

	/**
	 * Human labels for the standard action verbs, so each module only lists the verbs it supports.
	 *
	 * @return array<string,string>
	 */
	private static function action_labels() {
		return array(
			'access'          => __( 'Access', 'yazan' ),
			'view'            => __( 'View', 'yazan' ),
			'create'          => __( 'Create', 'yazan' ),
			'edit'            => __( 'Edit', 'yazan' ),
			'delete'          => __( 'Delete', 'yazan' ),
			'export'          => __( 'Export', 'yazan' ),
			'import'          => __( 'Import', 'yazan' ),
			'approve'         => __( 'Approve', 'yazan' ),
			'publish'         => __( 'Publish', 'yazan' ),
			'restore'         => __( 'Restore', 'yazan' ),
			'duplicate'       => __( 'Duplicate', 'yazan' ),
			'upload'          => __( 'Upload', 'yazan' ),
			'manage'          => __( 'Manage', 'yazan' ),
			'use'             => __( 'Use', 'yazan' ),
			'send'            => __( 'Send', 'yazan' ),
			'reply'           => __( 'Reply', 'yazan' ),
			'revoke'          => __( 'Revoke', 'yazan' ),
			'purge'           => __( 'Purge', 'yazan' ),
			'tools'           => __( 'Run tools', 'yazan' ),
			'status'          => __( 'Change status', 'yazan' ),
			'cancel'          => __( 'Cancel', 'yazan' ),
			'refund'          => __( 'Refund', 'yazan' ),
			'refund_gateway'  => __( 'Refund via gateway', 'yazan' ),
			'notes'           => __( 'Add notes', 'yazan' ),
			'items'           => __( 'Edit line items', 'yazan' ),
			'addresses'       => __( 'Edit addresses', 'yazan' ),
			'coupons'         => __( 'Apply coupons', 'yazan' ),
			'change_price'    => __( 'Change price', 'yazan' ),
			'change_stock'    => __( 'Change stock', 'yazan' ),
			'bulk'            => __( 'Bulk actions', 'yazan' ),
			'suspend'         => __( 'Suspend', 'yazan' ),
			'reset_password'  => __( 'Reset password', 'yazan' ),
			'force_logout'    => __( 'Force logout', 'yazan' ),
			'view_activity'   => __( 'View activity', 'yazan' ),
			'wp_role'         => __( 'Change WordPress role', 'yazan' ),
			'insights_view'   => __( 'View insights', 'yazan' ),
			'configure'       => __( 'Configure', 'yazan' ),
			'view_logs'       => __( 'View logs', 'yazan' ),
			'download'        => __( 'Download', 'yazan' ),
			'adjust'          => __( 'Adjust', 'yazan' ),
		);
	}

	/**
	 * The catalog, grouped by module.
	 *
	 * `actions` maps an action key to an optional description. A null description means "the
	 * generic verb is self-explanatory"; anything money-touching or destructive gets a sentence,
	 * because that text is what an operator reads in the Role Editor before ticking the box.
	 *
	 * @return array<string,array{label:string,status:string,sort:int,actions:array<string,?string>}>
	 */
	public static function modules() {
		static $modules = null;
		if ( null !== $modules ) {
			return $modules;
		}

		$modules = array(

			/* ---------------------------------------------------------- Core */

			'dashboard'     => array(
				'label'   => __( 'Dashboard', 'yazan' ),
				'sort'    => 10,
				'actions' => array(
					'access' => __( 'Sign in to the dashboard at all. Every role needs this.', 'yazan' ),
					'view'   => __( 'See the home screen and its store totals.', 'yazan' ),
				),
			),

			/* ------------------------------------------------------- Catalog */

			'products'      => array(
				'label'   => __( 'Products', 'yazan' ),
				'sort'    => 20,
				'actions' => array(
					'view'         => null,
					'create'       => null,
					'edit'         => null,
					'delete'       => __( 'Move products to trash, and delete them permanently.', 'yazan' ),
					'publish'      => __( 'Take a product from draft to live on the storefront.', 'yazan' ),
					'restore'      => __( 'Restore a trashed product.', 'yazan' ),
					'duplicate'    => null,
					'change_price' => __( 'Edit regular and sale prices. Separate from general editing on purpose.', 'yazan' ),
					'change_stock' => __( 'Edit stock quantity and stock status.', 'yazan' ),
					'bulk'         => __( 'Apply an action to many products at once.', 'yazan' ),
					'export'       => null,
					'import'       => null,
				),
			),

			'categories'    => array(
				'label'   => __( 'Categories', 'yazan' ),
				'sort'    => 30,
				'actions' => array(
					'view'   => null,
					'create' => null,
					'edit'   => null,
					'delete' => null,
				),
			),

			'attributes'    => array(
				'label'   => __( 'Attributes', 'yazan' ),
				'sort'    => 40,
				'actions' => array(
					'view'   => null,
					'create' => __( 'Create a global attribute such as Stone or Metal.', 'yazan' ),
					'edit'   => null,
					'delete' => __( 'Deleting an attribute removes it from every product using it.', 'yazan' ),
				),
			),

			'inventory'     => array(
				'label'   => __( 'Inventory', 'yazan' ),
				'sort'    => 50,
				'actions' => array(
					'view'   => null,
					'edit'   => __( 'Bulk-edit stock levels from the inventory screen.', 'yazan' ),
					'export' => null,
					'import' => null,
				),
			),

			'collections'   => array(
				'label'   => __( 'Collections', 'yazan' ),
				'status'  => self::STATUS_PLANNED,
				'sort'    => 60,
				'actions' => array(
					'view'    => null,
					'create'  => null,
					'edit'    => null,
					'delete'  => null,
					'publish' => null,
				),
			),

			'media'         => array(
				'label'   => __( 'Media', 'yazan' ),
				'sort'    => 70,
				'actions' => array(
					'view'   => null,
					'upload' => null,
					'delete' => __( 'Permanently delete a file from the media library.', 'yazan' ),
				),
			),

			/* --------------------------------------------------------- Sales */

			'orders'        => array(
				'label'   => __( 'Orders', 'yazan' ),
				'sort'    => 100,
				'actions' => array(
					'view'           => null,
					'create'         => __( 'Create an order manually on a customer\'s behalf.', 'yazan' ),
					'edit'           => null,
					'delete'         => __( 'Move an order to trash. Prefer cancelling.', 'yazan' ),
					'restore'        => null,
					'status'         => __( 'Move an order between statuses (processing, completed…).', 'yazan' ),
					'cancel'         => __( 'Cancel an order.', 'yazan' ),
					'refund'         => __( 'Record a refund against an order. Money movement — grant carefully.', 'yazan' ),
					'refund_gateway' => __( 'Send the refund to the payment gateway, moving real money. Also grants WooCommerce admin capability.', 'yazan' ),
					'notes'          => null,
					'items'          => __( 'Add, remove or reprice the line items on an existing order.', 'yazan' ),
					'addresses'      => __( 'Edit the billing and shipping addresses on an order.', 'yazan' ),
					'coupons'        => __( 'Add or remove a coupon on an existing order.', 'yazan' ),
					'export'         => null,
				),
			),

			'customers'     => array(
				'label'   => __( 'Customers', 'yazan' ),
				'sort'    => 110,
				'actions' => array(
					'view'   => __( 'Browse shoppers and their order history. Personal data — grant deliberately.', 'yazan' ),
					'export' => null,
				),
			),

			'coupons'       => array(
				'label'   => __( 'Coupons', 'yazan' ),
				'sort'    => 120,
				'actions' => array(
					'view'   => null,
					'create' => __( 'Create a discount code. Affects revenue.', 'yazan' ),
					'edit'   => null,
					'delete' => null,
					'export' => null,
					'import' => null,
				),
			),

			'reviews'       => array(
				'label'   => __( 'Reviews', 'yazan' ),
				'status'  => self::STATUS_PLANNED,
				'sort'    => 130,
				'actions' => array(
					'view'    => null,
					'approve' => null,
					'reply'   => null,
					'edit'    => null,
					'delete'  => null,
					'export'  => null,
				),
			),

			'membership'    => array(
				'label'   => __( 'Membership', 'yazan' ),
				'status'  => self::STATUS_PLANNED,
				'sort'    => 140,
				'actions' => array(
					'view'    => null,
					'create'  => null,
					'edit'    => null,
					'delete'  => null,
					'approve' => null,
					'publish' => null,
					'export'  => null,
				),
			),

			'rewards'       => array(
				'label'   => __( 'Rewards', 'yazan' ),
				'sort'    => 150,
				'actions' => array(
					'view'   => __( 'Read the Yazan Rewards screens.', 'yazan' ),
					'manage' => __( 'Change rules, points and campaigns in Yazan Rewards.', 'yazan' ),
				),
			),

			'wishlist'      => array(
				'label'   => __( 'Wishlist', 'yazan' ),
				'sort'    => 160,
				'actions' => array(
					'view' => null,
				),
			),

			/* -------------------------------------------------- Intelligence */

			'reports'       => array(
				'label'   => __( 'Reports', 'yazan' ),
				'sort'    => 200,
				'actions' => array(
					'view'   => __( 'See sales and stock reports, including revenue.', 'yazan' ),
					'export' => null,
				),
			),

			'analytics'     => array(
				'label'   => __( 'Analytics', 'yazan' ),
				'status'  => self::STATUS_PLANNED,
				'sort'    => 210,
				'actions' => array(
					'view'   => null,
					'export' => null,
				),
			),

			'ai'            => array(
				'label'   => __( 'AI Studio', 'yazan' ),
				'sort'    => 220,
				'actions' => array(
					'use'           => __( 'Generate product copy, SEO and marketing text.', 'yazan' ),
					'insights_view' => __( 'See AI insights over store data.', 'yazan' ),
					'configure'     => __( 'Set providers, models, API credentials and spend limits.', 'yazan' ),
					'view_logs'     => __( 'Read the AI generation log, including token cost.', 'yazan' ),
				),
			),

			/* -------------------------------------------------------- System */

			'settings'      => array(
				'label'   => __( 'Settings', 'yazan' ),
				'sort'    => 300,
				'actions' => array(
					'view'   => null,
					'edit'   => __( 'Change store-wide settings.', 'yazan' ),
					'export' => null,
					'import' => null,
				),
			),

			'tax'           => array(
				'label'   => __( 'Tax', 'yazan' ),
				'sort'    => 310,
				'actions' => array(
					'view' => null,
					'edit' => __( 'Change tax classes and rates. Affects every order total.', 'yazan' ),
				),
			),

			'shipping'      => array(
				'label'   => __( 'Shipping', 'yazan' ),
				'sort'    => 320,
				'actions' => array(
					'view' => null,
					'edit' => __( 'Change shipping zones, methods and costs.', 'yazan' ),
				),
			),

			'gateways'      => array(
				'label'   => __( 'Payments', 'yazan' ),
				'sort'    => 330,
				'actions' => array(
					'view' => null,
					'edit' => __( 'Enable, disable and configure payment gateways.', 'yazan' ),
				),
			),

			'emails'        => array(
				'label'   => __( 'Emails', 'yazan' ),
				'sort'    => 340,
				'actions' => array(
					'view' => null,
					'edit' => __( 'Change transactional email content and recipients.', 'yazan' ),
				),
			),

			'webhooks'      => array(
				'label'   => __( 'Webhooks', 'yazan' ),
				'sort'    => 350,
				'actions' => array(
					'view'   => null,
					'create' => __( 'Webhooks send store data to an external URL.', 'yazan' ),
					'edit'   => null,
					'delete' => null,
				),
			),

			'porting'       => array(
				'label'   => __( 'Import / Export', 'yazan' ),
				'sort'    => 360,
				'actions' => array(
					'export' => __( 'Download store data as a file.', 'yazan' ),
					'import' => __( 'Upload a file that writes store data in bulk.', 'yazan' ),
				),
			),

			'status'        => array(
				'label'   => __( 'System status', 'yazan' ),
				'sort'    => 370,
				'actions' => array(
					'view'  => null,
					'tools' => __( 'Run maintenance tools such as cache clearing and regeneration.', 'yazan' ),
				),
			),

			'backup'        => array(
				'label'   => __( 'Backups', 'yazan' ),
				'sort'    => 380,
				'actions' => array(
					'view'     => null,
					'create'   => null,
					'restore'  => __( 'Overwrite the live site with an archive. The most destructive action in the dashboard.', 'yazan' ),
					'download' => __( 'Download an archive containing the whole database.', 'yazan' ),
					'delete'   => null,
				),
			),

			'audit'         => array(
				'label'   => __( 'Activity log', 'yazan' ),
				'sort'    => 390,
				'actions' => array(
					'view'   => __( 'Read the record of every write made through the dashboard.', 'yazan' ),
					'export' => null,
					'purge'  => __( 'Delete old log entries. This erases accountability history.', 'yazan' ),
				),
			),

			'banners'       => array(
				'label'   => __( 'Banners', 'yazan' ),
				'status'  => self::STATUS_PLANNED,
				'sort'    => 400,
				'actions' => array(
					'view'    => null,
					'create'  => null,
					'edit'    => null,
					'delete'  => null,
					'publish' => null,
					'restore' => null,
				),
			),

			'notifications' => array(
				'label'   => __( 'Notifications', 'yazan' ),
				'status'  => self::STATUS_PLANNED,
				'sort'    => 410,
				'actions' => array(
					'view'   => null,
					'create' => null,
					'edit'   => null,
					'delete' => null,
					'send'   => null,
				),
			),

			'api_keys'      => array(
				'label'   => __( 'API keys', 'yazan' ),
				'status'  => self::STATUS_PLANNED,
				'sort'    => 420,
				'actions' => array(
					'view'   => null,
					'create' => null,
					'edit'   => null,
					'revoke' => null,
					'delete' => null,
				),
			),

			'pages'         => array(
				'label'   => __( 'Pages', 'yazan' ),
				'status'  => self::STATUS_PLANNED,
				'sort'    => 430,
				'actions' => array(
					'view'    => null,
					'create'  => null,
					'edit'    => null,
					'delete'  => null,
					'publish' => null,
					'restore' => null,
				),
			),

			/* -------------------------------------------------------- Access */

			'users'         => array(
				'label'   => __( 'Users', 'yazan' ),
				'sort'    => 500,
				'actions' => array(
					'view'           => __( 'See the staff list.', 'yazan' ),
					'create'         => __( 'Create a staff account that can sign into the dashboard.', 'yazan' ),
					'edit'           => null,
					'delete'         => __( 'Permanently delete a staff account.', 'yazan' ),
					'suspend'        => __( 'Block an account from signing in, without deleting it.', 'yazan' ),
					'reset_password' => __( 'Send a reset link, or set a new password directly.', 'yazan' ),
					'force_logout'   => __( 'End every active session for an account, on all devices.', 'yazan' ),
					'view_activity'  => __( 'Read one account\'s audit trail.', 'yazan' ),
					'wp_role'        => __( 'Change an account\'s WordPress role. A WordPress administrator bypasses every Yazan permission, so this is the switch that makes Yazan roles actually bite. Super admins only.', 'yazan' ),
					'export'         => null,
					'import'         => null,
				),
			),

			'roles'         => array(
				'label'   => __( 'Roles', 'yazan' ),
				'sort'    => 510,
				'actions' => array(
					'view'      => null,
					'create'    => __( 'Create a role and choose its permissions.', 'yazan' ),
					'edit'      => __( 'Change what a role may do. You can never grant a permission you do not hold.', 'yazan' ),
					'delete'    => null,
					'duplicate' => null,
					'export'    => null,
				),
			),

			'permissions'   => array(
				'label'   => __( 'Permissions', 'yazan' ),
				'sort'    => 520,
				'actions' => array(
					'view'   => __( 'Browse the permission catalog.', 'yazan' ),
					'manage' => __( 'Reserved for future catalog administration.', 'yazan' ),
				),
			),
		);

		/**
		 * Filter the permission catalog's modules.
		 *
		 * The extension point that lets a module declare its own permissions instead of this file
		 * growing a hard-coded block per feature. A module registers here and its slugs appear in
		 * the Role Editor, in the grants pivot, and in every guard — with no change to this class.
		 *
		 * TIMING: the result of this method is memoised in a static below, and
		 * Yazan_RBAC_Boot::maybe_install() reads it on `init` priority 1. A filter attached later
		 * than plugin-load time is therefore never seen, and the failure is SILENT — the module's
		 * permissions simply do not exist. Attach at plugin load, not on `init`.
		 *
		 * @param array<string,array{label:string,status?:string,sort:int,actions:array<string,?string>}> $modules Modules.
		 */
		$modules = (array) apply_filters( 'yazan_permission_modules', $modules );

		return $modules;
	}

	/**
	 * The whole catalog, flattened to `slug => spec`.
	 *
	 * @return array<string,array{slug:string,module:string,module_label:string,action:string,label:string,description:string,status:string,sort:int}>
	 */
	/**
	 * Modules whose every action reaches beyond a single tenant.
	 *
	 * Declared as a list rather than a `scope` key per module so the classification is reviewable in
	 * one place — the question "which permissions cross tenants" should be answerable by reading
	 * fifteen lines, not by grepping forty module definitions.
	 *
	 * The reasoning, per entry:
	 *
	 *   stores      — create/clone have no target tenant; `domains` rewrites `yazan_store_hostmap`,
	 *                 which decides which store EVERY future request belongs to. Editing store 2's
	 *                 domain to store 1's host is a tenancy takeover. (The targeted read/edit
	 *                 actions are re-classified back to store scope below.)
	 *   users       — WordPress users are one global table; create/delete/suspend cross every store.
	 *   roles       — role DEFINITIONS are global rows with a global unique slug. Editing a role in
	 *   permissions   store 2 edits store 1's copy.
	 *   backup      — operates on the whole database.
	 *   porting     — bulk import/export through code paths with no store predicate.
	 *   status      — cache flush and regeneration, process-wide.
	 *   settings    — `wp_options`. Per-store settings live in wp_yazan_store_settings and are
	 *                 reached through `stores.settings`, which stays store-scoped.
	 *   audit       — one table; `audit.purge` erases every tenant's history.
	 *   webhooks    — global endpoints and credentials.
	 *   api_keys
	 *
	 * @var string[]
	 */
	const PLATFORM_MODULES = array(
		'stores',
		'users',
		'roles',
		'permissions',
		'backup',
		'porting',
		'status',
		'settings',
		'audit',
		'webhooks',
		'api_keys',
	);

	/**
	 * Slugs inside a platform module that are nonetheless store-scoped.
	 *
	 * These take an explicit `{id}` and StoreAdminContext::authorise() already checks the caller's
	 * permission IN that store — so the scope of the check and the scope of the effect match, which
	 * is exactly the property platform scope exists to enforce.
	 *
	 * @var string[]
	 */
	const STORE_SCOPED_EXCEPTIONS = array(
		'stores.view',
		'stores.edit',
		'stores.settings',
		'stores.modules',
		'audit.view',
		'audit.export',
		'settings.view',
	);

	/**
	 * The scope of one slug.
	 *
	 * An unknown slug is treated as PLATFORM. Fail closed: a permission nobody classified is more
	 * likely to be a new platform capability nobody thought about than a harmless store read, and
	 * the cost of being wrong in that direction is an outage rather than a leak.
	 *
	 * @param string $slug Permission slug.
	 * @return string One of SCOPE_PLATFORM | SCOPE_STORE.
	 */
	public static function scope( $slug ) {
		$slug = (string) $slug;

		if ( in_array( $slug, self::STORE_SCOPED_EXCEPTIONS, true ) ) {
			return self::SCOPE_STORE;
		}

		$module = strtok( $slug, '.' );

		return in_array( $module, self::PLATFORM_MODULES, true ) ? self::SCOPE_PLATFORM : self::SCOPE_STORE;
	}

	/** Convenience. @param string $slug Slug. @return bool */
	public static function is_platform( $slug ) {
		return self::SCOPE_PLATFORM === self::scope( $slug );
	}

	/**
	 * Every platform-scoped slug in the catalogue.
	 *
	 * @return string[]
	 */
	public static function platform_slugs() {
		return array_values( array_filter( array_keys( self::all() ), array( __CLASS__, 'is_platform' ) ) );
	}

	public static function all() {
		static $all = null;
		if ( null !== $all ) {
			return $all;
		}

		$labels = self::action_labels();
		$all    = array();
		$order  = 0;

		foreach ( self::modules() as $module => $spec ) {
			$module_status = $spec['status'] ?? self::STATUS_ACTIVE;

			foreach ( $spec['actions'] as $action => $description ) {
				$slug = $module . '.' . $action;
				++$order;

				$all[ $slug ] = array(
					'slug'         => $slug,
					'module'       => $module,
					'module_label' => $spec['label'],
					'action'       => $action,
					'label'        => $labels[ $action ] ?? ucfirst( str_replace( '_', ' ', $action ) ),
					'description'  => (string) $description,
					'status'       => $module_status,
					'scope'        => self::scope( $slug ),
					'sort'         => ( (int) $spec['sort'] * 100 ) + $order,
				);
			}
		}

		return $all;
	}

	/**
	 * Does this slug exist in the catalog?
	 *
	 * @param string $slug Permission slug.
	 * @return bool
	 */
	public static function exists( $slug ) {
		$all = self::all();
		return isset( $all[ $slug ] );
	}

	/**
	 * Every slug in the catalog.
	 *
	 * @return string[]
	 */
	public static function slugs() {
		return array_keys( self::all() );
	}

	/**
	 * Slugs whose module has actually shipped.
	 *
	 * Used to materialise a super admin's permission map — granting them `planned` permissions
	 * would be honest but noisy, and nothing enforces them yet anyway.
	 *
	 * @return string[]
	 */
	public static function active_slugs() {
		$out = array();
		foreach ( self::all() as $slug => $spec ) {
			if ( self::STATUS_ACTIVE === $spec['status'] ) {
				$out[] = $slug;
			}
		}
		return $out;
	}

	/**
	 * The catalog shaped for the Role Editor: one entry per module, permissions nested.
	 *
	 * @return array<int,array{module:string,label:string,status:string,permissions:array}>
	 */
	public static function grouped() {
		$grouped = array();

		foreach ( self::all() as $spec ) {
			$module = $spec['module'];

			if ( ! isset( $grouped[ $module ] ) ) {
				$grouped[ $module ] = array(
					'module'      => $module,
					'label'       => $spec['module_label'],
					'status'      => $spec['status'],
					'permissions' => array(),
				);
			}

			$grouped[ $module ]['permissions'][] = array(
				'slug'        => $spec['slug'],
				'action'      => $spec['action'],
				'label'       => $spec['label'],
				'description' => $spec['description'],
				'status'      => $spec['status'],
			);
		}

		return array_values( $grouped );
	}

	/* --------------------------------------------------------------------- */
	/* Table sync                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * Mirror the catalog into `yazan_permissions`, and carry grants across renames.
	 *
	 * Nothing is ever DELETEd: a slug that left the catalog is marked `retired` so a role that
	 * still grants it stays inspectable instead of silently losing a row. Rows are upserted rather
	 * than REPLACEd because REPLACE deletes and re-inserts, which would churn ids on every deploy.
	 *
	 * @param bool $force Sync even when the stored registry version already matches.
	 * @return array{inserted:int,retired:int,renamed:int,unknown:string[]}
	 */
	public static function sync_catalog( $force = false ) {
		$result = array(
			'inserted' => 0,
			'retired'  => 0,
			'renamed'  => 0,
			'unknown'  => array(),
		);

		if ( ! Yazan_RBAC_Schema::is_installed() ) {
			return $result;
		}
		if ( ! $force && get_option( self::VERSION_OPTION ) === self::REGISTRY_VERSION ) {
			return $result;
		}

		global $wpdb;
		$table   = Yazan_RBAC_Schema::permissions_table();
		$grants  = Yazan_RBAC_Schema::role_permissions_table();
		$catalog = self::all();

		// 1. Renames first, so a grant is moved before the old slug is marked retired.
		foreach ( self::RENAMES as $old => $new ) {
			if ( ! isset( $catalog[ $new ] ) ) {
				continue;
			}
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// IGNORE: the role may already hold the new slug, and the pivot's primary key would reject the duplicate.
			$moved = $wpdb->query(
				$wpdb->prepare( "UPDATE IGNORE {$grants} SET permission_slug = %s WHERE permission_slug = %s", $new, $old )
			);
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$grants} WHERE permission_slug = %s", $old ) );
			// phpcs:enable
			$result['renamed'] += max( 0, (int) $moved );
		}

		// 2. Upsert every catalog row.
		foreach ( $catalog as $spec ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table} (slug, module, action, label, description, status, sort)
					 VALUES (%s, %s, %s, %s, %s, %s, %d)
					 ON DUPLICATE KEY UPDATE module = VALUES(module), action = VALUES(action),
					 label = VALUES(label), description = VALUES(description),
					 status = VALUES(status), sort = VALUES(sort)",
					$spec['slug'],
					$spec['module'],
					$spec['action'],
					$spec['label'],
					$spec['description'],
					$spec['status'],
					$spec['sort']
				)
			);
			++$result['inserted'];
		}

		// 3. Retire anything the catalog no longer declares.
		$known        = array_keys( $catalog );
		$placeholders = implode( ',', array_fill( 0, count( $known ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result['retired'] = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s WHERE slug NOT IN ({$placeholders}) AND status <> %s",
				array_merge( array( self::STATUS_RETIRED ), $known, array( self::STATUS_RETIRED ) )
			)
		);

		// 4. Report (never prune) grants pointing at slugs we do not know.
		$unknown = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT permission_slug FROM {$grants} WHERE permission_slug NOT IN ({$placeholders})",
				$known
			)
		);
		// phpcs:enable
		$result['unknown'] = is_array( $unknown ) ? $unknown : array();

		update_option( self::VERSION_OPTION, self::REGISTRY_VERSION, false );

		if ( class_exists( 'Yazan_Dashboard_Audit' ) ) {
			Yazan_Dashboard_Audit::log(
				'rbac.catalog_sync',
				'role',
				0,
				array(
					'version' => self::REGISTRY_VERSION,
					'count'   => $result['inserted'],
					'retired' => $result['retired'],
					'unknown' => count( $result['unknown'] ),
				)
			);
		}

		return $result;
	}

	/**
	 * Grants that point at a slug outside the catalog. Surfaced in GET /status.
	 *
	 * @return string[]
	 */
	public static function orphan_slugs() {
		if ( ! Yazan_RBAC_Schema::is_installed() ) {
			return array();
		}

		global $wpdb;
		$grants       = Yazan_RBAC_Schema::role_permissions_table();
		$known        = self::slugs();
		$placeholders = implode( ',', array_fill( 0, count( $known ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col(
			$wpdb->prepare( "SELECT DISTINCT permission_slug FROM {$grants} WHERE permission_slug NOT IN ({$placeholders})", $known )
		);

		return is_array( $rows ) ? $rows : array();
	}
}
