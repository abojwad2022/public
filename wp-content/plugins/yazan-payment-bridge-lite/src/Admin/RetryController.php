<?php
/**
 * Retry action handler.
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

namespace Yazan\PaymentBridge\Admin;

use Yazan\PaymentBridge\Contracts\Hookable;
use Yazan\PaymentBridge\Integrations\IntegrationDispatcher;
use Yazan\PaymentBridge\Logging\Logger;
use Yazan\PaymentBridge\Payments\PaymentEventService;
use Yazan\PaymentBridge\Security\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Re-runs the integrations for one stored event.
 *
 * The retry goes through the same claim-gated pipeline as the original run, so a
 * double-clicked retry — or two administrators clicking at once — still produces
 * at most one downstream run.
 */
final class RetryController implements Hookable {

	/** admin-post action name. */
	public const ACTION = 'yazan_pb_retry';

	/** Nonce action prefix; the event id is appended. */
	public const NONCE_PREFIX = 'yazan_pb_retry_';

	/**
	 * @param PaymentEventService $service Event service.
	 * @param Logger              $logger  Logger.
	 */
	public function __construct( private PaymentEventService $service, private Logger $logger ) {}

	/**
	 * @inheritDoc
	 */
	public function hooks(): array {
		return array(
			array(
				'type'   => 'action',
				'hook'   => 'admin_post_' . self::ACTION,
				'method' => 'handle',
				'args'   => 0,
			),
		);
	}

	/**
	 * Handle the retry request: capability, then nonce, then work (H5).
	 *
	 * @return void
	 */
	public function handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified immediately below, and needed to build the nonce action.
		$event_id = isset( $_REQUEST['event_id'] ) ? absint( wp_unslash( $_REQUEST['event_id'] ) ) : 0;

		if ( ! current_user_can( Capabilities::RETRY ) ) {
			$this->logger->warning( 'Retry denied: insufficient capability.', array( 'event_id' => $event_id ) );
			wp_die(
				esc_html__( 'You do not have permission to retry payment integrations.', 'yazan-payment-bridge' ),
				esc_html__( 'Permission denied', 'yazan-payment-bridge' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::NONCE_PREFIX . $event_id );

		if ( $event_id <= 0 ) {
			$this->redirect( 'locked' );
		}

		$result = $this->service->retry( $event_id );

		$this->logger->info(
			'Retry requested from the admin.',
			array(
				'event_id'           => $event_id,
				'integration_status' => $result,
			)
		);

		$this->redirect( IntegrationDispatcher::RESULT_LOCKED === $result ? 'locked' : $result );
	}

	/**
	 * Redirect back to the events list with a result notice.
	 *
	 * @param string $notice Notice key.
	 * @return void
	 */
	private function redirect( string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => EventsPage::SLUG,
					'yazan_pb_notice' => sanitize_key( $notice ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
