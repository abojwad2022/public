<?php
/**
 * Uninstall handler.
 *
 * Guarded teardown. By default the plugin KEEPS its data: the payment-event
 * ledger is an accounting and legal retention record, not a cache. Only when the
 * store owner has explicitly enabled "Delete all data on uninstall" does this
 * drop the table and options.
 *
 * Capabilities are always removed — they are meaningless once the plugin is gone.
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

// Only run in a genuine uninstall context.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

/*
 * 1. Remove capabilities from every role. The list is re-declared literally
 *    because plugin classes are not autoloaded during uninstall.
 */
$yazan_pb_caps = array( 'yazan_payment_view', 'yazan_payment_manage', 'yazan_payment_retry' );

if ( function_exists( 'wp_roles' ) ) {
	$yazan_pb_roles = wp_roles();
	foreach ( array_keys( $yazan_pb_roles->roles ) as $yazan_pb_role_name ) {
		$yazan_pb_role = get_role( $yazan_pb_role_name );
		if ( ! $yazan_pb_role ) {
			continue;
		}
		foreach ( $yazan_pb_caps as $yazan_pb_cap ) {
			if ( $yazan_pb_role->has_cap( $yazan_pb_cap ) ) {
				$yazan_pb_role->remove_cap( $yazan_pb_cap );
			}
		}
	}
}

/*
 * 2. Opt-in data purge. Absent the explicit setting, everything else stays.
 */
$yazan_pb_settings = get_option( 'yazan_payment_bridge_settings', array() );
$yazan_pb_purge    = is_array( $yazan_pb_settings ) && ! empty( $yazan_pb_settings['delete_data_on_uninstall'] );

if ( ! $yazan_pb_purge ) {
	return; // Keep the payment-event ledger.
}

/*
 * 3. Drop every table owned by this plugin's prefix.
 */
$yazan_pb_prefix = $wpdb->prefix . 'yazan_payment_';
$yazan_pb_tables = $wpdb->get_col(
	$wpdb->prepare(
		'SHOW TABLES LIKE %s',
		$wpdb->esc_like( $yazan_pb_prefix ) . '%'
	)
);

foreach ( (array) $yazan_pb_tables as $yazan_pb_table ) {
	// Table name comes from SHOW TABLES on our own prefix — not user input.
	$wpdb->query( "DROP TABLE IF EXISTS `{$yazan_pb_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
}

/*
 * 4. Delete options.
 */
foreach ( array(
	'yazan_payment_bridge_settings',
	'yazan_payment_bridge_db_version',
	'yazan_payment_bridge_version',
) as $yazan_pb_option ) {
	delete_option( $yazan_pb_option );
}
