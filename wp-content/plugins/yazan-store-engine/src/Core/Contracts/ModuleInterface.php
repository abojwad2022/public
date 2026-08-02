<?php
/**
 * A unit of functionality with a declared lifecycle.
 *
 * The split between register() and boot() is the important part: register() may only BIND
 * services, boot() may only attach hooks. Keeping them apart is what lets the container be fully
 * assembled before any hook can fire and ask it a question.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Core\Contracts;

use Yazan\Stores\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module lifecycle: dependencies() -> register() -> boot(); activate()/deactivate() on install.
 */
interface ModuleInterface {

	/** Unique machine id, e.g. "store". */
	public function id(): string;

	/** @return string[] Ids of modules that must be present. */
	public function dependencies(): array;

	/** Bind services. NO HOOKS HERE. */
	public function register( Container $container ): void;

	/** Attach hooks and event subscribers. */
	public function boot( Container $container ): void;

	/** Plugin activation: seed data, capabilities. Must be idempotent — the migrator re-runs it. */
	public function activate( Container $container ): void;

	/** Plugin deactivation: unschedule. Never deletes data. */
	public function deactivate( Container $container ): void;
}
