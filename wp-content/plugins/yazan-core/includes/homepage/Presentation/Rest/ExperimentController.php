<?php
/**
 * /homepage/experiment — the A/B test.
 *
 * Reading the numbers needs only `homepage.view`; changing anything needs `homepage.experiment`,
 * and promoting a winner needs `homepage.publish` on top of it. Somebody who may look at how the
 * shop is doing is not automatically somebody who may change what half the visitors see.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Presentation\Rest;

use Yazan\Homepage\Infrastructure\Bootstrap\ServiceFactory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Experiment routes.
 */
final class ExperimentController {

	/**
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			RestSupport::NAMESPACE_V1,
			'/homepage/experiment',
			array(
				\Yazan_REST_Guard::args( \WP_REST_Server::READABLE, array( __CLASS__, 'read' ), 'homepage.view' ),
				\Yazan_REST_Guard::args( \WP_REST_Server::EDITABLE, array( __CLASS__, 'save' ), 'homepage.experiment' ),
				\Yazan_REST_Guard::args( \WP_REST_Server::DELETABLE, array( __CLASS__, 'remove' ), 'homepage.experiment' ),
			)
		);

		register_rest_route(
			RestSupport::NAMESPACE_V1,
			'/homepage/experiment/start',
			array(
				\Yazan_REST_Guard::args( \WP_REST_Server::CREATABLE, array( __CLASS__, 'start' ), 'homepage.experiment' ),
			)
		);

		register_rest_route(
			RestSupport::NAMESPACE_V1,
			'/homepage/experiment/stop',
			array(
				\Yazan_REST_Guard::args( \WP_REST_Server::CREATABLE, array( __CLASS__, 'stop' ), 'homepage.experiment' ),
			)
		);

		register_rest_route(
			RestSupport::NAMESPACE_V1,
			'/homepage/experiment/promote',
			array(
				\Yazan_REST_Guard::args( \WP_REST_Server::CREATABLE, array( __CLASS__, 'promote' ), 'homepage.publish' ),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function read( $request ) {
		return RestSupport::run(
			static function () use ( $request ) {
				return ServiceFactory::experiment_handler()->read( RestSupport::key( $request ) );
			}
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function save( $request ) {
		return RestSupport::run(
			static function () use ( $request ) {
				return ServiceFactory::experiment_handler()->save(
					RestSupport::key( $request ),
					(string) $request->get_param( 'variant' ),
					(int) $request->get_param( 'split' )
				);
			}
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function start( $request ) {
		return RestSupport::run(
			static function () use ( $request ) {
				return ServiceFactory::experiment_handler()->start( RestSupport::key( $request ) );
			}
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function stop( $request ) {
		return RestSupport::run(
			static function () use ( $request ) {
				return ServiceFactory::experiment_handler()->stop( RestSupport::key( $request ) );
			}
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function remove( $request ) {
		return RestSupport::run(
			static function () use ( $request ) {
				return ServiceFactory::experiment_handler()->remove( RestSupport::key( $request ) );
			}
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function promote( $request ) {
		return RestSupport::run(
			static function () use ( $request ) {
				return ServiceFactory::experiment_handler()->promote(
					RestSupport::key( $request ),
					ServiceFactory::publish()
				);
			}
		);
	}
}
