<?php
/**
 * Daily birthday trigger.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Rules;

use Yazan\Rewards\Core\Contracts\Hookable;
use Yazan\Rewards\Core\Support\Scheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A daily Action Scheduler job that fires `yazan_rewards/trigger/birthday` for
 * every user whose stored birthday (`_yzrw_birthday`, format `MM-DD` or `Y-m-d`)
 * matches today. Off until a birthday field exists on the store; the job is a
 * no-op when no users carry the meta.
 */
final class BirthdayScheduler implements Hookable {

	/** Recurring job hook. */
	public const HOOK = 'yazan_rewards/rules/birthday_scan';

	/** User-meta key holding the birthday. */
	public const META_KEY = '_yzrw_birthday';

	/**
	 * @param Scheduler $scheduler Scheduler.
	 */
	public function __construct( private Scheduler $scheduler ) {}

	/**
	 * @inheritDoc
	 */
	public function hooks(): array {
		return array(
			// Scheduled on `init`: Action Scheduler's store isn't ready at plugins_loaded/boot,
			// so a recurring action queued there never persists.
			array( 'type' => 'action', 'hook' => 'init', 'method' => 'ensure_scheduled', 'priority' => 20 ),
			array( 'type' => 'action', 'hook' => self::HOOK, 'method' => 'run' ),
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
	 * Fire the birthday trigger for matching users.
	 *
	 * @return void
	 */
	public function run(): void {
		$md = gmdate( 'm-d' );

		$user_ids = get_users(
			array(
				'meta_key'     => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare' => 'EXISTS',
				'fields'       => 'ID',
				'number'       => 5000,
			)
		);

		foreach ( (array) $user_ids as $uid ) {
			$raw = (string) get_user_meta( (int) $uid, self::META_KEY, true );
			if ( '' === $raw ) {
				continue;
			}
			// Accept "MM-DD" or a full date; compare month-day only.
			$user_md = ( 5 === strlen( $raw ) ) ? $raw : gmdate( 'm-d', (int) strtotime( $raw ) );
			if ( $user_md === $md ) {
				do_action( 'yazan_rewards/trigger/birthday', (int) $uid, array() );
			}
		}
	}
}
