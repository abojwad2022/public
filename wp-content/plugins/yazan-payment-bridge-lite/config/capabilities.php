<?php
/**
 * Capability => roles map.
 *
 * Shop managers may read the payment-event ledger; only administrators may
 * change settings or re-run an integration.
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'yazan_payment_view'   => array( 'administrator', 'shop_manager' ),
	'yazan_payment_manage' => array( 'administrator' ),
	'yazan_payment_retry'  => array( 'administrator' ),
);
