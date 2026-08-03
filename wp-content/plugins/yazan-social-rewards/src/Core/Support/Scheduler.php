<?php
/**
 * Action Scheduler wrapper.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin adapter over WooCommerce's Action Scheduler (always present on this
 * store). Used for point expiry, tier recalcs, commission batches, the
 * notification queue and analytics rollups. Falls back to no-ops if Action
 * Scheduler is somehow unavailable, so callers never fatal.
 */
final class Scheduler {

	/**
	 * Autoloaded flag set once the recurring jobs have been scheduled this install,
	 * so the per-request `init` scheduling pass can skip the Action Scheduler lookups.
	 * Cleared on settings change / migration / deactivation so jobs re-evaluate.
	 */
	private const READY_OPTION = 'yazan_rewards_cron_ready';

	/**
	 * @param string $group Action group namespace for this plugin's jobs.
	 */
	public function __construct( private string $group = 'yazan_rewards' ) {}

	/**
	 * Whether the one-time recurring-job scheduling pass has already run.
	 *
	 * @return bool
	 */
	public function is_ready(): bool {
		/*
		 * ⚠️ PER STORE. This was a plain `get_option()`, so the FIRST store to finish scheduling
		 * marked the whole platform ready — and every store after it skipped scheduling entirely.
		 * Its jobs would simply never exist, with nothing anywhere reporting a problem.
		 */
		if ( \class_exists( 'Yazan_Store_Options' ) ) {
			return '1' === (string) \Yazan_Store_Options::get( self::READY_OPTION, '' );
		}

		return '1' === get_option( self::READY_OPTION );
	}

	/**
	 * Mark scheduling done (idempotent — writes at most once).
	 *
	 * @return void
	 */
	public function mark_ready(): void {
		if ( \class_exists( 'Yazan_Store_Options' ) ) {
			\Yazan_Store_Options::set( self::READY_OPTION, '1' );

			return;
		}

		if ( ! $this->is_ready() ) {
			update_option( self::READY_OPTION, '1', true );
		}
	}

	/**
	 * Force the scheduling pass to re-run on the next request (after a settings
	 * change, migration, or deactivation).
	 *
	 * @return void
	 */
	public function clear_ready(): void {
		if ( \class_exists( 'Yazan_Store_Options' ) ) {
			\Yazan_Store_Options::set( self::READY_OPTION, '' );

			return;
		}

		delete_option( self::READY_OPTION );
	}

	/**
	 * Whether Action Scheduler is available.
	 *
	 * @return bool
	 */
	public function available(): bool {
		return function_exists( 'as_enqueue_async_action' );
	}

	/**
	 * Run a hook as soon as possible, asynchronously.
	 *
	 * @param string $hook Hook name.
	 * @param array  $args Positional args passed to the hook.
	 * @return int Action id (0 when unavailable).
	 */
	public function async( string $hook, array $args = array() ): int {
		if ( ! $this->available() ) {
			return 0;
		}
		return (int) as_enqueue_async_action( $hook, $args, $this->group );
	}

	/**
	 * Schedule a one-off action at a timestamp.
	 *
	 * @param int    $timestamp Unix time.
	 * @param string $hook      Hook name.
	 * @param array  $args      Args.
	 * @return int Action id (0 when unavailable).
	 */
	public function single( int $timestamp, string $hook, array $args = array() ): int {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return 0;
		}
		return (int) as_schedule_single_action( $timestamp, $hook, $args, $this->group );
	}

	/**
	 * Ensure a recurring action exists (idempotent — won't duplicate).
	 *
	 * @param int    $timestamp First run.
	 * @param int    $interval  Seconds between runs.
	 * @param string $hook      Hook name.
	 * @param array  $args      Args.
	 * @return int Action id (0 when unavailable/existing).
	 */
	public function recurring( int $timestamp, int $interval, string $hook, array $args = array() ): int {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return 0;
		}
		if ( as_has_scheduled_action( $hook, $args, $this->group ) ) {
			return 0;
		}
		return (int) as_schedule_recurring_action( $timestamp, $interval, $hook, $args, $this->group );
	}

	/**
	 * Cancel all scheduled actions in this plugin's group (deactivation).
	 *
	 * @return void
	 */
	public function unschedule_all(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), $this->group );
		}
	}
}
