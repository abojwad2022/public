<?php
/**
 * Campaign-ending broadcast scanner.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Notification;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Contracts\Hookable;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Core\Support\Scheduler;
use Yazan\Rewards\Modules\Campaigns\CampaignEligibility;
use Yazan\Rewards\Modules\Campaigns\MarketingCampaignRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A daily scan that broadcasts a "campaign ending soon" notification to each active
 * campaign's eligible audience when its `ends_at` falls within
 * `notification.campaign_ending_days` (default 3). De-duplicated by an option list so
 * a campaign is announced once. Modelled on {@see \Yazan\Rewards\Modules\Rules\BirthdayScheduler}.
 */
final class CampaignEndingScanner implements Hookable {

	/** Recurring scan hook. */
	public const HOOK = 'yzrw_notification_campaign_ending';

	/** Option holding campaign ids already announced as "ending". */
	private const NOTIFIED_OPTION = 'yazan_rewards_campaign_ending_notified';

	/**
	 * @param Container               $container   Container.
	 * @param NotificationBroadcaster $broadcaster Broadcaster.
	 * @param Scheduler               $scheduler   Scheduler.
	 * @param Settings                $settings    Settings.
	 */
	public function __construct(
		private Container $container,
		private NotificationBroadcaster $broadcaster,
		private Scheduler $scheduler,
		private Settings $settings
	) {}

	/**
	 * @inheritDoc
	 */
	public function hooks(): array {
		return array(
			// Scheduling happens on `init` (not boot): Action Scheduler's store isn't
			// ready at plugins_loaded, so a recurring action queued there never persists.
			array( 'type' => 'action', 'hook' => 'init', 'method' => 'ensure_scheduled', 'priority' => 20 ),
			array( 'type' => 'action', 'hook' => self::HOOK, 'method' => 'run' ),
		);
	}

	/**
	 * Ensure the daily scan is scheduled (idempotent) while the marketing engine is on.
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		if ( $this->scheduler->is_ready() ) {
			return;
		}
		if ( ! $this->settings->feature_enabled( 'marketing' ) ) {
			return;
		}
		if ( (int) $this->settings->get( 'notification.campaign_ending_days', 3 ) <= 0 ) {
			return; // "Campaign ending" broadcasts disabled.
		}
		$this->scheduler->recurring( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK );
	}

	/**
	 * The scan: announce active campaigns ending within the window.
	 *
	 * @return void
	 */
	public function run(): void {
		if ( ! $this->container->has( MarketingCampaignRepository::class ) || ! $this->container->has( CampaignEligibility::class ) ) {
			return;
		}
		$days = (int) $this->settings->get( 'notification.campaign_ending_days', 3 );
		if ( $days <= 0 ) {
			return;
		}

		$repo   = $this->container->get( MarketingCampaignRepository::class );
		$elig   = $this->container->get( CampaignEligibility::class );
		$now    = current_time( 'mysql' );
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' +' . $days . ' days' ) );

		$notified = array_map( 'intval', (array) get_option( self::NOTIFIED_OPTION, array() ) );

		foreach ( $repo->active() as $campaign ) {
			$ends = (string) ( $campaign->ends_at ?? '' );
			if ( '' === $ends || $ends < $now || $ends > $cutoff ) {
				continue; // No end date, already past, or beyond the window.
			}
			if ( in_array( (int) $campaign->id, $notified, true ) ) {
				continue;
			}

			$users = $elig->audience( (array) $campaign->target );
			$this->broadcaster->broadcast(
				$users,
				'campaign_ending',
				array( 'campaign_id' => (int) $campaign->id, 'title' => (string) $campaign->title, 'ends_at' => $ends )
			);
			$notified[] = (int) $campaign->id;
		}

		update_option( self::NOTIFIED_OPTION, array_slice( array_values( array_unique( $notified ) ), -500 ), false );
	}
}
