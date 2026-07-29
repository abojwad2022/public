<?php
/**
 * Warranty connector — an integration seam, not a store.
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

namespace Yazan\PaymentBridge\Integrations\Warranty;

use Yazan\PaymentBridge\Events\Event;
use Yazan\PaymentBridge\Logging\Logger;
use Yazan\PaymentBridge\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connects verified payments to the YAZAN warranty system.
 *
 * Same independence rules as the ownership connector: no storage, no hard
 * dependency on another plugin, and a missing downstream system is a skipped
 * integration rather than a fatal error (H7).
 */
final class WarrantyConnector implements WarrantyServiceInterface {

	/**
	 * @param Settings $settings Settings reader.
	 * @param Logger   $logger   Logger.
	 */
	public function __construct( private Settings $settings, private Logger $logger ) {}

	/**
	 * Whether the warranty integration is switched on.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->settings->is_enabled( 'enable_warranty' );
	}

	/**
	 * @inheritDoc
	 */
	public function createWarranty( Event $event, array $items ): bool {
		return $this->announce( 'create', $event, $items, '' );
	}

	/**
	 * @inheritDoc
	 */
	public function getWarrantyStatus( int $order_id ): string {
		/**
		 * Ask a downstream warranty system for an order's warranty status.
		 *
		 * @param string $status   Status string, '' when unknown.
		 * @param int    $order_id Order id.
		 */
		return (string) apply_filters( 'yazan_payment_bridge/warranty/status', '', $order_id );
	}

	/**
	 * @inheritDoc
	 */
	public function suspendWarranty( Event $event, string $reason ): bool {
		return $this->announce( 'suspend', $event, array(), $reason );
	}

	/**
	 * Shared seam: ask whether anyone will handle this, then fire the work hook.
	 *
	 * @param string                         $action One of create|suspend.
	 * @param Event                          $event  Event.
	 * @param array<int,array<string,mixed>> $items  Eligible items.
	 * @param string                         $reason Reason (suspend only).
	 * @return bool Whether a downstream system handled it.
	 */
	private function announce( string $action, Event $event, array $items, string $reason ): bool {
		/**
		 * Claim a warranty event. Return true from a downstream YAZAN system to
		 * mark it handled.
		 *
		 * @param bool                           $handled Whether a subscriber handled it.
		 * @param Event                          $event   Payment event.
		 * @param array<int,array<string,mixed>> $items   Eligible line items.
		 * @param string                         $reason  Reason, for suspensions.
		 */
		$handled = (bool) apply_filters( "yazan_payment_bridge/warranty/handled_{$action}", false, $event, $items, $reason );

		/**
		 * Do the warranty work.
		 *
		 * @param Event                          $event  Payment event.
		 * @param array<int,array<string,mixed>> $items  Eligible line items.
		 * @param string                         $reason Reason, for suspensions.
		 */
		do_action( "yazan_payment_bridge/warranty/{$action}", $event, $items, $reason );

		if ( ! $handled ) {
			$this->logger->debug(
				'No warranty subscriber claimed the event.',
				array(
					'order_id'   => $event->order_id,
					'event_uuid' => $event->event_uuid,
					'connector'  => 'warranty',
					'code'       => $action,
				)
			);
		}

		return $handled;
	}
}
