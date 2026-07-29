<?php
/**
 * Notification event subscriber.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Notification;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Contracts\SubscriberInterface;
use Yazan\Rewards\Core\Events\Event;
use Yazan\Rewards\Core\Events\Events;
use Yazan\Rewards\Modules\Achievement\TierRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps user-facing domain events to per-user notifications. "Points earned" is
 * wired but excludes redemption refunds (source=redeem); its category defaults to
 * the daily digest so it is never noisy. "Level upgrade" fires only on an upgrade
 * (the copy is congratulatory). Broadcast campaign events (new / ending) are handled
 * separately — see CampaignNotificationSubscriber / CampaignEndingScanner.
 */
final class NotificationSubscriber implements SubscriberInterface {

	/**
	 * @param NotificationDispatcher $dispatcher Dispatcher.
	 * @param Container              $container  Container (optional Tier lookup for upgrade detection).
	 */
	public function __construct(
		private NotificationDispatcher $dispatcher,
		private Container $container
	) {}

	/**
	 * @inheritDoc
	 */
	public function subscribed_events(): array {
		return array(
			Events::REWARD_REDEEMED      => array( 'on_redeemed', 80 ),
			Events::POINTS_CREDITED      => array( 'on_points_credited', 80 ),
			Events::POINTS_EXPIRED       => array( 'on_points_expired', 80 ),
			Events::POINTS_EXPIRING_SOON => array( 'on_points_expiring', 80 ),
			Events::TIER_CHANGED         => array( 'on_tier', 80 ),
			Events::ACHIEVEMENT_UNLOCKED => array( 'on_achievement', 80 ),
			Events::COMMISSION_EARNED    => array( 'on_commission', 80 ),
			Events::REFERRAL_CONVERTED   => array( 'on_referral', 80 ),
			Events::SERVICE_REQUESTED    => array( 'on_service_requested', 80 ),
			Events::SERVICE_FULFILLED    => array( 'on_service_fulfilled', 80 ),
			Events::CAMPAIGN_SUBMISSION_APPROVED => array( 'on_campaign_approved', 80 ),
			Events::CAMPAIGN_SUBMISSION_REJECTED => array( 'on_campaign_rejected', 80 ),
			Events::CAMPAIGN_COMPLETED           => array( 'on_campaign_completed', 80 ),
		);
	}

	/**
	 * Points earned — excludes redemption refunds (which also credit with source=redeem).
	 *
	 * @param Event $e points_credited.
	 * @return void
	 */
	public function on_points_credited( Event $e ): void {
		if ( 'redeem' === (string) $e->get( 'source' ) ) {
			return; // A refund from a failed/reversed redemption, not an earning.
		}
		$this->dispatcher->notify( (int) $e->get( 'user_id' ), 'points_earned', $e->payload() );
	}

	/**
	 * @param Event $e points_expired.
	 * @return void
	 */
	public function on_points_expired( Event $e ): void {
		$this->dispatcher->notify( (int) $e->get( 'user_id' ), 'points_expired', $e->payload() );
	}

	/**
	 * @param Event $e points_expiring_soon.
	 * @return void
	 */
	public function on_points_expiring( Event $e ): void {
		$this->dispatcher->notify( (int) $e->get( 'user_id' ), 'points_expiring', $e->payload() );
	}

	/**
	 * @param Event $e reward_redeemed.
	 * @return void
	 */
	public function on_redeemed( Event $e ): void {
		$this->dispatcher->notify( (int) $e->get( 'user_id' ), 'reward_redeemed', $e->payload() );
	}

	/**
	 * Level change — notify only on an upgrade (the copy congratulates).
	 *
	 * @param Event $e tier_changed.
	 * @return void
	 */
	public function on_tier( Event $e ): void {
		$from = (string) $e->get( 'from' );
		$to   = (string) $e->get( 'to' );
		if ( '' !== $from && ! $this->is_upgrade( $from, $to ) ) {
			return; // Downgrade / lateral move — no congratulatory notification.
		}
		$this->dispatcher->notify( (int) $e->get( 'user_id' ), 'tier_changed', $e->payload() );
	}

	/**
	 * Whether moving from one tier slug to another is an upgrade, using the tier
	 * ladder (ascending by threshold). Falls back to "yes" when tiers are unavailable.
	 *
	 * @param string $from From slug.
	 * @param string $to   To slug.
	 * @return bool
	 */
	private function is_upgrade( string $from, string $to ): bool {
		if ( ! $this->container->has( TierRepository::class ) ) {
			return true;
		}
		$order = array();
		$index = 0;
		foreach ( $this->container->get( TierRepository::class )->all() as $tier ) {
			$order[ (string) $tier->slug ] = $index++;
		}
		return ( $order[ $to ] ?? -1 ) > ( $order[ $from ] ?? -1 );
	}

	/**
	 * @param Event $e achievement_unlocked.
	 * @return void
	 */
	public function on_achievement( Event $e ): void {
		$this->dispatcher->notify( (int) $e->get( 'user_id' ), 'achievement_unlocked', $e->payload() );
	}

	/**
	 * @param Event $e commission_earned.
	 * @return void
	 */
	public function on_commission( Event $e ): void {
		$this->dispatcher->notify( (int) $e->get( 'user_id' ), 'commission_earned', $e->payload() );
	}

	/**
	 * @param Event $e referral_converted.
	 * @return void
	 */
	public function on_referral( Event $e ): void {
		$this->dispatcher->notify( (int) $e->get( 'referrer_id' ), 'referral_converted', $e->payload() );
	}

	/**
	 * Notify the customer + email the owner that a service was requested.
	 *
	 * @param Event $e service_requested.
	 * @return void
	 */
	public function on_service_requested( Event $e ): void {
		$this->dispatcher->notify( (int) $e->get( 'user_id' ), 'service_requested', $e->payload() );

		// Owner alert so staff can action the fulfillment queue.
		$owner = (string) apply_filters( 'yazan_rewards/service/owner_email', get_option( 'admin_email' ) );
		if ( is_email( $owner ) ) {
			wp_mail(
				$owner,
				__( 'New service reward to fulfill', 'yazan-rewards' ),
				sprintf(
					/* translators: 1: service type, 2: voucher code. */
					__( 'A customer redeemed a service reward (%1$s, voucher %2$s). See Yazan Rewards → Service Queue.', 'yazan-rewards' ),
					(string) $e->get( 'type' ),
					(string) $e->get( 'code' )
				)
			);
		}
	}

	/**
	 * @param Event $e service_fulfilled.
	 * @return void
	 */
	public function on_service_fulfilled( Event $e ): void {
		$this->dispatcher->notify( (int) $e->get( 'user_id' ), 'service_fulfilled', $e->payload() );
	}

	/**
	 * @param Event $e campaign_submission_approved.
	 * @return void
	 */
	public function on_campaign_approved( Event $e ): void {
		$this->dispatcher->notify( (int) $e->get( 'user_id' ), 'campaign_submission_approved', $e->payload() );
	}

	/**
	 * @param Event $e campaign_submission_rejected.
	 * @return void
	 */
	public function on_campaign_rejected( Event $e ): void {
		$this->dispatcher->notify( (int) $e->get( 'user_id' ), 'campaign_submission_rejected', $e->payload() );
	}

	/**
	 * @param Event $e campaign_completed.
	 * @return void
	 */
	public function on_campaign_completed( Event $e ): void {
		$this->dispatcher->notify( (int) $e->get( 'user_id' ), 'campaign_completed', $e->payload() );
	}
}
