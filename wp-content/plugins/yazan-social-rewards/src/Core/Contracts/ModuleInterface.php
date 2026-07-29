<?php
/**
 * Module contract.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Contracts;

use Yazan\Rewards\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every engine is a Module. The registry drives each through this lifecycle:
 *   dependencies() → (checked) → register() → boot()
 * and, on plugin activation/deactivation, activate()/deactivate().
 *
 * A module MUST NOT add hooks in register() (services only) — hooks belong in
 * boot(), so that all services are bound before any hook can fire.
 */
interface ModuleInterface {

	/**
	 * Unique machine id for this module (e.g. "points", "ambassador").
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Ids of other modules that must be present and booted first.
	 *
	 * @return string[]
	 */
	public function dependencies(): array;

	/**
	 * Bind this module's services into the container. No hooks here.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ): void;

	/**
	 * Register hooks / event subscribers. Runs after every module registered.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function boot( Container $container ): void;

	/**
	 * Called on plugin activation (schema for this module, seed data, caps).
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function activate( Container $container ): void;

	/**
	 * Called on plugin deactivation (unschedule jobs, etc.). Never deletes data.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function deactivate( Container $container ): void;
}
