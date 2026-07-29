<?php
/**
 * Achievement progress tracker.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Achievement;

use Yazan\Rewards\Core\Contracts\PointsLedgerInterface;
use Yazan\Rewards\Core\Contracts\SubscriberInterface;
use Yazan\Rewards\Core\Events\Event;
use Yazan\Rewards\Core\Events\Events;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Events\GenericEvent;
use Yazan\Rewards\Core\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Advances achievement progress from domain events and unlocks badges when the
 * criteria are met, awarding the achievement's points. Achievements are
 * data-driven: each definition names the event it counts and a threshold, so new
 * badges need no code.
 */
final class ProgressTracker implements SubscriberInterface {

	/**
	 * @param AchievementRepository $repo     Achievements.
	 * @param PointsLedgerInterface $points   Points ledger.
	 * @param EventBus              $bus      Event bus.
	 * @param Settings              $settings Settings.
	 */
	public function __construct(
		private AchievementRepository $repo,
		private PointsLedgerInterface $points,
		private EventBus $bus,
		private Settings $settings
	) {}

	/**
	 * @inheritDoc
	 */
	public function subscribed_events(): array {
		return array(
			Events::ORDER_REWARDED      => array( 'on_order', 40 ),
			Events::REVIEW_CREATED      => array( 'on_review', 40 ),
			Events::REFERRAL_CONVERTED  => array( 'on_referral', 40 ),
			Events::AMBASSADOR_APPROVED => array( 'on_ambassador', 40 ),
		);
	}

	/**
	 * @param Event $event order_rewarded.
	 * @return void
	 */
	public function on_order( Event $event ): void {
		$this->process(
			Events::ORDER_REWARDED,
			(int) $event->get( 'customer_id' ),
			array( 'order_total' => (float) $event->get( 'order_total' ) )
		);
	}

	/**
	 * @param Event $event review_created.
	 * @return void
	 */
	public function on_review( Event $event ): void {
		$this->process( Events::REVIEW_CREATED, (int) $event->get( 'user_id' ), array() );
	}

	/**
	 * @param Event $event referral_converted.
	 * @return void
	 */
	public function on_referral( Event $event ): void {
		$this->process( Events::REFERRAL_CONVERTED, (int) $event->get( 'referrer_id' ), array() );
	}

	/**
	 * @param Event $event ambassador_approved.
	 * @return void
	 */
	public function on_ambassador( Event $event ): void {
		$this->process( Events::AMBASSADOR_APPROVED, (int) $event->get( 'user_id' ), array() );
	}

	/**
	 * Advance and maybe unlock every achievement bound to this event.
	 *
	 * @param string $event_key Event key.
	 * @param int    $user_id   User id.
	 * @param array  $context   Extra facts (order_total…).
	 * @return void
	 */
	private function process( string $event_key, int $user_id, array $context ): void {
		if ( $user_id <= 0 || ! $this->settings->feature_enabled( 'achievement' ) ) {
			return;
		}

		foreach ( $this->repo->for_event( $event_key ) as $achievement ) {
			$criteria = $achievement->criteria_decoded ?? array();

			// Optional gate: minimum order total.
			if ( isset( $criteria['min_total'] ) && (float) ( $context['order_total'] ?? 0 ) < (float) $criteria['min_total'] ) {
				continue;
			}

			$threshold = max( 1, (int) ( $criteria['threshold'] ?? 1 ) );
			$progress  = $this->repo->bump_progress( $user_id, (int) $achievement->id, 1 );

			if ( $progress < $threshold ) {
				continue;
			}

			if ( ! $this->repo->unlock( $user_id, (int) $achievement->id ) ) {
				continue; // Already unlocked.
			}

			$award = (int) $achievement->points_award;
			if ( $award > 0 ) {
				$this->points->credit(
					$user_id,
					$award,
					'achievement',
					(int) $achievement->id,
					array( 'type' => 'earn', 'note' => sprintf( /* translators: %s: achievement name. */ __( 'Achievement: %s', 'yazan-rewards' ), $achievement->name ) )
				);
			}

			$this->bus->dispatch(
				new GenericEvent(
					Events::ACHIEVEMENT_UNLOCKED,
					array(
						'user_id'        => $user_id,
						'achievement_id' => (int) $achievement->id,
						'key'            => (string) $achievement->akey,
						'name'           => (string) $achievement->name,
						'points_award'   => $award,
					)
				)
			);
		}
	}
}
