<?php
/**
 * REST controller registrar.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Rest;

use Yazan\Rewards\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects REST controllers contributed by modules and registers their routes on
 * `rest_api_init`. Modules push their controller instances via add(); the core
 * calls register() once.
 */
final class RestBootstrap {

	/**
	 * Controller instances or lazy factories, resolved at register() time.
	 *
	 * @var array<int,AbstractController|callable>
	 */
	private array $controllers = array();

	/**
	 * @param Container $container Service container.
	 */
	public function __construct( private Container $container ) {}

	/**
	 * Add a controller, or a factory fn(Container): AbstractController. A factory
	 * is preferred when the controller depends on services bound by other modules,
	 * because it is resolved on rest_api_init — after every module has registered.
	 *
	 * @param AbstractController|callable $controller Controller or factory.
	 * @return void
	 */
	public function add( $controller ): void {
		$this->controllers[] = $controller;
	}

	/**
	 * Register every controller's routes. Hook: rest_api_init.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( $this->controllers as $controller ) {
			if ( is_callable( $controller ) ) {
				$controller = $controller( $this->container );
			}
			if ( $controller instanceof AbstractController ) {
				$controller->register_routes();
			}
		}

		/**
		 * Fires after core controllers register, for add-on routes.
		 *
		 * @param RestBootstrap $bootstrap The registrar.
		 */
		do_action( 'yazan_rewards/rest/register', $this );
	}
}
