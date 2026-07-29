<?php
/**
 * Plugin Migrations
 *
 * Handles silent data migrations on plugin update or activation.
 *
 * @package Power_Coupons
 * @since 1.0.3
 */

namespace Power_Coupons\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Power_Coupons_Migration
 */
class Power_Coupons_Migration {

	/**
	 * Option key to track the last completed migration version.
	 *
	 * @var string
	 */
	const DB_VERSION_KEY = 'power_coupons_db_version';

	/**
	 * Run pending migrations (version-gated).
	 *
	 * Called on `admin_init` so migrations run once on the first admin page load
	 * after a plugin update.
	 *
	 * @return void
	 */
	public static function run() {
		$db_version = get_option( self::DB_VERSION_KEY, '0' );
		$db_version = is_string( $db_version ) ? $db_version : '0';

		if ( version_compare( $db_version, '1.0.3', '<' ) ) {
			self::migrate_slideout_visibility();
			update_option( self::DB_VERSION_KEY, '1.0.3' );
		}
	}

	/**
	 * Run all migrations on plugin activation.
	 *
	 * Bypasses the version check so migrations run even when the plugin is
	 * deactivated/reactivated at the same version (e.g. during development
	 * or when a new build is uploaded without a version bump).
	 *
	 * Each individual migration is idempotent — it uses a dedicated completion
	 * flag so repeated runs are safe.
	 *
	 * @return void
	 */
	public static function run_on_activation() {
		self::migrate_slideout_visibility();
		update_option( self::DB_VERSION_KEY, '1.0.3' );
	}

	/**
	 * Migrate from "Hide from slideout" (opt-out) to "Show in slideout" (opt-in).
	 *
	 * For existing installs, converts old behaviour where all coupons were visible
	 * by default. After migration, only coupons with `_power_coupon_show_in_slideout`
	 * = 'yes' appear in the slideout.
	 *
	 * Migration logic:
	 *  - Coupons with old hide meta = 'yes' → stay hidden (no new meta).
	 *  - All other existing coupons → show_in_slideout = 'yes' (preserve visibility).
	 *  - Old `_power_coupon_hide_in_slideout` meta is cleaned up.
	 *
	 * Skips on fresh installs (no `power_coupons_settings` option = never configured).
	 * Idempotent via a dedicated completion flag.
	 *
	 * @return void
	 */
	private static function migrate_slideout_visibility() {
		// Check if this migration has already been completed.
		if ( 'done' === get_option( 'power_coupons_migrated_slideout_visibility' ) ) {
			return;
		}

		// Only migrate for existing installs — fresh installs should default to hidden.
		if ( false === get_option( 'power_coupons_settings' ) ) {
			update_option( 'power_coupons_migrated_slideout_visibility', 'done' );
			return;
		}

		global $wpdb;

		// Get IDs of coupons that were explicitly hidden with the old meta.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$hidden_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
				'_power_coupon_hide_in_slideout',
				'yes'
			)
		);

		// Get all published/draft coupon IDs.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$all_coupon_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish', 'draft', 'pending', 'private')",
				'shop_coupon'
			)
		);

		if ( ! empty( $all_coupon_ids ) ) {
			// Coupons that were visible = all coupons minus the hidden ones.
			$visible_ids = array_diff( $all_coupon_ids, $hidden_ids );

			// Set show_in_slideout = 'yes' for previously visible coupons,
			// but skip any that already have the new meta (idempotency).
			foreach ( $visible_ids as $coupon_id ) {
				$existing = get_post_meta( (int) $coupon_id, '_power_coupon_show_in_slideout', true );
				if ( '' === $existing ) {
					update_post_meta( (int) $coupon_id, '_power_coupon_show_in_slideout', 'yes' );
				}
			}
		}

		// Clean up old meta key from all coupons.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->postmeta,
			array( 'meta_key' => '_power_coupon_hide_in_slideout' ) // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		);

		// Mark migration as done.
		update_option( 'power_coupons_migrated_slideout_visibility', 'done' );
	}
}
