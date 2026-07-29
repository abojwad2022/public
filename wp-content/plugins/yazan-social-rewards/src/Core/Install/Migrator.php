<?php
/**
 * Version-gated migrator.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Install;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Plugin;
use Yazan\Rewards\Core\Support\Scheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs schema upgrades when the plugin version changes, on `admin_init`, without
 * needing a re-activation (mirrors yazan-core's admin_init install gate). Keeps
 * the DB in step with code after a silent plugin update.
 */
final class Migrator {

	/** Option storing the last-migrated plugin version. */
	public const VERSION_OPTION = 'yazan_rewards_version';

	/**
	 * @param Container $container Service container.
	 */
	public function __construct( private Container $container ) {}

	/**
	 * Run pending migrations if the code version moved ahead. Hook: admin_init.
	 *
	 * Mirrors {@see Activator::activate()} for the silent-update path: after the
	 * schema step it re-runs each module's activate() (idempotent seeds / field
	 * back-fills / endpoint registration) and flushes rewrite rules once, so a
	 * file-only update — no manual re-activation — still registers new account
	 * endpoints and back-fills new columns (e.g. the 1.5.0 tier badge/colour/
	 * discount fields). Every module activate() is written to be safe to re-run.
	 *
	 * @return void
	 */
	public function maybe_migrate(): void {
		$installed = get_option( self::VERSION_OPTION );
		if ( YAZAN_REWARDS_VERSION === $installed ) {
			return;
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			return; // Retry on the next admin load once WooCommerce is up.
		}

		// 1. Schema (dbDelta — additive column/table changes).
		$schema = $this->container->get( Schema::class );
		$schema->install( $schema->installable_modules() );

		// 2. Per-module activation: idempotent seeds, field back-fills, and
		//    endpoint registration that the version-gated dbDelta alone misses.
		foreach ( Plugin::instance()->modules() as $module ) {
			$module->activate( $this->container );
		}

		// 3. Persist rewrite rules so any newly-added My Account endpoint resolves
		//    without a manual permalink re-save.
		flush_rewrite_rules();

		// 4. Force the scheduling pass to re-run (a new version may add recurring jobs).
		if ( $this->container->has( Scheduler::class ) ) {
			$this->container->get( Scheduler::class )->clear_ready();
		}

		update_option( self::VERSION_OPTION, YAZAN_REWARDS_VERSION, false );

		/**
		 * Fires once after a silent in-place upgrade has migrated.
		 *
		 * @param string    $version   The version migrated to.
		 * @param Container  $container Service container.
		 */
		do_action( 'yazan_rewards/migrated', YAZAN_REWARDS_VERSION, $this->container );
	}
}
