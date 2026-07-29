<?php
/**
 * Campaign analytics.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Campaigns;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * On-demand per-campaign analytics: participants, submissions (by status),
 * generated points, completions, and engagement. Computed from the indexed
 * participant/submission tables.
 */
final class CampaignAnalytics {

	/**
	 * @param ParticipantRepository       $participants Participants.
	 * @param SubmissionRepository        $submissions  Submissions.
	 * @param MarketingCampaignRepository $campaigns    Campaigns.
	 */
	public function __construct(
		private ParticipantRepository $participants,
		private SubmissionRepository $submissions,
		private MarketingCampaignRepository $campaigns
	) {}

	/**
	 * Analytics snapshot for a campaign.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return array<string,mixed>
	 */
	public function for_campaign( int $campaign_id ): array {
		$participants = $this->participants->count( $campaign_id );
		$completions  = $this->participants->completed_count( $campaign_id );
		$stats        = $this->submissions->stats( $campaign_id );
		$engaged      = $this->submissions->engaged_users( $campaign_id );
		$campaign     = $this->campaigns->get( $campaign_id );
		$bonus        = $campaign ? (int) $campaign->reward_amount : 0;

		return array(
			'participants'     => $participants,
			'submissions'      => $stats['total'],
			'approved'         => $stats['approved'],
			'rejected'         => $stats['rejected'],
			'pending'          => $stats['pending'],
			'completions'      => $completions,
			// Task points from approved submissions + completion bonuses paid.
			'points_generated' => $stats['points'] + ( $completions * $bonus ),
			// Share of participants who submitted at least once.
			'engagement_rate'  => $participants > 0 ? round( $engaged / $participants, 4 ) : 0.0,
		);
	}
}
