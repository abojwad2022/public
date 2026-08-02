<?php
/**
 * Plugin Name: Yazan — Store Engine
 * Description: The store registry: entity, repository, service, context, resolver, middleware and provider. Ships with one store and changes no behaviour.
 * Version:     1.1.0
 * Author:      Yazan
 * Requires PHP: 8.1
 * Text Domain: yazan-stores
 *
 * WHY A SEPARATE PLUGIN
 * ---------------------
 * A store is a more fundamental concept than loyalty or payments, so it must not depend on either.
 * This plugin therefore MIRRORS the container/module/event framework proven in yazan-social-rewards
 * rather than importing it — same patterns, no dependency. It also touches nothing in yazan-core:
 * permissions arrive through the `yazan_permission_modules` filter, REST lives in its own
 * namespace, and the canonical-host guard is adjusted through its own published filter.
 *
 * RELATIONSHIP TO Yazan_Store_Context (the mu-plugin)
 * --------------------------------------------------
 * The mu-plugin stays the kernel. It answers "which store is this request" before any plugin
 * loads — it has to, because it runs ahead of `determine_current_user` and of the first capability
 * check — and it does so by reading one autoloaded option, `yazan_store_hostmap`, as a plain array
 * lookup. It cannot query the database: it executes inside the `user_has_cap` path, where any
 * capability call re-enters the filter and recurses until the process dies.
 *
 * This plugin is the SOURCE OF TRUTH that option is projected from. Registry lives here; resolution
 * stays there. Two concepts of "current store" would be one too many.
 *
 * @package Yazan\Stores
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'YAZAN_STORES_VERSION', '1.1.0' );
define( 'YAZAN_STORES_FILE', __FILE__ );
define( 'YAZAN_STORES_DIR', plugin_dir_path( __FILE__ ) );
define( 'YAZAN_STORES_URL', plugin_dir_url( __FILE__ ) );
define( 'YAZAN_STORES_BASENAME', plugin_basename( __FILE__ ) );

/*
 * PSR-4 for `Yazan\Stores\` → src/. Hand-rolled rather than Composer: this plugin has no third-party
 * dependencies, and the sibling plugins already establish that a vendor/ directory is not required
 * to ship here.
 */
spl_autoload_register(
	static function ( $class ) {
		$prefix = 'Yazan\\Stores\\';
		$len    = strlen( $prefix );

		if ( 0 !== strncmp( $prefix, $class, $len ) ) {
			return;
		}

		$relative = substr( $class, $len );
		$path     = YAZAN_STORES_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

/*
 * PERMISSIONS MUST BE FILTERED IN AT PLUGIN-LOAD TIME, NOT ON `init`.
 *
 * Yazan_Permission_Registry::modules() memoises into a static, and Yazan_RBAC_Boot reads it on
 * `init` priority 1. A filter attached any later is never seen — and the failure is SILENT: the
 * Role Editor simply has no Stores section and every stores.* permission resolves to false for
 * everyone except super admins. yazan-core's own homepage module carries the same warning.
 */
add_filter( 'yazan_permission_modules', array( \Yazan\Stores\Application\StorePermissions::class, 'register' ) );

register_activation_hook(
	YAZAN_STORES_FILE,
	static function () {
		\Yazan\Stores\Core\Install\Activator::activate();
	}
);

register_deactivation_hook(
	YAZAN_STORES_FILE,
	static function () {
		\Yazan\Stores\Core\Install\Deactivator::deactivate();
	}
);

/*
 * Priority 5 — ahead of yazan-social-rewards (20) and yazan-core's boot. Anything that asks the
 * registry a question should find it already assembled, and the hostmap projection must be able to
 * run before another plugin caches a store-derived value.
 *
 * Still AFTER Yazan_Store_Context::freeze(), which runs on `plugins_loaded` priority 0. Resolution
 * is settled before the registry loads; the registry only supplies the data resolution reads.
 */
add_action(
	'plugins_loaded',
	static function () {
		\Yazan\Stores\Core\Plugin::instance()->boot();
	},
	5
);
