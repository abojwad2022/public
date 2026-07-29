<?php
/**
 * Ownership integration contract.
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

namespace Yazan\PaymentBridge\Integrations\Ownership;

use Yazan\PaymentBridge\Events\Event;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract between the Bridge and the YAZAN Digital Ownership System.
 *
 * Implementations return true only when a downstream system actually handled the
 * call; false means "nobody was listening", which the dispatcher records as
 * skipped rather than failed.
 */
interface OwnershipServiceInterface {

	/**
	 * Announce a completed payment so ownership can be established downstream.
	 *
	 * @param Event                          $event Canonical payment event.
	 * @param array<int,array<string,mixed>> $items Eligible line items.
	 * @return bool Whether a downstream system handled it.
	 */
	public function createOwnership( Event $event, array $items ): bool;

	/**
	 * Ask whether ownership exists downstream for an order.
	 *
	 * @param int $order_id Order id.
	 * @return bool
	 */
	public function verifyOwnership( int $order_id ): bool;

	/**
	 * Announce a full refund so ownership can be revoked downstream (H3).
	 *
	 * @param Event  $event  Refund event.
	 * @param string $reason Machine-readable reason.
	 * @return bool Whether a downstream system handled it.
	 */
	public function revokeOwnership( Event $event, string $reason ): bool;
}
