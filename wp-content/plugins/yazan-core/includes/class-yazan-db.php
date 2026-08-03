<?php
/**
 * Table-name authority for yazan-core.
 *
 * WHY THIS EXISTS
 * ---------------
 * The two sibling plugins each funnel every table name through one method — `Database::table()` in
 * yazan-social-rewards and yazan-payment-bridge-lite — which is why they each have exactly one
 * place to inject a tenant predicate later. yazan-core had four independent accessors that shared
 * no base (`Yazan_RBAC_Schema::*_table()`, `Yazan_Dashboard_Audit::table()`, `Yazan_AI_Log::table()`
 * and the homepage `Schema::table()`), plus a handful of inline `$wpdb->prefix . 'yazan_…'` strings
 * in the privacy layer.
 *
 * Four accessors is not a crisis on its own. It becomes one when a store dimension has to be
 * threaded through, because "which tables does yazan-core own" stops being answerable by reading a
 * single file. This class makes it answerable.
 *
 * SCOPE — DELIBERATELY NARROW
 * ---------------------------
 * This centralises NAMING only. It is not a query builder and it does not add a WHERE clause; the
 * ~120 call sites still interpolate `{$table}` into hand-written SQL and each still needs its own
 * predicate when the time comes. Naming centralisation is the prerequisite for that work, not a
 * substitute for it — see docs/multi-store/06-QUERY-SCOPING-MAP.md, which classifies every call
 * site into the ones that must be scoped and the ones that must never be.
 *
 * WooCommerce and WordPress core tables are deliberately absent. Two controllers read
 * `woocommerce_shipping_zone_methods` and `woocommerce_tax_rate_locations` directly; those belong
 * to WooCommerce, must not be listed here, and cannot be scoped by adding a column.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Names the tables yazan-core owns.
 */
class Yazan_DB {

	/**
	 * Every table this plugin creates, without the `$wpdb->prefix` or the `yazan_` segment.
	 *
	 * Kept as an explicit list rather than inferred from a naming convention: an audit that asks
	 * "what does yazan-core own" should get an answer from a constant, not from a glob.
	 *
	 * @var string[]
	 */
	const TABLES = array(
		'roles',
		'permissions',
		'role_permissions',
		'user_roles',
		'audit_log',
		'ai_generations',
		'homepage_documents',
		'homepage_revisions',
		'homepage_templates',
		'homepage_ab_stats',
		'api_tokens',
	);

	/**
	 * Fully-qualified name of a table this plugin owns.
	 *
	 * @param string $name One of self::TABLES, e.g. 'audit_log'.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;

		return $wpdb->prefix . 'yazan_' . $name;
	}

	/**
	 * Every table this plugin owns, fully qualified.
	 *
	 * Used by tooling and by the scoping audit; not on any request path.
	 *
	 * @return string[]
	 */
	public static function tables() {
		return array_map( array( __CLASS__, 'table' ), self::TABLES );
	}

	/**
	 * Does this plugin own the given fully-qualified table?
	 *
	 * The privacy layer and the backup engine both iterate tables generically; this lets them tell
	 * ours from WooCommerce's and WordPress's without hard-coding a second list.
	 *
	 * @param string $table Fully-qualified table name.
	 * @return bool
	 */
	public static function owns( $table ) {
		return in_array( (string) $table, self::tables(), true );
	}

	/**
	 * The store a query should be scoped to.
	 *
	 * Present so that call sites can be written against a stable API now and start meaning
	 * something when the schema grows a `store_id` column, rather than being rewritten twice.
	 *
	 * ⚠️ There is no `store_id` column yet, so nothing may use this to build a WHERE clause today.
	 * When that changes, the rule from the migration plan applies without exception: `DEFAULT 1` on
	 * the COLUMN, never a default in the QUERY. A query layer that quietly assumes store 1 turns
	 * cross-tenant leaks — invisible — into the normal case; one that refuses to run without an
	 * explicit store turns them into outages, which are visible and therefore fixable.
	 *
	 * @return int
	 */
	public static function store_id() {
		return class_exists( 'Yazan_Store_Context' ) ? Yazan_Store_Context::current() : 1;
	}
}
