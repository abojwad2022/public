<?php
/**
 * Campaign status workflow + scheduler.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Campaigns;

use Yazan\Rewards\Core\Contracts\Hookable;
use Yazan\Rewards\Core\Events\Events;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Events\GenericEvent;
use Yazan\Rewards\Core\Support\Scheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enforces the campaign status workflow and auto-transitions campaigns by date.
 * Transitions:
 *   draft     → scheduled | active | archived
 *   scheduled → active | paused | draft | archived
 *   active    → paused | completed | archived
 *   paused    → active | completed | archived
 *   completed → archived
 *   archived  → (terminal)
 * A daily Action Scheduler job auto-activates Scheduled campaigns whose start has
 * passed, and completes Active campaigns whose end has passed.
 */
final class CampaignWorkflow implements Hookable {

	/** Recurring job hook. */
	public const HOOK = 'yazan_rewards/campaign/tick';

	/** Allowed transitions map. */
	private const ALLOWED = array(
		Campaign::STATUS_DRAFT     => array( Campaign::STATUS_SCHEDULED, Campaign::STATUS_ACTIVE, Campaign::STATUS_ARCHIVED ),
		Campaign::STATUS_SCHEDULED => array( Campaign::STATUS_ACTIVE, Campaign::STATUS_PAUSED, Campaign::STATUS_DRAFT, Campaign::STATUS_ARCHIVED ),
		Campaign::STATUS_ACTIVE    => array( Campaign::STATUS_PAUSED, Campaign::STATUS_COMPLETED, Campaign::STATUS_ARCHIVED ),
		Campaign::STATUS_PAUSED    => array( Campaign::STATUS_ACTIVE, Campaign::STATUS_COMPLETED, Campaign::STATUS_ARCHIVED ),
		Campaign::STATUS_COMPLETED => array( Campaign::STATUS_ARCHIVED ),
		Campaign::STATUS_ARCHIVED  => array(),
	);

	/**
	 * @param MarketingCampaignRepository $repo      Campaign repository.
	 * @param EventBus                    $bus       Event bus.
	 * @param Scheduler                   $scheduler Scheduler.
	 */
	public function __construct(
		private MarketingCampaignRepository $repo,
		private EventBus $bus,
		private Scheduler $scheduler
	) {}

	/**
	 * @inheritDoc
	 */
	public function hooks(): array {
		return array(
			// Scheduled on `init`: Action Scheduler's store isn't ready at plugins_loaded/boot,
			// so a recurring action queued there never persists.
			array( 'type' => 'action', 'hook' => 'init', 'method' => 'ensure_scheduled', 'priority' => 20 ),
			array( 'type' => 'action', 'hook' => self::HOOK, 'method' => 'tick', 'job' => true ),
		);
	}

	/**
	 * Ensure the daily job is scheduled (idempotent).
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		if ( $this->scheduler->is_ready() ) {
			return;
		}
		$this->scheduler->recurring( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK );
	}

	/**
	 * Whether a transition is allowed.
	 *
	 * @param string $from Current status.
	 * @param string $to   Target status.
	 * @return bool
	 */
	public function can_transition( string $from, string $to ): bool {
		return in_array( $to, self::ALLOWED[ $from ] ?? array(), true );
	}

	/**
	 * Valid statuses.
	 *
	 * @return string[]
	 */
	public function statuses(): array {
		return array( Campaign::STATUS_DRAFT, Campaign::STATUS_SCHEDULED, Campaign::STATUS_ACTIVE, Campaign::STATUS_PAUSED, Campaign::STATUS_COMPLETED, Campaign::STATUS_ARCHIVED );
	}

	/**
	 * Transition a campaign. Returns true on success.
	 *
	 * @param int    $campaign_id Campaign id.
	 * @param string $to          Target status.
	 * @return bool
	 */
	public function transition( int $campaign_id, string $to ): bool {
		$campaign = $this->repo->get( $campaign_id );
		if ( ! $campaign ) {
			return false;
		}
		if ( $campaign->status === $to ) {
			return true;
		}
		if ( ! $this->can_transition( $campaign->status, $to ) ) {
			return false;
		}
		$this->apply( $campaign_id, $campaign->status, $to );
		return true;
	}

	/**
	 * The daily tick: auto activate/complete by date.
	 *
	 * @return void
	 */
	public function tick(): void {
		$due = $this->repo->due_transitions();
		foreach ( $due['activate'] as $id ) {
			$this->apply( $id, Campaign::STATUS_SCHEDULED, Campaign::STATUS_ACTIVE );
		}
		foreach ( $due['complete'] as $id ) {
			$this->apply( $id, Campaign::STATUS_ACTIVE, Campaign::STATUS_COMPLETED );
		}
	}

	/**
	 * Persist a transition + announce it.
	 *
	 * @param int    $id   Campaign id.
	 * @param string $from From status.
	 * @param string $to   To status.
	 * @return void
	 */
	private function apply( int $id, string $from, string $to ): void {
		$this->repo->set_status( $id, $to );
		$this->bus->dispatch(
			new GenericEvent(
				Events::CAMPAIGN_STATUS_CHANGED,
				array( 'campaign_id' => $id, 'from' => $from, 'to' => $to )
			)
		);
	}
}
