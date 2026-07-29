<?php
/**
 * Notification cron registrar.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Notification;

use Yazan\Rewards\Core\Contracts\Hookable;
use Yazan\Rewards\Core\Support\Scheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps an hourly recurring action alive that flushes the email-digest queue.
 * Running hourly (rather than once a day) decouples the cron cadence from the
 * configured digest hour and any timezone drift: the repository only returns rows
 * whose scheduled_at (the digest hour) has passed, so a customer's summary goes
 * out within the hour of their chosen time. Registered only while the notification
 * feature is enabled.
 */
final class NotificationScheduler implements Hookable {

	/** Recurring action hook that flushes the digest queue. */
	public const HOOK = 'yzrw_notification_digest';

	/**
	 * @param Scheduler      $scheduler Action Scheduler wrapper.
	 * @param DigestService  $digest    Digest service.
	 */
	public function __construct(
		private Scheduler $scheduler,
		private DigestService $digest
	) {}

	/**
	 * @inheritDoc
	 */
	public function hooks(): array {
		return array(
			array( 'type' => 'action', 'hook' => 'init', 'method' => 'ensure_scheduled', 'priority' => 20 ),
			array( 'type' => 'action', 'hook' => self::HOOK, 'method' => 'run' ),
		);
	}

	/**
	 * Ensure the recurring digest-flush action exists (idempotent).
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		if ( $this->scheduler->is_ready() ) {
			return;
		}
		$this->scheduler->recurring( time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, self::HOOK );
	}

	/**
	 * Cron callback — flush any due digest emails.
	 *
	 * @return void
	 */
	public function run(): void {
		$this->digest->run_due();
	}
}
