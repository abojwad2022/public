<?php
/**
 * Downstream integration failure.
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

namespace Yazan\PaymentBridge\Exceptions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown when a connector's downstream subscriber failed. Recorded against the
 * event as integration_status = failed and retryable from the admin.
 */
final class IntegrationException extends PaymentBridgeException {
}
