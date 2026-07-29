<?php
/**
 * Base REST controller.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Rest;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Foundation for every yazan-rewards/v1 controller: shared namespace, the Auth
 * helper, and consistent response/error shaping. Concrete controllers implement
 * register_routes().
 */
abstract class AbstractController {

	/** Shared REST namespace. */
	protected string $namespace = Plugin::REST_NS;

	protected Auth $auth;

	/**
	 * @param Container $container Service container.
	 */
	public function __construct( protected Container $container ) {
		$this->auth = $container->get( Auth::class );
	}

	/**
	 * The controller's route base (e.g. "me", "rewards").
	 *
	 * @return string
	 */
	abstract protected function base(): string;

	/**
	 * Register this controller's routes. Hook: rest_api_init.
	 *
	 * @return void
	 */
	abstract public function register_routes(): void;

	/**
	 * Success response.
	 *
	 * @param mixed $data   Payload.
	 * @param int   $status HTTP status.
	 * @return \WP_REST_Response
	 */
	protected function ok( $data, int $status = 200 ): \WP_REST_Response {
		return new \WP_REST_Response( $data, $status );
	}

	/**
	 * Error response.
	 *
	 * @param string $code    Machine code.
	 * @param string $message Human message.
	 * @param int    $status  HTTP status.
	 * @return \WP_Error
	 */
	protected function error( string $code, string $message, int $status = 400 ): \WP_Error {
		return new \WP_Error( 'yazan_rewards_' . $code, $message, array( 'status' => $status ) );
	}
}
