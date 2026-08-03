<?php
/**
 * Points expiry job.
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
 * Expires stale points on a daily Action Scheduler job. Off by default
 * (expiry_months = 0); when enabled, credits are stamped with an expiry and this
 * job flips past-due entries, reconciles the balance, and notifies.
 */
final class ExpiryService implements Hookable {

	/** Recurring job hook. */
	public const HOOK = 'yazan_rewards/points/expire_due';

	/**
	 * @param PointsRepository $repo      Repository.
	 * @param PointsLedger     $ledger    Ledger (for reconcile).
	 * @param EventBus         $bus       Event bus.
	 * @param Scheduler        $scheduler Scheduler.
	 * @param Settings         $settings  Settings.
	 */
	public function __construct(
		private PointsRepository $repo,
		private PointsLedger $ledger,
		private EventBus $bus,
		private Scheduler $scheduler,
		private Settings $settings
	) {}

	/**
	 * @inheritDoc
	 */
	public function hooks(): array {
		return array(
			// Scheduled on `init`: Action Scheduler's store isn't ready at plugins_loaded/boot,
			// so a recurring action queued there never persists.
			array(
				'type'     => 'action',
				'hook'     => 'init',
				'method'   => 'ensure_scheduled',
				'priority' => 20,
			),
			array(
				'type'   => 'action',
				'hook'   => self::HOOK,
				'method' => 'run',
				'job'    => true,
			),
		);
	}

	/**
	 * Ensure the daily job is scheduled (idempotent; only when expiry is enabled).
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		if ( $this->scheduler->is_ready() ) {
			return;
		}
		if ( (int) $this->settings->get( 'points.expiry_months', 0 ) <= 0 ) {
			return; // Expiry disabled — no job needed.
		}
		$this->scheduler->recurring( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK );
	}

	/**
	 * The scheduled task: expire due entries and reconcile affected users.
	 *
	 * @return void
	 */
	public function run(): void {
		$users = $this->repo->expire_due();
		foreach ( $users as $user_id ) {
			$balance = $this->ledger->reconcile( $user_id );
			$this->bus->dispatch(
				new GenericEvent(
					Events::POINTS_EXPIRED,
					array(
						'user_id'       => $user_id,
						'balance_after' => $balance,
					)
				)
			);
		}
	}

	/**
	 * Compute an expiry timestamp for a fresh credit, or null when disabled.
	 *
	 * @return int|null Unix timestamp or null.
	 */
	public function expiry_for_new_credit(): ?int {
		$months = (int) $this->settings->get( 'points.expiry_months', 0 );
		if ( $months <= 0 ) {
			return null;
		}
		return strtotime( "+{$months} months" ) ?: null;
	}
}
