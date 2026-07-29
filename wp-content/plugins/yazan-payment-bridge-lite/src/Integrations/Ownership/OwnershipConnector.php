<?php
/**
 * Ownership connector — an integration seam, not a store.
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

namespace Yazan\PaymentBridge\Integrations\Ownership;

use Yazan\PaymentBridge\Events\Event;
use Yazan\PaymentBridge\Logging\Logger;
use Yazan\PaymentBridge\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connects verified payments to the YAZAN Digital Ownership System.
 *
 * This connector deliberately stores nothing. Yazan's authenticity model is
 * scoped to the certificate — serial, stone, silver, origin, craft — and
 * explicitly excludes any ownership register, so the Bridge announces the event
 * and lets a downstream system decide what, if anything, to record.
 *
 * The handoff is a filter plus an action:
 *  - `yazan_payment_bridge/ownership/handled` — a subscriber returns true to
 *    claim the event. False means nobody was listening, which the dispatcher
 *    records as skipped, not failed.
 *  - `yazan_payment_bridge/ownership/create` — the work hook itself.
 *
 * A missing downstream plugin can therefore never fatal the Bridge (H7).
 */
final class OwnershipConnector implements OwnershipServiceInterface {

	/**
	 * @param Settings $settings Settings reader.
	 * @param Logger   $logger   Logger.
	 */
	public function __construct( private Settings $settings, private Logger $logger ) {}

	/**
	 * Whether the ownership integration is switched on.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->settings->is_enabled( 'enable_ownership' );
	}

	/**
	 * @inheritDoc
	 */
	public function createOwnership( Event $event, array $items ): bool {
		return $this->announce( 'create', $event, $items, '' );
	}

	/**
	 * @inheritDoc
	 */
	public function verifyOwnership( int $order_id ): bool {
		/**
		 * Ask a downstream ownership system whether it holds a record for an order.
		 *
		 * @param bool $exists   Whether ownership exists.
		 * @param int  $order_id Order id.
		 */
		return (bool) apply_filters( 'yazan_payment_bridge/ownership/exists', false, $order_id );
	}

	/**
	 * @inheritDoc
	 */
	public function revokeOwnership( Event $event, string $reason ): bool {
		return $this->announce( 'revoke', $event, array(), $reason );
	}

	/**
	 * Shared seam: ask whether anyone will handle this, then fire the work hook.
	 *
	 * @param string                         $action One of create|revoke.
	 * @param Event                          $event  Event.
	 * @param array<int,array<string,mixed>> $items  Eligible items.
	 * @param string                         $reason Reason (revoke only).
	 * @return bool Whether a downstream system handled it.
	 */
	private function announce( string $action, Event $event, array $items, string $reason ): bool {
		/**
		 * Claim an ownership event. Return true from a downstream YAZAN system
		 * (guarded by its own class_exists check) to mark it handled.
		 *
		 * @param bool                           $handled Whether a subscriber handled it.
		 * @param Event                          $event   Payment event.
		 * @param array<int,array<string,mixed>> $items   Eligible line items.
		 * @param string                         $reason  Reason, for revocations.
		 */
		$handled = (bool) apply_filters( "yazan_payment_bridge/ownership/handled_{$action}", false, $event, $items, $reason );

		/**
		 * Do the ownership work. Subscribers that throw are caught by the
		 * dispatcher and recorded against the event.
		 *
		 * @param Event                          $event  Payment event.
		 * @param array<int,array<string,mixed>> $items  Eligible line items.
		 * @param string                         $reason Reason, for revocations.
		 */
		do_action( "yazan_payment_bridge/ownership/{$action}", $event, $items, $reason );

		if ( ! $handled ) {
			$this->logger->debug(
				'No ownership subscriber claimed the event.',
				array(
					'order_id'   => $event->order_id,
					'event_uuid' => $event->event_uuid,
					'connector'  => 'ownership',
					'code'       => $action,
				)
			);
		}

		return $handled;
	}
}
