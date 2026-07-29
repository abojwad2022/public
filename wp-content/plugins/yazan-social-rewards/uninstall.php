<?php
/**
 * Uninstall handler.
 *
 * Guarded teardown. By default the plugin KEEPS its data (points/wallet ledgers
 * are a financial liability record). Only when the store owner has explicitly
 * enabled "delete data on uninstall" does this drop tables + options + caps.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

// Only run in a genuine uninstall context.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings = get_option( 'yazan_rewards_settings', array() );
$purge    = is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] );

if ( ! $purge ) {
	return; // Keep everything.
}

global $wpdb;

// 1. Drop every plugin table (prefix owned by this plugin).
$prefix = $wpdb->prefix . 'yazan_rw_';
$tables = $wpdb->get_col(
	$wpdb->prepare(
		'SHOW TABLES LIKE %s',
		$wpdb->esc_like( $prefix ) . '%'
	)
);
foreach ( (array) $tables as $table ) {
	// Table name comes from SHOW TABLES on our own prefix — not user input.
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
}

// 2. Delete options — settings, version markers, the cron-ready flag, and the
//    write-only secrets (social OAuth app credentials + webhook signing secret).
foreach ( array(
	'yazan_rewards_settings',
	'yazan_rewards_db_version',
	'yazan_rewards_version',
	'yazan_rewards_cron_ready',
	'yazan_rewards_social_secrets',
	'yazan_rewards_notification_webhook_secret',
) as $option ) {
	delete_option( $option );
}

// 3. Remove capabilities from all roles.
$caps = array( 'manage_yazan_rewards', 'view_yazan_rewards' );
if ( function_exists( 'wp_roles' ) ) {
	$roles = wp_roles();
	foreach ( array_keys( $roles->roles ) as $role_name ) {
		$role = get_role( $role_name );
		if ( ! $role ) {
			continue;
		}
		foreach ( $caps as $cap ) {
			if ( $role->has_cap( $cap ) ) {
				$role->remove_cap( $cap );
			}
		}
	}
}

// 4. Clear the cached derived balances stored in user meta.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like( '_yzrw_' ) . '%'
	)
);
