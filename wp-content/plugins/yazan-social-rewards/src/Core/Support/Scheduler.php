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
	 * Every recurring hook this plugin owns, as DATA.
	 *
	 * A list rather than eight scattered constants, because per-store cancellation and the health
	 * check both need to enumerate them. A hook that is not here cannot be cancelled when a store is
	 * archived — and that failure is silent.
	 *
	 * @var string[]
	 */
	public const HOOKS = array(
		'yazan_rewards/points/expire_due',
		'yazan_rewards/points/expiry_reminder',
		'yazan_rewards/rules/birthday_scan',
		'yazan_rewards/campaign/tick',
		'yzrw_notification_campaign_ending',
		'yzrw_notification_digest',
	);

	/**
	 * The store every job scheduled through this instance belongs to.
	 *
	 * @return int
	 */
	public function store_id(): int {
		return \class_exists( 'Yazan_Store_Context' ) ? \Yazan_Store_Context::current() : 1;
	}

	/**
	 * Put the store at the front of a job's arguments.
	 *
	 * ⚠️ THIS IS WHAT MAKES THE QUEUE MULTI-TENANT, and it is one line because Action Scheduler
	 * already does the hard part: `as_has_scheduled_action()` matches on ARGS, so N stores produce N
	 * distinct recurring actions with no deduplication logic to write and no per-store bookkeeping.
	 *
	 * Position 0 by convention so `Scheduler::handler()` can pop it without knowing the hook.
	 *
	 * @param array $args Caller args.
	 * @return array
	 */
	private function with_store( array $args ): array {
		array_unshift( $args, $this->store_id() );

		return $args;
	}

	/**
	 * Wrap a job handler so it runs inside its own store.
	 *
	 * ⚠️ WITHOUT THIS EVERY RECURRING JOB RUNS AS STORE 1, FOREVER.
	 *
	 * A scheduled request has no host, so `Yazan_Store_Context` resolves through its cron rung and
	 * returns the default store. Points never expire in store 2, its tiers never recalculate, its
	 * notification queue never drains — and every one of those is a job that completed successfully.
	 *
	 * A job that arrives with no store is REFUSED rather than guessed, which is what the kernel's
	 * own comment asks for. Refusing marks the action failed, which is visible in the queue; guessing
	 * writes another store's data and is not.
	 *
	 * @param callable $fn fn( ...$args ): void
	 * @return callable
	 */
	public static function handler( callable $fn ): callable {
		return static function ( ...$args ) use ( $fn ) {
			$store = isset( $args[0] ) ? (int) $args[0] : 0;

			if ( $store < 1 ) {
				if ( \class_exists( 'Yazan_Log' ) ) {
					\Yazan_Log::error( 'queue.no_store', array( 'args' => count( $args ) ) );
				}

				return;
			}

			array_shift( $args );

			if ( ! \class_exists( 'Yazan_Store_Context' ) ) {
				$fn( ...$args );

				return;
			}

			\Yazan_Store_Context::run_for( $store, static fn() => $fn( ...$args ) );
		};
	}

	/**
	 * Cancel every recurring job belonging to one store.
	 *
	 * Exact, because Action Scheduler matches on args — so archiving one store cannot touch another's
	 * queue.
	 *
	 * @param int $store_id Store.
	 * @return void
	 */
	public function unschedule_store( int $store_id ): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		foreach ( self::HOOKS as $hook ) {
			as_unschedule_all_actions( $hook, array( $store_id ), $this->group );
		}
	}

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
		return (int) as_enqueue_async_action( $hook, $this->with_store( $args ), $this->group );
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
		return (int) as_schedule_single_action( $timestamp, $hook, $this->with_store( $args ), $this->group );
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
		$args = $this->with_store( $args );

		// Args-matched, so this dedupes PER STORE: store 2 getting its own copy of a job store 1
		// already has is exactly the intended outcome, not a duplicate.
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
