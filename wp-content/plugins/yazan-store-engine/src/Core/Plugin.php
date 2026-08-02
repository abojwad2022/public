<?php
/**
 * The engine's single entry point.
 *
 * Thin by design: assemble the container, load modules, drive them through register() then boot().
 * Every decision of consequence lives in a module or a service.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Core;

use Yazan\Stores\Core\Contracts\ModuleInterface;
use Yazan\Stores\Core\Install\Migrator;
use Yazan\Stores\Core\Install\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrator.
 */
final class Plugin {

	/** Own REST namespace — see the note in boot(). */
	public const REST_NS = 'yazan-stores/v1';

	private static ?Plugin $instance = null;

	private Container $container;

	private ModuleRegistry $registry;

	private bool $built = false;

	private bool $booted = false;

	private function __construct() {
		$this->container = new Container();
		$this->registry  = new ModuleRegistry();
	}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function container(): Container {
		return $this->container;
	}

	/**
	 * @return ModuleInterface[]
	 */
	public function modules(): array {
		return $this->registry->all();
	}

	/**
	 * Assemble the container and register modules. Attaches NO hooks.
	 *
	 * Idempotent, because the activation hook and the normal boot path both need a built container
	 * and only one of them runs first.
	 *
	 * @return void
	 */
	public function build(): void {
		if ( $this->built ) {
			return;
		}

		$this->built = true;

		$this->container->instance( self::class, $this );
		$this->container->singleton( Schema::class, fn( Container $c ): Schema => new Schema( $c ) );
		$this->container->singleton( Migrator::class, fn( Container $c ): Migrator => new Migrator( $c ) );

		$this->load_modules();

		// register() may only BIND. Keeping hooks out until boot() is what guarantees the container
		// is complete before anything can fire and ask it for a service.
		foreach ( $this->registry->all() as $module ) {
			$module->register( $this->container );
		}
	}

	/**
	 * Attach hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$this->build();

		/*
		 * `init` priority 1: early enough that the additive DDL lands before any front-end, REST,
		 * webhook or cron write path runs. Deliberately not `admin_init`, which never fires for
		 * three of those four.
		 */
		add_action(
			'init',
			function () {
				$this->container->get( Migrator::class )->maybe_migrate();
			},
			1
		);

		foreach ( $this->registry->all() as $module ) {
			$module->boot( $this->container );
		}

		/**
		 * The engine is up and the container is complete.
		 *
		 * @param Container $container Service container.
		 */
		do_action( 'yazan_stores/booted', $this->container );
	}

	/**
	 * Instantiate module classes from config, then let anyone add more.
	 *
	 * @return void
	 */
	private function load_modules(): void {
		$classes = (array) require YAZAN_STORES_DIR . 'config/modules.php';

		/**
		 * Filter the module class list.
		 *
		 * The extension point for adding a module without editing this plugin.
		 *
		 * @param string[] $classes Fully-qualified module class names.
		 */
		$classes = (array) apply_filters( 'yazan_stores/modules', $classes );

		foreach ( $classes as $class ) {
			if ( ! is_string( $class ) || ! class_exists( $class ) ) {
				continue;
			}

			$module = new $class();

			if ( $module instanceof ModuleInterface ) {
				$this->registry->add( $module );
			}
		}
	}
}
