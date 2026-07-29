<?php
/**
 * Activation routine.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Install;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Plugin;
use Yazan\Rewards\Core\Security\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs on plugin activation: build the container (services + modules), install
 * schema, grant capabilities, let each module seed defaults, then flush rewrite
 * rules so any account endpoints resolve immediately.
 */
final class Activator {

	/**
	 * @return void
	 */
	public static function activate(): void {
		$plugin = Plugin::instance();
		$plugin->build();
		$container = $plugin->container();

		// 1. Schema (aggregated from Installable modules).
		$schema = $container->get( Schema::class );
		$schema->install( $schema->installable_modules() );
		update_option( Migrator::VERSION_OPTION, YAZAN_REWARDS_VERSION, false );

		// 2. Capabilities.
		$container->get( Capabilities::class )->grant();

		// 3. Per-module activation (seed data, module-specific setup).
		foreach ( $plugin->modules() as $module ) {
			$module->activate( $container );
		}

		// 4. Rewrite rules (My Account "Rewards" endpoint added by a later module).
		flush_rewrite_rules();

		/**
		 * Fires at the end of activation.
		 *
		 * @param Container $container Service container.
		 */
		do_action( 'yazan_rewards/activated', $container );
	}
}
