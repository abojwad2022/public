<?php
/**
 * Capability → role map.
 *
 * Granted on activation, removed on uninstall. Ambassadors are NOT a WordPress
 * role — ambassador status lives in the plugin's own table; customers keep the
 * stock `customer` role.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	// Full management of the rewards platform (settings, rules, ambassadors…).
	'manage_yazan_rewards' => array( 'administrator', 'shop_manager' ),
	// Read-only access to reports/dashboards.
	'view_yazan_rewards'   => array( 'administrator', 'shop_manager' ),
);
