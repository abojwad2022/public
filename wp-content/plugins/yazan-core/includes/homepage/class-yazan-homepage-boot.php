<?php
/**
 * Yazan Homepage Manager — subsystem bootstrap.
 *
 * The one file in this module written in the plugin's original style (a `Yazan_*` class, no
 * namespace). It is the bridge: yazan-core.php gains a single require + init() call, exactly like
 * Yazan_RBAC_Boot and Yazan_Dashboard_Boot, and everything behind it is the namespaced module.
 *
 * ORDERING — the one thing to get right in this file:
 *
 *   Yazan_Permission_Registry::modules() memoises its result in a static, and
 *   Yazan_RBAC_Boot::maybe_install() calls it on `init` priority 1. Our filter therefore has to be
 *   attached at plugin-load time, which is what happens here — init() is called from yazan-core.php
 *   while the plugin file is being included, long before `init` fires. Attaching it any later means
 *   the catalog is built without our permissions and NOTHING reports an error: the Role Editor
 *   simply has no Homepage section. That failure is silent, which is why this comment exists.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the Homepage Manager into the platform.
 */
class Yazan_Homepage_Boot {

	/**
	 * Load the module and register its hooks. Call once at plugin load.
	 *
	 * @return void
	 */
	public static function init() {
		require_once YAZAN_CORE_DIR . 'includes/homepage/autoload.php';

		// Built-in components register on the public hook, at an early priority.
		add_action( 'yazan_homepage_register_components', array( __CLASS__, 'register_components' ), 5 );

		// Contribute this module's permissions to the central catalog. Must be attached now — see
		// the ordering note above.
		add_filter( 'yazan_permission_modules', array( __CLASS__, 'register_permissions' ) );

		// Install after RBAC (priority 1) so the catalog sync has tables to write to.
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 2 );

		/*
		 * Cross-cutting listeners: audit and cache REACT to events, they are not called by the
		 * handlers. The hook name is written literally rather than read from
		 * WpEventDispatcher::ANY_HOOK so that touching a constant does not autoload a class on
		 * every single request, including the ones that never reach this module.
		 */
		add_action( 'yazan_homepage_event', array( __CLASS__, 'on_event' ), 10, 1 );
	}

	/**
	 * Register the built-in components.
	 *
	 * Empty in phase 0 — the module is fully wired and its permissions are grantable before a
	 * single component exists, which is exactly the milestone this phase targets.
	 *
	 * @param \Yazan\Homepage\Domain\Component\ComponentRegistry $registry Registry.
	 * @return void
	 */
	public static function register_components( $registry ) {
		$dir = YAZAN_CORE_DIR . 'includes/homepage/Presentation/Components/';

		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( (array) glob( $dir . '*Component.php' ) as $file ) {
			$class = '\\Yazan\\Homepage\\Presentation\\Components\\' . basename( $file, '.php' );

			if ( class_exists( $class ) && method_exists( $class, 'definition' ) ) {
				$registry->register( $class::definition() );
			}
		}
	}

	/**
	 * Add the `homepage` module to the permission catalog.
	 *
	 * @param array $modules Existing modules.
	 * @return array
	 */
	public static function register_permissions( $modules ) {
		if ( ! is_array( $modules ) ) {
			return $modules;
		}

		$modules['homepage'] = \Yazan\Homepage\Infrastructure\Permission\PermissionCatalog::module(
			\Yazan\Homepage\Infrastructure\Bootstrap\ComponentBootstrap::registry()
		);

		return $modules;
	}

	/**
	 * Install or upgrade.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		\Yazan\Homepage\Infrastructure\Bootstrap\Installer::maybe_install();
	}

	/**
	 * Fan a domain event out to the module's listeners.
	 *
	 * @param mixed $event Domain event.
	 * @return void
	 */
	public static function on_event( $event ) {
		$audit = \Yazan\Homepage\Infrastructure\Bootstrap\ServiceFactory::audit_recorder();
		$cache = \Yazan\Homepage\Infrastructure\Bootstrap\ServiceFactory::cache_invalidator();

		$audit( $event );
		$cache( $event );
	}

	/**
	 * Health summary for GET /status.
	 *
	 * @return array
	 */
	public static function status() {
		return \Yazan\Homepage\Infrastructure\Bootstrap\Installer::status();
	}
}
