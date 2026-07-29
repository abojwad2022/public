<?php
/**
 * Social action service.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Social;

use Yazan\Rewards\Core\Contracts\PointsLedgerInterface;
use Yazan\Rewards\Core\Events\Events;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Events\GenericEvent;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Modules\AntiFraud\FraudReviewService;
use Yazan\Rewards\Modules\AntiFraud\RiskEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrates the social engine: recording share intents (awarded on click,
 * throttled) and UGC submissions verified by the connector framework — the network's
 * API when its keys are configured and the customer is linked, otherwise the six
 * manual checks. A clear pass auto-approves; anything ambiguous is queued for admin
 * review. Points for approved actions flow through the ledger (source `social`), so
 * anti-fraud and analytics see them like any other earn.
 */
final class SocialService {

	/**
	 * @param SocialRepository            $repo     Repository.
	 * @param PointsLedgerInterface        $points   Points ledger.
	 * @param SocialVerificationService    $verifier Verification orchestrator.
	 * @param ConnectorRegistry            $registry Connector registry.
	 * @param EventBus                     $bus      Event bus.
	 * @param Settings                     $settings Settings.
	 */
	public function __construct(
		private SocialRepository $repo,
		private PointsLedgerInterface $points,
		private SocialVerificationService $verifier,
		private ConnectorRegistry $registry,
		private EventBus $bus,
		private Settings $settings,
		private ?RiskEngine $risk = null,
		private ?FraudReviewService $review = null
	) {}

	/**
	 * Record a share intent and award share points (throttled per day).
	 *
	 * @param int    $user_id  User id.
	 * @param string $platform Platform key.
	 * @param string $url      Optional shared URL.
	 * @return array|\WP_Error
	 */
	public function share( int $user_id, string $platform, string $url = '' ) {
		if ( ! $this->guard( $user_id ) ) {
			return new \WP_Error( 'social_disabled', __( 'Social rewards are unavailable.', 'yazan-rewards' ), array( 'status' => 403 ) );
		}
		$platform = sanitize_key( $platform );
		if ( '' === $platform ) {
			return new \WP_Error( 'bad_platform', __( 'A platform is required.', 'yazan-rewards' ), array( 'status' => 400 ) );
		}

		$today = date_i18n( 'Y-m-d 00:00:00' );
		if ( $this->repo->count_since( $user_id, 'share', $today ) >= (int) $this->settings->get( 'social.share_daily_limit', 3 ) ) {
			return new \WP_Error( 'share_throttled', __( 'You have reached today\'s share limit.', 'yazan-rewards' ), array( 'status' => 429 ) );
		}

		$points_award = (int) $this->settings->get( 'social.share_points', 25 );
		$id           = $this->repo->create(
			array(
				'user_id'      => $user_id,
				'platform'     => $platform,
				'action_type'  => 'share',
				'target_url'   => $url,
				'status'       => 'approved',
				'verify_method' => 'trust',
				'points_award' => $points_award,
			)
		);
		if ( $points_award > 0 ) {
			$this->points->credit( $user_id, $points_award, 'social', $id, array( 'type' => 'earn', 'note' => __( 'Social share', 'yazan-rewards' ) ) );
		}
		return array( 'ok' => true, 'action_id' => $id, 'status' => 'approved', 'points' => $points_award );
	}

	/**
	 * Submit UGC (a post URL) for verification. The network is auto-detected from the
	 * URL when not given.
	 *
	 * @param int    $user_id        User id.
	 * @param string $network        Network id, or ''.
	 * @param string $submission_url Post URL.
	 * @param string $media_type     photo|video|story|reel (default photo).
	 * @return array|\WP_Error
	 */
	public function submit_ugc( int $user_id, string $network, string $submission_url, string $media_type = '' ) {
		if ( ! $this->guard( $user_id ) ) {
			return new \WP_Error( 'social_disabled', __( 'Social rewards are unavailable.', 'yazan-rewards' ), array( 'status' => 403 ) );
		}
		$url = esc_url_raw( $submission_url );
		if ( '' === $url ) {
			return new \WP_Error( 'bad_submission', __( 'A valid post URL is required.', 'yazan-rewards' ), array( 'status' => 400 ) );
		}

		$connector = '' !== $network ? $this->registry->get( sanitize_key( $network ) ) : $this->registry->for_url( $url );
		if ( ! $connector ) {
			return new \WP_Error( 'unknown_network', __( 'We could not recognise that link\'s network.', 'yazan-rewards' ), array( 'status' => 400 ) );
		}
		$network = $connector->network();
		if ( ! $this->network_enabled( $network ) ) {
			return new \WP_Error( 'network_disabled', __( 'That network is not accepted right now.', 'yazan-rewards' ), array( 'status' => 400 ) );
		}

		$media_type   = sanitize_key( $media_type ) ?: 'photo';
		$points_award = $this->points_for( $network );

		$id = $this->repo->create(
			array(
				'user_id'        => $user_id,
				'platform'       => $network,
				'action_type'    => 'ugc',
				'media_type'     => $media_type,
				'submission_url' => $url,
				'status'         => 'pending',
				'points_award'   => $points_award,
			)
		);
		if ( $id <= 0 ) {
			return new \WP_Error( 'create_failed', __( 'Could not record the submission.', 'yazan-rewards' ), array( 'status' => 500 ) );
		}

		// Verify (Method 1 API when live+linked, else Method 2 manual). Exclude this
		// just-created row from the duplicate check.
		$result = $this->verifier->verify( $user_id, $network, $url, $id );
		$this->repo->save_verification( $id, $result['checks'], (string) $result['method'] );

		// A verification-rejected submission is denied outright — no reward, and no
		// fraud case (there is nothing to hold or later release).
		if ( 'rejected' === $result['verdict'] ) {
			$this->reject( $id, 0 );
			return array( 'ok' => true, 'action_id' => $id, 'status' => 'rejected', 'method' => $result['method'], 'reasons' => $result['reasons'] ?? array(), 'checks' => $result['checks'] );
		}

		// Anti-fraud risk assessment. A flagged submission is HELD: it opens a fraud
		// case linked to this action (for the record + potential clawback) and an
		// otherwise-auto-approved submission is downgraded to pending, so flagged
		// content is never auto-paid.
		$held = false;
		if ( $this->risk && $this->review ) {
			$decision = $this->risk->assess( 'ugc', $user_id, array( 'url' => $url, 'exclude_id' => $id ) );
			if ( $decision->needs_review() ) {
				$held = true;
				$this->review->hold(
					array(
						'user_id'        => $user_id,
						'type'           => 'social_fraud',
						'severity'       => $decision->severity(),
						'score'          => $decision->score,
						'signals'        => $decision->reasons(),
						'source'         => 'social',
						'source_id'      => $id,
						'reward_kind'    => 'points',
						'reward_amount'  => $points_award,
						'reward_user_id' => $user_id,
						'context'        => array( 'url' => $url ),
					)
				);
			}
		}

		if ( ! $held && 'approved' === $result['verdict'] ) {
			$this->approve( $id, 0 );
			return array( 'ok' => true, 'action_id' => $id, 'status' => 'approved', 'method' => $result['method'], 'points' => $points_award, 'checks' => $result['checks'] );
		}
		return array( 'ok' => true, 'action_id' => $id, 'status' => 'pending', 'method' => $result['method'], 'held' => $held, 'points' => 0, 'checks' => $result['checks'] );
	}

	/**
	 * Approve a pending action and award its points (idempotent).
	 *
	 * @param int $action_id   Action id.
	 * @param int $reviewed_by Reviewer user id (0 = system).
	 * @return array|\WP_Error
	 */
	public function approve( int $action_id, int $reviewed_by ) {
		$action = $this->repo->get( $action_id );
		if ( ! $action ) {
			return new \WP_Error( 'not_found', __( 'Action not found.', 'yazan-rewards' ), array( 'status' => 404 ) );
		}
		// A rejected submission (denied by verification or a confirmed fraud case) can
		// never be approved/paid — this is what makes a fraud reject a hard lock.
		if ( 'rejected' === $action->status ) {
			return new \WP_Error( 'rejected', __( 'This submission was rejected and cannot be approved.', 'yazan-rewards' ), array( 'status' => 409 ) );
		}
		$award = (int) $action->points_award;

		// Idempotency: never re-credit an action already paid (status flag OR ledger).
		if ( 'approved' === $action->status || ( $award > 0 && $this->points->net_for_source( (int) $action->user_id, 'social', $action_id ) > 0 ) ) {
			$this->repo->set_status( $action_id, 'approved', $reviewed_by );
			return array( 'ok' => true, 'action_id' => $action_id, 'status' => 'approved' );
		}

		$this->repo->set_status( $action_id, 'approved', $reviewed_by );
		if ( $award > 0 ) {
			$this->points->credit( (int) $action->user_id, $award, 'social', $action_id, array( 'type' => 'earn', 'note' => __( 'Social content approved', 'yazan-rewards' ) ) );
		}

		$this->bus->dispatch(
			new GenericEvent(
				Events::SOCIAL_ACTION_APPROVED,
				array(
					'user_id'    => (int) $action->user_id,
					'action_id'  => $action_id,
					'points'     => $award,
					'media_type' => (string) ( $action->media_type ?? '' ),
				)
			)
		);
		return array( 'ok' => true, 'action_id' => $action_id, 'status' => 'approved', 'points' => $award );
	}

	/**
	 * Reject a pending action.
	 *
	 * @param int $action_id   Action id.
	 * @param int $reviewed_by Reviewer user id.
	 * @return array|\WP_Error
	 */
	public function reject( int $action_id, int $reviewed_by ) {
		$action = $this->repo->get( $action_id );
		if ( ! $action ) {
			return new \WP_Error( 'not_found', __( 'Action not found.', 'yazan-rewards' ), array( 'status' => 404 ) );
		}
		$this->repo->set_status( $action_id, 'rejected', $reviewed_by );
		return array( 'ok' => true, 'action_id' => $action_id, 'status' => 'rejected' );
	}

	/**
	 * Per-network UGC points (override or the global default).
	 *
	 * @param string $network Network id.
	 * @return int
	 */
	private function points_for( string $network ): int {
		$networks = (array) $this->settings->get( 'social.networks', array() );
		$override = $networks[ $network ]['points'] ?? null;
		return null !== $override ? max( 0, (int) $override ) : (int) $this->settings->get( 'social.ugc_points', 200 );
	}

	/**
	 * Whether a network accepts submissions (enabled unless explicitly disabled).
	 *
	 * @param string $network Network id.
	 * @return bool
	 */
	private function network_enabled( string $network ): bool {
		$networks = (array) $this->settings->get( 'social.networks', array() );
		return ! isset( $networks[ $network ]['enabled'] ) || (bool) $networks[ $network ]['enabled'];
	}

	/**
	 * Feature + login guard.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	private function guard( int $user_id ): bool {
		return $user_id > 0 && $this->settings->feature_enabled( 'social' );
	}
}
