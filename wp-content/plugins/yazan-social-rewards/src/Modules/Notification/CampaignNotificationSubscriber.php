<?php
/**
 * Campaign broadcast subscriber (new campaign).
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Notification;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Contracts\SubscriberInterface;
use Yazan\Rewards\Core\Events\Event;
use Yazan\Rewards\Core\Events\Events;
use Yazan\Rewards\Modules\Campaigns\Campaign;
use Yazan\Rewards\Modules\Campaigns\CampaignEligibility;
use Yazan\Rewards\Modules\Campaigns\MarketingCampaignRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Broadcasts a "new campaign" notification to the eligible audience the first time a
 * campaign becomes active (`CAMPAIGN_STATUS_CHANGED` → active). De-duplicated by an
 * option list so a pause→resume doesn't re-blast. Campaigns services are resolved
 * optionally, so this is inert when the marketing engine is unavailable.
 */
final class CampaignNotificationSubscriber implements SubscriberInterface {

	/** Option holding campaign ids already announced as "new". */
	private const NOTIFIED_OPTION = 'yazan_rewards_campaign_new_notified';

	/**
	 * @param Container              $container   Container.
	 * @param NotificationBroadcaster $broadcaster Broadcaster.
	 */
	public function __construct(
		private Container $container,
		private NotificationBroadcaster $broadcaster
	) {}

	/**
	 * @inheritDoc
	 */
	public function subscribed_events(): array {
		return array(
			Events::CAMPAIGN_STATUS_CHANGED => array( 'on_status_changed', 80 ),
		);
	}

	/**
	 * @param Event $e campaign_status_changed { campaign_id, from, to }.
	 * @return void
	 */
	public function on_status_changed( Event $e ): void {
		if ( Campaign::STATUS_ACTIVE !== (string) $e->get( 'to' ) ) {
			return;
		}
		$campaign_id = (int) $e->get( 'campaign_id' );
		if ( $campaign_id <= 0 ) {
			return;
		}

		$notified = array_map( 'intval', (array) get_option( self::NOTIFIED_OPTION, array() ) );
		if ( in_array( $campaign_id, $notified, true ) ) {
			return; // Already announced (first activation only).
		}

		if ( ! $this->container->has( MarketingCampaignRepository::class ) || ! $this->container->has( CampaignEligibility::class ) ) {
			return;
		}
		$campaign = $this->container->get( MarketingCampaignRepository::class )->get( $campaign_id );
		if ( ! $campaign ) {
			return;
		}

		$users = $this->container->get( CampaignEligibility::class )->audience( (array) $campaign->target );
		$this->broadcaster->broadcast(
			$users,
			'campaign_new',
			array( 'campaign_id' => $campaign_id, 'title' => (string) $campaign->title )
		);

		$notified[] = $campaign_id;
		update_option( self::NOTIFIED_OPTION, array_slice( array_values( array_unique( $notified ) ), -500 ), false );
	}
}
