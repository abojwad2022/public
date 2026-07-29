<?php
/**
 * Input/order validation failure.
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

namespace Yazan\PaymentBridge\Exceptions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown when an order does not exist, is not a WC_Order, or an event payload
 * is structurally unusable. Never results in a stored event.
 */
final class ValidationException extends PaymentBridgeException {
}
