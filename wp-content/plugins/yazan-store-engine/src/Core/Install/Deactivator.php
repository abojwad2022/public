<?php
/**
 * Plugin deactivation. Never deletes data.
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
 * Unwinds runtime state only.
 */
final class Deactivator {

	public static function deactivate(): void {
		$plugin = Plugin::instance();
		$plugin->build();
		$container = $plugin->container();

		foreach ( $plugin->modules() as $module ) {
			$module->deactivate( $container );
		}

		/*
		 * The hostmap is left in place on purpose. Deleting it would make the tenancy kernel fall
		 * back to synthesising a single-store map — which is the correct behaviour when the engine
		 * is gone, and is exactly what it does when the option is absent. But deactivation is
		 * routinely temporary, and a store that becomes unreachable on a deactivate/reactivate
		 * cycle is a worse failure than a map that is briefly ahead of the plugin.
		 */

		do_action( 'yazan_stores/deactivated' );
	}
}
