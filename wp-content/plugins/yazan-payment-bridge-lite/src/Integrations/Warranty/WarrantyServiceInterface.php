<?php
/**
 * Warranty integration contract.
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

namespace Yazan\PaymentBridge\Integrations\Warranty;

use Yazan\PaymentBridge\Events\Event;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract between the Bridge and the YAZAN warranty system.
 *
 * Implementations return true only when a downstream system actually handled the
 * call; false means "nobody was listening".
 */
interface WarrantyServiceInterface {

	/**
	 * Announce a completed payment so a warranty can be created downstream.
	 *
	 * @param Event                          $event Canonical payment event.
	 * @param array<int,array<string,mixed>> $items Eligible line items.
	 * @return bool Whether a downstream system handled it.
	 */
	public function createWarranty( Event $event, array $items ): bool;

	/**
	 * Ask downstream for the warranty status of an order.
	 *
	 * @param int $order_id Order id.
	 * @return string Status string, or '' when unknown.
	 */
	public function getWarrantyStatus( int $order_id ): string;

	/**
	 * Announce a full refund so the warranty can be suspended downstream (H3).
	 *
	 * @param Event  $event  Refund event.
	 * @param string $reason Machine-readable reason.
	 * @return bool Whether a downstream system handled it.
	 */
	public function suspendWarranty( Event $event, string $reason ): bool;
}
