<?php
/**
 * Plugin activation.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Core\Install;

use Yazan\Stores\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs schema then per-module activation.
 */
final class Activator {

	public static function activate(): void {
		$plugin = Plugin::instance();
		$plugin->build();
		$container = $plugin->container();

		$schema = $container->get( Schema::class );
		$schema->install( $schema->installables() );

		update_option( Migrator::VERSION_OPTION, YAZAN_STORES_VERSION, false );

		foreach ( $plugin->modules() as $module ) {
			$module->activate( $container );
		}

		do_action( 'yazan_stores/activated', $container );
	}
}
