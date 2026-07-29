<?php
/**
 * Campaign submission review service.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Campaigns;

use Yazan\Rewards\Core\Contracts\PointsLedgerInterface;
use Yazan\Rewards\Core\Events\Events;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Events\GenericEvent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin review of task submissions. Approving awards the task's points and, once a
 * participant's required tasks are all approved, pays the campaign completion
 * bonus and marks them completed. Mirrors the Social engine's approval pattern.
 */
final class SubmissionReviewService {

	/**
	 * @param SubmissionRepository        $submissions  Submissions.
	 * @param CampaignTaskRepository      $tasks        Tasks.
	 * @param MarketingCampaignRepository $campaigns    Campaigns.
	 * @param ParticipantRepository       $participants Participants.
	 * @param PointsLedgerInterface       $points       Points ledger.
	 * @param EventBus                    $bus          Event bus.
	 */
	public function __construct(
		private SubmissionRepository $submissions,
		private CampaignTaskRepository $tasks,
		private MarketingCampaignRepository $campaigns,
		private ParticipantRepository $participants,
		private PointsLedgerInterface $points,
		private EventBus $bus
	) {}

	/**
	 * Approve a submission.
	 *
	 * @param int $submission_id Submission id.
	 * @param int $reviewer      Reviewer user id.
	 * @return array|\WP_Error
	 */
	public function approve( int $submission_id, int $reviewer ) {
		$sub = $this->submissions->get( $submission_id );
		if ( ! $sub ) {
			return new \WP_Error( 'not_found', __( 'Submission not found.', 'yazan-rewards' ), array( 'status' => 404 ) );
		}
		if ( 'pending' !== $sub->status ) {
			return array( 'ok' => true, 'status' => $sub->status ); // Already reviewed.
		}

		$user_id     = (int) $sub->user_id;
		$campaign_id = (int) $sub->campaign_id;
		$award       = (int) $sub->points_award;

		$this->submissions->set_status( $submission_id, 'approved', $reviewer );

		if ( $award > 0 ) {
			$this->points->credit( $user_id, $award, 'campaign', $campaign_id, array( 'type' => 'earn', 'reason' => __( 'Campaign task approved', 'yazan-rewards' ) ) );
			$this->participants->add_points( $campaign_id, $user_id, $award );
		}

		$this->bus->dispatch(
			new GenericEvent(
				Events::CAMPAIGN_SUBMISSION_APPROVED,
				array( 'user_id' => $user_id, 'campaign_id' => $campaign_id, 'submission_id' => $submission_id, 'points' => $award )
			)
		);

		$this->maybe_complete( $campaign_id, $user_id );

		return array( 'ok' => true, 'status' => 'approved', 'points' => $award );
	}

	/**
	 * Reject a submission.
	 *
	 * @param int $submission_id Submission id.
	 * @param int $reviewer      Reviewer user id.
	 * @return array|\WP_Error
	 */
	public function reject( int $submission_id, int $reviewer ) {
		$sub = $this->submissions->get( $submission_id );
		if ( ! $sub ) {
			return new \WP_Error( 'not_found', __( 'Submission not found.', 'yazan-rewards' ), array( 'status' => 404 ) );
		}
		if ( 'pending' === $sub->status ) {
			$this->submissions->set_status( $submission_id, 'rejected', $reviewer );
			$this->bus->dispatch(
				new GenericEvent(
					Events::CAMPAIGN_SUBMISSION_REJECTED,
					array( 'user_id' => (int) $sub->user_id, 'campaign_id' => (int) $sub->campaign_id, 'submission_id' => $submission_id )
				)
			);
		}
		return array( 'ok' => true, 'status' => 'rejected' );
	}

	/**
	 * Award the completion bonus once all required tasks are approved.
	 *
	 * @param int $campaign_id Campaign id.
	 * @param int $user_id     User id.
	 * @return void
	 */
	private function maybe_complete( int $campaign_id, int $user_id ): void {
		$participant = $this->participants->get( $campaign_id, $user_id );
		if ( ! $participant || 'completed' === $participant->status ) {
			return; // Not joined or already completed.
		}

		$required = $this->required_tasks( $campaign_id );
		if ( empty( $required ) ) {
			return;
		}
		foreach ( $required as $task ) {
			if ( ! $this->submissions->has_approved( $user_id, (int) $task->id ) ) {
				return; // Still missing a required task.
			}
		}

		// All required tasks approved — complete + pay the bonus.
		$campaign = $this->campaigns->get( $campaign_id );
		$bonus    = $campaign ? (int) $campaign->reward_amount : 0;
		if ( $bonus > 0 ) {
			$this->points->credit( $user_id, $bonus, 'campaign', $campaign_id, array( 'type' => 'earn', 'reason' => __( 'Campaign completion bonus', 'yazan-rewards' ) ) );
			$this->participants->add_points( $campaign_id, $user_id, $bonus );
		}
		$this->participants->mark_completed( $campaign_id, $user_id );

		$this->bus->dispatch(
			new GenericEvent(
				Events::CAMPAIGN_COMPLETED,
				array( 'user_id' => $user_id, 'campaign_id' => $campaign_id, 'bonus' => $bonus )
			)
		);
	}

	/**
	 * The required tasks for a campaign (criteria.required truthy; if none are
	 * flagged, every active task is required).
	 *
	 * @param int $campaign_id Campaign id.
	 * @return object[]
	 */
	private function required_tasks( int $campaign_id ): array {
		$all      = $this->tasks->for_campaign( $campaign_id );
		$required = array();
		$any_flag = false;
		foreach ( $all as $t ) {
			$criteria = json_decode( (string) $t->criteria, true );
			if ( is_array( $criteria ) && ! empty( $criteria['required'] ) ) {
				$required[] = $t;
				$any_flag   = true;
			}
		}
		return $any_flag ? $required : $all;
	}
}
