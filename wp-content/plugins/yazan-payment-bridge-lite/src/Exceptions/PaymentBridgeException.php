<?php
/**
 * Base exception.
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

namespace Yazan\PaymentBridge\Exceptions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Root of the Bridge's exception hierarchy. Never surfaced to customers — the
 * listener catches everything so checkout cannot break.
 */
class PaymentBridgeException extends \RuntimeException {
}
