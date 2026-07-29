<?php
/**
 * Ambassador application service.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Ambassador;

use Yazan\Rewards\Core\Events\Events;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Events\GenericEvent;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Modules\Referral\ReferralCodes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles becoming an ambassador: apply (pending, or auto-approved), then
 * approve/suspend by an admin. An approved ambassador's code is their referral
 * code, so referral attribution and commission share one identity.
 */
final class ApplicationService {

	/**
	 * @param AmbassadorRepository $repo     Repository.
	 * @param ReferralCodes        $codes    Referral codes.
	 * @param Settings             $settings Settings.
	 * @param EventBus             $bus      Event bus.
	 */
	public function __construct(
		private AmbassadorRepository $repo,
		private ReferralCodes $codes,
		private Settings $settings,
		private EventBus $bus
	) {}

	/**
	 * Apply to become an ambassador.
	 *
	 * @param int $user_id User id.
	 * @return Ambassador|\WP_Error
	 */
	public function apply( int $user_id ) {
		if ( ! $this->settings->feature_enabled( 'ambassador' ) ) {
			return new \WP_Error( 'ambassador_disabled', __( 'The ambassador programme is not open.', 'yazan-rewards' ), array( 'status' => 403 ) );
		}
		if ( $user_id <= 0 ) {
			return new \WP_Error( 'not_logged_in', __( 'Please sign in to apply.', 'yazan-rewards' ), array( 'status' => 401 ) );
		}

		$existing = $this->repo->get_by_user( $user_id );
		if ( $existing ) {
			return $existing; // Idempotent — already applied.
		}

		$auto_approve = (bool) $this->settings->get( 'ambassador.auto_approve', false );
		$rate         = (float) $this->settings->get( 'ambassador.commission_rate', 5 );
		$status       = $auto_approve ? Ambassador::STATUS_ACTIVE : Ambassador::STATUS_PENDING;
		$code         = $this->codes->for_user( $user_id );

		$id = $this->repo->create(
			array(
				'user_id'         => $user_id,
				'code'            => $code,
				'status'          => $status,
				'tier'            => 'standard',
				'commission_rate' => $rate,
			)
		);

		if ( ! $id ) {
			return new \WP_Error( 'apply_failed', __( 'Could not submit your application.', 'yazan-rewards' ), array( 'status' => 500 ) );
		}

		if ( Ambassador::STATUS_ACTIVE === $status ) {
			$this->announce_approved( $user_id, $id );
		}

		return $this->repo->get( $id );
	}

	/**
	 * Approve a pending ambassador (admin).
	 *
	 * @param int $ambassador_id Row id.
	 * @return Ambassador|\WP_Error
	 */
	public function approve( int $ambassador_id ) {
		$amb = $this->repo->get( $ambassador_id );
		if ( ! $amb ) {
			return new \WP_Error( 'not_found', __( 'Ambassador not found.', 'yazan-rewards' ), array( 'status' => 404 ) );
		}
		$this->repo->set_status( $ambassador_id, Ambassador::STATUS_ACTIVE );
		$this->announce_approved( $amb->user_id, $ambassador_id );
		return $this->repo->get( $ambassador_id );
	}

	/**
	 * Suspend an ambassador (admin).
	 *
	 * @param int $ambassador_id Row id.
	 * @return Ambassador|\WP_Error
	 */
	public function suspend( int $ambassador_id ) {
		$amb = $this->repo->get( $ambassador_id );
		if ( ! $amb ) {
			return new \WP_Error( 'not_found', __( 'Ambassador not found.', 'yazan-rewards' ), array( 'status' => 404 ) );
		}
		$this->repo->set_status( $ambassador_id, Ambassador::STATUS_SUSPENDED );
		return $this->repo->get( $ambassador_id );
	}

	/**
	 * Dispatch the approved event.
	 *
	 * @param int $user_id       User id.
	 * @param int $ambassador_id Row id.
	 * @return void
	 */
	private function announce_approved( int $user_id, int $ambassador_id ): void {
		$this->bus->dispatch(
			new GenericEvent(
				Events::AMBASSADOR_APPROVED,
				array( 'user_id' => $user_id, 'ambassador_id' => $ambassador_id )
			)
		);
	}
}
