<?php
/**
 * Social engagement-metrics collector.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Social;

use Yazan\Rewards\Core\Contracts\Hookable;
use Yazan\Rewards\Core\Contracts\SubscriberInterface;
use Yazan\Rewards\Core\Events\Event;
use Yazan\Rewards\Core\Events\Events;
use Yazan\Rewards\Core\Support\Crypto;
use Yazan\Rewards\Core\Support\Scheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The metrics-provider seam. When a UGC action is approved, it schedules an async
 * fetch of the post's engagement metrics (likes/views/…) via the network connector —
 * but only when that connector's API is configured and the customer's account is
 * linked. Otherwise it is a no-op. Runs off Action Scheduler so a slow API never
 * blocks the request; stores the result on the action for analytics.
 */
final class MetricsService implements SubscriberInterface, Hookable {

	/** Action Scheduler hook for the async fetch. */
	private const HOOK = 'yazan_rewards/social/fetch_metrics';

	/**
	 * @param SocialRepository        $repo     Actions repo.
	 * @param SocialAccountRepository  $accounts Linked accounts.
	 * @param ConnectorRegistry        $registry Connectors.
	 * @param Scheduler                $scheduler Job scheduler.
	 * @param Crypto                   $crypto   Token decryption.
	 */
	public function __construct(
		private SocialRepository $repo,
		private SocialAccountRepository $accounts,
		private ConnectorRegistry $registry,
		private Scheduler $scheduler,
		private Crypto $crypto
	) {}

	/**
	 * @inheritDoc
	 */
	public function subscribed_events(): array {
		return array(
			Events::SOCIAL_ACTION_APPROVED => array( 'on_approved', 60 ),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function hooks(): array {
		return array(
			array( 'type' => 'action', 'hook' => self::HOOK, 'method' => 'run', 'args' => 1, 'job' => true ),
		);
	}

	/**
	 * Queue a metrics fetch for a freshly approved action.
	 *
	 * @param Event $e social_action_approved.
	 * @return void
	 */
	public function on_approved( Event $e ): void {
		$action_id = (int) $e->get( 'action_id' );
		if ( $action_id <= 0 ) {
			return;
		}
		if ( $this->scheduler->available() ) {
			$this->scheduler->async( self::HOOK, array( $action_id ) );
		} else {
			$this->run( $action_id ); // Inline fallback.
		}
	}

	/**
	 * Fetch + store metrics for an action (no-op unless the API path is live).
	 *
	 * @param int $action_id Action id.
	 * @return void
	 */
	public function run( $action_id ): void {
		$action = $this->repo->get( (int) $action_id );
		if ( ! $action || 'ugc' !== $action->action_type ) {
			return;
		}
		$connector = $this->registry->get( (string) $action->platform );
		if ( ! $connector || ! $connector->is_configured() ) {
			return;
		}
		$account = $this->accounts->for_user_platform( (int) $action->user_id, (string) $action->platform );
		if ( ! $account || empty( $account->verified ) ) {
			return;
		}
		$metrics = $connector->fetch_metrics(
			(string) $action->submission_url,
			array( 'access_token' => $this->crypto->decrypt( (string) ( $account->access_token ?? '' ) ) )
		);
		if ( is_array( $metrics ) ) {
			$this->repo->save_metrics( (int) $action->id, $metrics );
		}
	}
}
