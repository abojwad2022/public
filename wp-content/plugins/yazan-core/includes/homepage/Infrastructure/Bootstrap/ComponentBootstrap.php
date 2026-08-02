<?php
/**
 * Builds the component registry and fires the registration hook.
 *
 * Built-in components register on the SAME hook everyone else uses, at priority 5. There is no
 * privileged path — which is the only way to be sure the extension point actually works.
 *
 * `registry()` is safe to call at any time: it boots on first use. That matters because the
 * permission catalog can be asked for its contents very early, before `plugins_loaded` has run its
 * course, and an empty registry at that moment would silently produce a catalog with no
 * per-section permissions in it.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Infrastructure\Bootstrap;

use Yazan\Homepage\Domain\Component\ComponentRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Component registration.
 */
final class ComponentBootstrap {

	/** @var ComponentRegistry|null */
	private static $registry = null;

	/** @var bool */
	private static $booting = false;

	/**
	 * The registry, populated on first access.
	 *
	 * @return ComponentRegistry
	 */
	public static function registry() {
		if ( null !== self::$registry ) {
			return self::$registry;
		}

		// Re-entrancy guard: a component whose registration asks for the registry gets the partial
		// one rather than an infinite loop.
		if ( self::$booting ) {
			return new ComponentRegistry();
		}

		self::$booting  = true;
		self::$registry = new ComponentRegistry();

		/**
		 * Register homepage components.
		 *
		 * Adding a section type needs nothing but this hook — no core file changes.
		 *
		 * @param ComponentRegistry $registry The registry.
		 */
		do_action( 'yazan_homepage_register_components', self::$registry );

		self::$booting = false;

		return self::$registry;
	}

	/**
	 * Reset — tests only.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$registry = null;
		self::$booting  = false;
	}
}
