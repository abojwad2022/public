<?php
/**
 * Default settings.
 *
 * Debug logging is OFF by default (H6). Data deletion on uninstall is OFF by
 * default (H7) — the payment-event ledger may be required for accounting or
 * legal retention.
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'enable_ownership'         => 1,
	'enable_warranty'          => 1,
	'debug_logging'            => 0,
	'sku_pattern'              => '^YZ-[A-Z0-9]{2,10}-\d{1,8}$',
	'delete_data_on_uninstall' => 0,
);
