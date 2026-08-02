<?php
/**
 * Default no-op implementations so a module only overrides what it uses.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Core\Module;

use Yazan\Stores\Core\Container;
use Yazan\Stores\Core\Contracts\ModuleInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base module. `id()` stays abstract — it is the one thing every module must state.
 */
abstract class AbstractModule implements ModuleInterface {

	public function dependencies(): array {
		return array();
	}

	public function register( Container $container ): void {}

	public function boot( Container $container ): void {}

	public function activate( Container $container ): void {}

	public function deactivate( Container $container ): void {}
}
