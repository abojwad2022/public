<?php
/**
 * The template library REST surface.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Presentation\Rest;

use Yazan\Homepage\Infrastructure\Bootstrap\ServiceFactory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * /homepage/templates routes.
 */
final class TemplatesController {

	/**
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			RestSupport::NAMESPACE_V1,
			'/homepage/templates',
			array(
				\Yazan_REST_Guard::args( \WP_REST_Server::READABLE, array( __CLASS__, 'index' ), 'homepage.templates.view' ),
				\Yazan_REST_Guard::args( \WP_REST_Server::CREATABLE, array( __CLASS__, 'create' ), 'homepage.templates.create' ),
			)
		);

		register_rest_route(
			RestSupport::NAMESPACE_V1,
			'/homepage/templates/(?P<id>\d+)/apply',
			array(
				\Yazan_REST_Guard::args( \WP_REST_Server::CREATABLE, array( __CLASS__, 'apply' ), 'homepage.sections.create' ),
			)
		);

		register_rest_route(
			RestSupport::NAMESPACE_V1,
			'/homepage/sections/' . RestSupport::UUID_PATTERN . '/share',
			array(
				\Yazan_REST_Guard::args( \WP_REST_Server::CREATABLE, array( __CLASS__, 'share' ), 'homepage.templates.create' ),
			)
		);

		register_rest_route(
			RestSupport::NAMESPACE_V1,
			'/homepage/sections/' . RestSupport::UUID_PATTERN . '/detach',
			array(
				\Yazan_REST_Guard::args( \WP_REST_Server::CREATABLE, array( __CLASS__, 'detach' ), 'homepage.sections.edit' ),
			)
		);

		register_rest_route(
			RestSupport::NAMESPACE_V1,
			'/homepage/templates/(?P<id>\d+)',
			array(
				\Yazan_REST_Guard::args( \WP_REST_Server::DELETABLE, array( __CLASS__, 'remove' ), 'homepage.templates.delete' ),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function index( $request ) {
		return RestSupport::run(
			static function () {
				return ServiceFactory::templates()->listing();
			}
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create( $request ) {
		return RestSupport::run(
			static function () use ( $request ) {
				return ServiceFactory::templates()->save(
					RestSupport::key( $request ),
					(string) $request->get_param( 'name' ),
					$request->get_param( 'section' )
				);
			}
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function apply( $request ) {
		return RestSupport::run(
			static function () use ( $request ) {
				$position = $request->get_param( 'position' );

				return ServiceFactory::templates()->apply(
					RestSupport::key( $request ),
					(int) $request['id'],
					null === $position ? null : (int) $position,
					RestSupport::version( $request )
				);
			}
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function share( $request ) {
		return RestSupport::run(
			static function () use ( $request ) {
				return ServiceFactory::templates()->make_global(
					RestSupport::key( $request ),
					(string) $request['id'],
					(string) $request->get_param( 'name' ),
					RestSupport::version( $request )
				);
			}
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function detach( $request ) {
		return RestSupport::run(
			static function () use ( $request ) {
				return ServiceFactory::templates()->detach(
					RestSupport::key( $request ),
					(string) $request['id'],
					RestSupport::version( $request )
				);
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
				return ServiceFactory::templates()->remove( (int) $request['id'] );
			}
		);
	}
}
