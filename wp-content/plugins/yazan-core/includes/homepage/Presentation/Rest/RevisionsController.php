<?php
/**
 * Revision history: list, read, compare, restore.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Presentation\Rest;

use Yazan\Homepage\Domain\Revision\RevisionDiff;
use Yazan\Homepage\Infrastructure\Bootstrap\ServiceFactory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * /homepage/revisions routes.
 */
final class RevisionsController {

	/**
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			RestSupport::NAMESPACE_V1,
			'/homepage/revisions',
			array(
				\Yazan_REST_Guard::args( \WP_REST_Server::READABLE, array( __CLASS__, 'index' ), 'homepage.view' ),
			)
		);

		register_rest_route(
			RestSupport::NAMESPACE_V1,
			'/homepage/revisions/(?P<id>\d+)',
			array(
				\Yazan_REST_Guard::args( \WP_REST_Server::READABLE, array( __CLASS__, 'read' ), 'homepage.view' ),
			)
		);

		register_rest_route(
			RestSupport::NAMESPACE_V1,
			'/homepage/revisions/(?P<id>\d+)/diff',
			array(
				\Yazan_REST_Guard::args( \WP_REST_Server::READABLE, array( __CLASS__, 'diff' ), 'homepage.view' ),
			)
		);

		register_rest_route(
			RestSupport::NAMESPACE_V1,
			'/homepage/revisions/(?P<id>\d+)/restore',
			array(
				\Yazan_REST_Guard::args( \WP_REST_Server::CREATABLE, array( __CLASS__, 'restore' ), 'homepage.rollback' ),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function index( $request ) {
		return RestSupport::run(
			static function () use ( $request ) {
				$rows = ServiceFactory::revisions()->listing(
					RestSupport::key( $request )->value(),
					(int) ( $request->get_param( 'per_page' ) ?: 30 ),
					(int) $request->get_param( 'offset' )
				);

				foreach ( $rows as $index => $row ) {
					$user                    = get_userdata( (int) $row['author_id'] );
					$rows[ $index ]['author'] = $user ? $user->display_name : __( 'Scheduled', 'yazan' );
				}

				return array( 'revisions' => $rows );
			}
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function read( $request ) {
		return RestSupport::run(
			static function () use ( $request ) {
				$revision = ServiceFactory::revisions()->find( (int) $request['id'] );

				if ( ! $revision ) {
					return new \WP_Error( 'yazan_homepage_no_revision', __( 'That revision does not exist.', 'yazan' ), array( 'status' => 404 ) );
				}

				unset( $revision['payload'] );

				return array( 'revision' => $revision );
			}
		);
	}

	/**
	 * What changed between a revision and the current draft (or another revision).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function diff( $request ) {
		return RestSupport::run(
			static function () use ( $request ) {
				$revision = ServiceFactory::revisions()->find( (int) $request['id'] );

				if ( ! $revision ) {
					return new \WP_Error( 'yazan_homepage_no_revision', __( 'That revision does not exist.', 'yazan' ), array( 'status' => 404 ) );
				}

				$against = $request->get_param( 'against' );

				if ( $against ) {
					$other = ServiceFactory::revisions()->find( (int) $against );
					$after = $other ? $other['sections'] : array();
				} else {
					$after = ServiceFactory::documents()->get( RestSupport::key( $request ) )->sections()->to_array();
				}

				return array( 'diff' => RevisionDiff::between( (array) $revision['sections'], (array) $after ) );
			}
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function restore( $request ) {
		return RestSupport::run(
			static function () use ( $request ) {
				return ServiceFactory::rollback()->handle(
					RestSupport::key( $request ),
					(int) $request['id'],
					RestSupport::version( $request )
				);
			}
		);
	}
}
