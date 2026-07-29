<?php
/**
 * Activity logger (event subscriber).
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Activity;

use Yazan\Rewards\Core\Contracts\SubscriberInterface;
use Yazan\Rewards\Core\Events\Event;
use Yazan\Rewards\Core\Events\Events;
use Yazan\Rewards\Core\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns domain events into a human-readable per-user activity feed. Subscribes at
 * a late priority (95) so it records the final state after every other engine has
 * acted. Purely a consumer — it never writes to points/wallet, so it cannot
 * affect balances.
 */
final class ActivityLogger implements SubscriberInterface {

	/**
	 * @param ActivityRepository $repo     Activity repository.
	 * @param Settings           $settings Settings.
	 */
	public function __construct(
		private ActivityRepository $repo,
		private Settings $settings
	) {}

	/**
	 * @inheritDoc
	 */
	public function subscribed_events(): array {
		return array(
			Events::POINTS_CREDITED      => array( 'on_points_credited', 95 ),
			Events::POINTS_DEBITED       => array( 'on_points_debited', 95 ),
			Events::REWARD_REDEEMED      => array( 'on_reward_redeemed', 95 ),
			Events::TIER_CHANGED         => array( 'on_tier_changed', 95 ),
			Events::ACHIEVEMENT_UNLOCKED => array( 'on_achievement', 95 ),
			Events::COMMISSION_EARNED    => array( 'on_commission', 95 ),
			Events::REFERRAL_CONVERTED   => array( 'on_referral', 95 ),
			Events::SOCIAL_ACTION_APPROVED => array( 'on_social', 95 ),
		);
	}

	/**
	 * @param Event $e points_credited.
	 * @return void
	 */
	public function on_points_credited( Event $e ): void {
		$points = (int) $e->get( 'points' );
		$this->record(
			(int) $e->get( 'user_id' ),
			'points_earned',
			sprintf( /* translators: %d: points. */ __( 'Earned %d points', 'yazan-rewards' ), $points ),
			(string) $e->get( 'source' ),
			(int) $e->get( 'source_id' ),
			$points
		);
	}

	/**
	 * @param Event $e points_debited.
	 * @return void
	 */
	public function on_points_debited( Event $e ): void {
		$points = (int) $e->get( 'points' );
		$this->record(
			(int) $e->get( 'user_id' ),
			'points_spent',
			sprintf( /* translators: %d: points. */ __( 'Spent %d points', 'yazan-rewards' ), $points ),
			(string) $e->get( 'source' ),
			(int) $e->get( 'source_id' ),
			-$points
		);
	}

	/**
	 * @param Event $e reward_redeemed.
	 * @return void
	 */
	public function on_reward_redeemed( Event $e ): void {
		$this->record(
			(int) $e->get( 'user_id' ),
			'reward_redeemed',
			__( 'Redeemed a reward', 'yazan-rewards' ),
			'reward',
			(int) $e->get( 'reward_id' ),
			-(int) $e->get( 'points_spent' )
		);
	}

	/**
	 * @param Event $e tier_changed.
	 * @return void
	 */
	public function on_tier_changed( Event $e ): void {
		$tier = $e->get( 'tier' );
		$name = is_array( $tier ) ? (string) ( $tier['name'] ?? '' ) : '';
		$this->record(
			(int) $e->get( 'user_id' ),
			'tier_changed',
			sprintf( /* translators: %s: tier name. */ __( 'Reached the %s tier', 'yazan-rewards' ), $name ),
			'tier',
			0,
			0
		);
	}

	/**
	 * @param Event $e achievement_unlocked.
	 * @return void
	 */
	public function on_achievement( Event $e ): void {
		$this->record(
			(int) $e->get( 'user_id' ),
			'achievement_unlocked',
			sprintf( /* translators: %s: achievement name. */ __( 'Unlocked "%s"', 'yazan-rewards' ), (string) $e->get( 'name' ) ),
			'achievement',
			(int) $e->get( 'achievement_id' ),
			(int) $e->get( 'points_award' )
		);
	}

	/**
	 * @param Event $e commission_earned.
	 * @return void
	 */
	public function on_commission( Event $e ): void {
		$this->record(
			(int) $e->get( 'user_id' ),
			'commission_earned',
			__( 'Earned an ambassador commission', 'yazan-rewards' ),
			'order',
			(int) $e->get( 'order_id' ),
			0
		);
	}

	/**
	 * @param Event $e referral_converted.
	 * @return void
	 */
	public function on_referral( Event $e ): void {
		$this->record(
			(int) $e->get( 'referrer_id' ),
			'referral_converted',
			__( 'A referral placed their first order', 'yazan-rewards' ),
			'referral',
			(int) $e->get( 'referral_id' ),
			0
		);
	}

	/**
	 * @param Event $e social_action_approved.
	 * @return void
	 */
	public function on_social( Event $e ): void {
		$this->record(
			(int) $e->get( 'user_id' ),
			'social_approved',
			__( 'Social content approved', 'yazan-rewards' ),
			'social',
			(int) $e->get( 'action_id' ),
			(int) $e->get( 'points' )
		);
	}

	/**
	 * Write a row (guarded by the feature flag).
	 *
	 * @param int    $user_id     User id.
	 * @param string $type        Activity type.
	 * @param string $description Human text.
	 * @param string $object_type Related object type.
	 * @param int    $object_id   Related object id.
	 * @param int    $points      Signed points delta (0 if n/a).
	 * @return void
	 */
	private function record( int $user_id, string $type, string $description, string $object_type, int $object_id, int $points ): void {
		if ( $user_id <= 0 || ! $this->settings->feature_enabled( 'activity' ) ) {
			return;
		}
		$this->repo->log(
			array(
				'user_id'       => $user_id,
				'activity_type' => $type,
				'description'   => $description,
				'object_type'   => $object_type,
				'object_id'     => $object_id,
				'points'        => $points,
			)
		);
	}
}
