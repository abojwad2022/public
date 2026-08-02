<?php
/**
 * Registers the engine's permissions into yazan-core's catalogue.
 *
 * ⚠️ THE FILTER MUST BE ATTACHED AT PLUGIN-LOAD TIME. Yazan_Permission_Registry::modules()
 * memoises into a static and Yazan_RBAC_Boot reads it on `init` priority 1; a filter attached any
 * later is never seen, and the failure is SILENT — the Role Editor simply has no Stores section
 * and every stores.* permission resolves false for everyone but super admins. The attachment lives
 * in the plugin's main file for exactly that reason, not in a module boot().
 *
 * This is also why the engine touches no yazan-core file: the catalogue is designed to be extended
 * from outside, and this is the supported way in.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Application;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The `stores` permission module.
 */
final class StorePermissions {

	public const VIEW      = 'stores.view';
	public const CREATE    = 'stores.create';
	public const EDIT      = 'stores.edit';
	public const DELETE    = 'stores.delete';
	public const DOMAINS   = 'stores.domains';
	public const SETTINGS  = 'stores.settings';
	public const MODULES   = 'stores.modules';

	/**
	 * Add the `stores` module to the permission catalogue.
	 *
	 * Sort 450 places it between `pages` (430) and `users` (500) — a deliberately empty band in the
	 * existing catalogue, so nothing renumbers.
	 *
	 * @param array<string,mixed> $modules Existing catalogue.
	 * @return array<string,mixed>
	 */
	public static function register( $modules ) {
		if ( ! is_array( $modules ) ) {
			return $modules;
		}

		$modules['stores'] = array(
			'label'   => __( 'Stores', 'yazan-stores' ),
			'sort'    => 450,
			'actions' => array(
				'view'     => __( 'See the store list and each store\'s configuration.', 'yazan-stores' ),
				'create'   => __( 'Create a new store.', 'yazan-stores' ),
				'edit'     => __( 'Rename a store, change its theme, currency or status.', 'yazan-stores' ),
				'delete'   => __( 'Archive a store.', 'yazan-stores' ),
				'domains'  => __( 'Add or remove the addresses a store answers on. Changes what the public can reach.', 'yazan-stores' ),
				'settings' => __( 'Change a store\'s settings.', 'yazan-stores' ),
				'modules'  => __( 'Turn features on and off for a store.', 'yazan-stores' ),
			),
		);

		return $modules;
	}
}
