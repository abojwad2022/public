<?php
/**
 * Campaign participation service.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Campaigns;

use Yazan\Rewards\Core\Events\Events;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Events\GenericEvent;
use Yazan\Rewards\Core\Security\RateLimiter;
use Yazan\Rewards\Core\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customer-facing participation: join a campaign and submit task content. Guards
 * eligibility, campaign status (must be Active), duplicate approved submissions,
 * and rate-limits submissions.
 */
final class ParticipationService {

	private const RL_ACTION = 'campaign_submit';
	private const RL_MAX    = 20;
	private const RL_WINDOW = 3600;

	/**
	 * @param MarketingCampaignRepository $campaigns    Campaigns.
	 * @param CampaignTaskRepository      $tasks        Tasks.
	 * @param ParticipantRepository       $participants Participants.
	 * @param SubmissionRepository        $submissions  Submissions.
	 * @param CampaignEligibility         $eligibility  Eligibility.
	 * @param EventBus                    $bus          Event bus.
	 * @param RateLimiter                 $limiter      Rate limiter.
	 * @param Settings                    $settings     Settings.
	 */
	public function __construct(
		private MarketingCampaignRepository $campaigns,
		private CampaignTaskRepository $tasks,
		private ParticipantRepository $participants,
		private SubmissionRepository $submissions,
		private CampaignEligibility $eligibility,
		private EventBus $bus,
		private RateLimiter $limiter,
		private Settings $settings
	) {}

	/**
	 * Join a campaign.
	 *
	 * @param int $user_id     User id.
	 * @param int $campaign_id Campaign id.
	 * @return array|\WP_Error
	 */
	public function join( int $user_id, int $campaign_id ) {
		$campaign = $this->guard( $user_id, $campaign_id );
		if ( is_wp_error( $campaign ) ) {
			return $campaign;
		}
		$id = $this->participants->ensure( $campaign_id, $user_id );
		$this->bus->dispatch( new GenericEvent( Events::CAMPAIGN_JOINED, array( 'user_id' => $user_id, 'campaign_id' => $campaign_id ) ) );
		return array( 'ok' => true, 'participant_id' => $id );
	}

	/**
	 * Submit content for a task (auto-joins).
	 *
	 * @param int    $user_id     User id.
	 * @param int    $campaign_id Campaign id.
	 * @param int    $task_id     Task id.
	 * @param string $content_url Content URL.
	 * @param int    $metric      Metric value (e.g. views), optional.
	 * @return array|\WP_Error
	 */
	public function submit( int $user_id, int $campaign_id, int $task_id, string $content_url, int $metric = 0 ) {
		$campaign = $this->guard( $user_id, $campaign_id );
		if ( is_wp_error( $campaign ) ) {
			return $campaign;
		}

		$task = null;
		foreach ( $this->tasks->for_campaign( $campaign_id ) as $t ) {
			if ( (int) $t->id === $task_id ) {
				$task = $t;
				break;
			}
		}
		if ( ! $task ) {
			return new \WP_Error( 'bad_task', __( 'That task is not part of this campaign.', 'yazan-rewards' ), array( 'status' => 404 ) );
		}

		if ( $this->submissions->has_approved( $user_id, $task_id ) ) {
			return new \WP_Error( 'already_done', __( 'You have already completed this task.', 'yazan-rewards' ), array( 'status' => 409 ) );
		}

		if ( $this->limiter->too_many( self::RL_ACTION, (string) $user_id, self::RL_MAX ) ) {
			return new \WP_Error( 'too_many', __( 'Too many submissions. Please try again later.', 'yazan-rewards' ), array( 'status' => 429 ) );
		}
		$this->limiter->hit( self::RL_ACTION, (string) $user_id, self::RL_WINDOW );

		$this->participants->ensure( $campaign_id, $user_id );

		$id = $this->submissions->create(
			array(
				'campaign_id'  => $campaign_id,
				'task_id'      => $task_id,
				'user_id'      => $user_id,
				'type'         => (string) $task->task_type,
				'content_url'  => $content_url,
				'metric_value' => $metric,
				'points_award' => (int) $task->points_award,
			)
		);

		return array( 'ok' => $id > 0, 'submission_id' => $id, 'status' => 'pending' );
	}

	/**
	 * Validate the campaign + user for participation.
	 *
	 * @param int $user_id     User id.
	 * @param int $campaign_id Campaign id.
	 * @return Campaign|\WP_Error
	 */
	private function guard( int $user_id, int $campaign_id ) {
		if ( ! $this->settings->feature_enabled( 'marketing' ) ) {
			return new \WP_Error( 'disabled', __( 'Campaigns are unavailable.', 'yazan-rewards' ), array( 'status' => 403 ) );
		}
		if ( $user_id <= 0 ) {
			return new \WP_Error( 'not_logged_in', __( 'Please sign in to participate.', 'yazan-rewards' ), array( 'status' => 401 ) );
		}
		$campaign = $this->campaigns->get( $campaign_id );
		if ( ! $campaign || ! $campaign->is_active() ) {
			return new \WP_Error( 'not_active', __( 'This campaign is not currently active.', 'yazan-rewards' ), array( 'status' => 404 ) );
		}
		if ( ! $this->eligibility->eligible( $user_id, $campaign->target ) ) {
			return new \WP_Error( 'not_eligible', __( 'This campaign is not available to your account.', 'yazan-rewards' ), array( 'status' => 403 ) );
		}
		return $campaign;
	}
}
