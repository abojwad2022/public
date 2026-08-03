<?php
/**
 * Pre-expiry points reminder job.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Points;

use Yazan\Rewards\Core\Contracts\Hookable;
use Yazan\Rewards\Core\Events\Events;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Events\GenericEvent;
use Yazan\Rewards\Core\Support\Scheduler;
use Yazan\Rewards\Core\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Warns customers BEFORE their points expire (the counterpart to {@see ExpiryService},
 * which only flips already-expired points). A daily Action Scheduler job finds points
 * expiring within `points.expiry_reminder_days` and dispatches POINTS_EXPIRING_SOON so
 * the notification engine can reach out. De-duplicated per user by the earliest
 * upcoming expiry date (user meta), so a customer is reminded once per expiry batch —
 * not every day of the window. Only scheduled when expiry itself is enabled.
 */
final class ExpiryReminderService implements Hookable {

	/** Recurring job hook. */
	public const HOOK = 'yazan_rewards/points/expiry_reminder';

	/** User meta: the earliest-expiry date a reminder was last sent for. */
	private const META_KEY = '_yzrw_expiry_reminded';

	/**
	 * @param PointsRepository $repo      Repository.
	 * @param EventBus         $bus       Event bus.
	 * @param Scheduler        $scheduler Scheduler.
	 * @param Settings         $settings  Settings.
	 */
	public function __construct(
		private PointsRepository $repo,
		private EventBus $bus,
		private Scheduler $scheduler,
		private Settings $settings
	) {}

	/**
	 * @inheritDoc
	 */
	public function hooks(): array {
		return array(
			// Scheduled on `init` — Action Scheduler's store isn't ready at boot time.
			array( 'type' => 'action', 'hook' => 'init', 'method' => 'ensure_scheduled', 'priority' => 20 ),
			array( 'type' => 'action', 'hook' => self::HOOK, 'method' => 'run', 'job' => true ),
		);
	}

	/**
	 * Ensure the daily reminder job is scheduled (only when expiry + reminders are on).
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		if ( $this->scheduler->is_ready() ) {
			return;
		}
		if ( (int) $this->settings->get( 'points.expiry_months', 0 ) <= 0 ) {
			return; // Points never expire — nothing to remind about.
		}
		if ( (int) $this->settings->get( 'points.expiry_reminder_days', 14 ) <= 0 ) {
			return; // Reminder disabled.
		}
		$this->scheduler->recurring( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK );
	}

	/**
	 * The scheduled task: notify users with points expiring within the window.
	 *
	 * @return void
	 */
	public function run(): void {
		$days = (int) $this->settings->get( 'points.expiry_reminder_days', 14 );
		if ( $days <= 0 ) {
			return;
		}
		$now = current_time( 'mysql' );
		$to  = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' +' . $days . ' days' ) );

		foreach ( $this->repo->expiring_between( $now, $to ) as $row ) {
			$user_id  = (int) $row['user_id'];
			$earliest = substr( (string) $row['earliest'], 0, 10 );
			if ( $user_id <= 0 || '' === $earliest ) {
				continue;
			}
			// One reminder per distinct upcoming-expiry batch.
			if ( (string) get_user_meta( $user_id, self::META_KEY, true ) === $earliest ) {
				continue;
			}
			update_user_meta( $user_id, self::META_KEY, $earliest );

			$days_left = (int) max( 0, (int) ceil( ( strtotime( $earliest ) - current_time( 'timestamp' ) ) / DAY_IN_SECONDS ) );
			$this->bus->dispatch(
				new GenericEvent(
					Events::POINTS_EXPIRING_SOON,
					array(
						'user_id' => $user_id,
						'points'  => (int) $row['points'],
						'days'    => $days_left,
					)
				)
			);
		}
	}
}
