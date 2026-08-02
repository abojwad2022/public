<?php
/**
 * Section-level routes.
 *
 * `/sections/reorder` is registered BEFORE the id route and the id pattern is a strict UUID, so
 * "reorder" can never be matched as a section id — the classic REST routing bug where a verb and
 * an identifier share a path segment.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Presentation\Rest;

use Yazan\Homepage\Infrastructure\Bootstrap\ServiceFactory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * /homepage/sections routes.
 */
final class SectionsController {

	/**
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			RestSupport::NAMESPACE_V1,
			'/homepage/sections/reorder',
			array(
				\Yazan_REST_Guard::args( \WP_REST_Server::CREATABLE, array( __CLASS__, 'reorder' ), 'homepage.sections.sort' ),
			)
		);

		register_rest_route(
			RestSupport::NAMESPACE_V1,
			'/homepage/sections',
			array(
				\Yazan_REST_Guard::args( \WP_REST_Server::CREATABLE, array( __CLASS__, 'create' ), 'homepage.sections.create' ),
			)
		);

		register_rest_route(
			RestSupport::NAMESPACE_V1,
			'/homepage/sections/' . RestSupport::UUID_PATTERN,
			array(
				\Yazan_REST_Guard::args( \WP_REST_Server::EDITABLE, array( __CLASS__, 'update' ), 'homepage.sections.edit' ),
				\Yazan_REST_Guard::args( \WP_REST_Server::DELETABLE, array( __CLASS__, 'delete' ), 'homepage.sections.delete' ),
			)
		);

		register_rest_route(
			RestSupport::NAMESPACE_V1,
			'/homepage/sections/' . RestSupport::UUID_PATTERN . '/duplicate',
			array(
				\Yazan_REST_Guard::args( \WP_REST_Server::CREATABLE, array( __CLASS__, 'duplicate' ), 'homepage.sections.duplicate' ),
			)
		);

		register_rest_route(
			RestSupport::NAMESPACE_V1,
			'/homepage/sections/' . RestSupport::UUID_PATTERN . '/toggle',
			array(
				\Yazan_REST_Guard::args( \WP_REST_Server::CREATABLE, array( __CLASS__, 'toggle' ), 'homepage.sections.edit' ),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create( $request ) {
		return RestSupport::run(
			static function () use ( $request ) {
				$type = (string) $request->get_param( 'type' );

				if ( '' === $type ) {
					throw new \InvalidArgumentException( 'A section type is required.' );
				}

				$position = $request->get_param( 'position' );

				return ServiceFactory::create_section()->handle(
					RestSupport::key( $request ),
					$type,
					(array) $request->get_param( 'content' ),
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
	public static function update( $request ) {
		return RestSupport::run(
			static function () use ( $request ) {
				$changes = array();

				foreach ( array( 'content', 'label', 'state', 'visibility', 'schedule' ) as $key ) {
					if ( null !== $request->get_param( $key ) ) {
						$changes[ $key ] = $request->get_param( $key );
					}
				}

				return ServiceFactory::update_section()->handle(
					RestSupport::key( $request ),
					(string) $request['id'],
					$changes,
					RestSupport::version( $request )
				);
			}
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function delete( $request ) {
		return RestSupport::run(
			static function () use ( $request ) {
				return ServiceFactory::delete_section()->handle(
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
	public static function duplicate( $request ) {
		return RestSupport::run(
			static function () use ( $request ) {
				return ServiceFactory::duplicate_section()->handle(
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
	public static function toggle( $request ) {
		return RestSupport::run(
			static function () use ( $request ) {
				return ServiceFactory::toggle_section()->handle(
					RestSupport::key( $request ),
					(string) $request['id'],
					(bool) $request->get_param( 'enabled' ),
					RestSupport::version( $request )
				);
			}
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function reorder( $request ) {
		return RestSupport::run(
			static function () use ( $request ) {
				$order = $request->get_param( 'order' );

				if ( ! is_array( $order ) || ! $order ) {
					throw new \InvalidArgumentException( 'order must list every section id.' );
				}

				return ServiceFactory::reorder_sections()->handle(
					RestSupport::key( $request ),
					array_map( 'strval', $order ),
					RestSupport::version( $request )
				);
			}
		);
	}
}
