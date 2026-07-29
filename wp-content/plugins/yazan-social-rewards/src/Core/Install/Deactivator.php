<?php
/**
 * Deactivation routine.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Install;

use Yazan\Rewards\Core\Plugin;
use Yazan\Rewards\Core\Support\Scheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs on deactivation. Unschedules background jobs and flushes rewrite rules.
 * NEVER deletes data — the ledgers are a financial record; teardown is opt-in at
 * uninstall only.
 */
final class Deactivator {

	/**
	 * @return void
	 */
	public static function deactivate(): void {
		$plugin = Plugin::instance();
		$plugin->build();
		$container = $plugin->container();

		// Cancel all scheduled Action Scheduler jobs in our group + clear the
		// cron-ready flag so a later reactivation re-schedules them.
		if ( $container->has( Scheduler::class ) ) {
			$scheduler = $container->get( Scheduler::class );
			$scheduler->unschedule_all();
			$scheduler->clear_ready();
		}

		// Per-module deactivation.
		foreach ( $plugin->modules() as $module ) {
			$module->deactivate( $container );
		}

		flush_rewrite_rules();

		/**
		 * Fires at the end of deactivation.
		 */
		do_action( 'yazan_rewards/deactivated' );
	}
}
