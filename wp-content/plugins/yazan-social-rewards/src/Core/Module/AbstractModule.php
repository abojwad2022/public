<?php
/**
 * Base module.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Module;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Contracts\ModuleInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Convenience base for engines. Concrete modules override what they need; the
 * empty defaults let a module opt into only the lifecycle steps it uses.
 */
abstract class AbstractModule implements ModuleInterface {

	/**
	 * @inheritDoc
	 */
	public function dependencies(): array {
		return array();
	}

	/**
	 * @inheritDoc
	 */
	public function register( Container $container ): void {}

	/**
	 * @inheritDoc
	 */
	public function boot( Container $container ): void {}

	/**
	 * @inheritDoc
	 */
	public function activate( Container $container ): void {}

	/**
	 * @inheritDoc
	 */
	public function deactivate( Container $container ): void {}
}
