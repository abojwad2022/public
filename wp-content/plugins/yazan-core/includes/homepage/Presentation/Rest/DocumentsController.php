<?php
/**
 * Landing-page documents.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Presentation\Rest;

use Yazan\Homepage\Infrastructure\Bootstrap\ServiceFactory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * /homepage/documents routes.
 */
final class DocumentsController {

	/**
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			RestSupport::NAMESPACE_V1,
			'/homepage/documents',
			array(
				\Yazan_REST_Guard::args( \WP_REST_Server::READABLE, array( __CLASS__, 'index' ), 'homepage.view' ),
				\Yazan_REST_Guard::args( \WP_REST_Server::CREATABLE, array( __CLASS__, 'create' ), 'homepage.settings' ),
			)
		);

		register_rest_route(
			RestSupport::NAMESPACE_V1,
			'/homepage/pages',
			array(
				\Yazan_REST_Guard::args( \WP_REST_Server::READABLE, array( __CLASS__, 'pages' ), 'homepage.settings' ),
			)
		);

		register_rest_route(
			RestSupport::NAMESPACE_V1,
			'/homepage/documents/(?P<key>[a-z0-9_-]{1,64})/bind',
			array(
				\Yazan_REST_Guard::args( \WP_REST_Server::CREATABLE, array( __CLASS__, 'bind' ), 'homepage.settings' ),
			)
		);

		register_rest_route(
			RestSupport::NAMESPACE_V1,
			'/homepage/documents/(?P<key>[a-z0-9_-]{1,64})',
			array(
				\Yazan_REST_Guard::args( \WP_REST_Server::DELETABLE, array( __CLASS__, 'remove' ), 'homepage.settings' ),
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
				return ServiceFactory::documents_handler()->listing();
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
				return ServiceFactory::documents_handler()->create(
					(string) $request->get_param( 'key' ),
					(string) $request->get_param( 'title' )
				);
			}
		);
	}

	/**
	 * Published pages, so binding is a choice from a list rather than an id someone has to go and
	 * look up in another tab.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function pages( $request ) {
		return RestSupport::run(
			static function () {
				$pages = get_posts(
					array(
						'post_type'        => 'page',
						'post_status'      => 'publish',
						'numberposts'      => 200,
						'orderby'          => 'title',
						'order'            => 'ASC',
						'suppress_filters' => false,
					)
				);

				$out = array();

				foreach ( $pages as $page ) {
					// The page WordPress uses as the front page is excluded: the homepage document
					// already renders there, and binding a second layout to it would be two
					// layouts on one URL.
					if ( (int) get_option( 'page_on_front' ) === (int) $page->ID ) {
						continue;
					}

					$out[] = array(
						'id'    => (int) $page->ID,
						'title' => $page->post_title ? $page->post_title : __( '(no title)', 'yazan' ),
					);
				}

				return array( 'pages' => $out );
			}
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function bind( $request ) {
		return RestSupport::run(
			static function () use ( $request ) {
				return ServiceFactory::documents_handler()->bind(
					\Yazan\Homepage\Domain\Document\DocumentKey::from( (string) $request['key'] ),
					(int) $request->get_param( 'page' )
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
				return ServiceFactory::documents_handler()->remove(
					\Yazan\Homepage\Domain\Document\DocumentKey::from( (string) $request['key'] )
				);
			}
		);
	}
}
