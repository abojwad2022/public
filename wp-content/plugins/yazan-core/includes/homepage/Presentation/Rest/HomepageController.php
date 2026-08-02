<?php
/**
 * The document itself: read, save the draft, publish, schedule.
 *
 * Every route is built with Yazan_REST_Guard::args(), which emits the callback, the
 * permission_callback AND the guard's tag from a single slug — so the central default-deny filter
 * and the route's own check can never disagree.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Presentation\Rest;

use Yazan\Homepage\Infrastructure\Bootstrap\ServiceFactory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * /homepage routes.
 */
final class HomepageController {

	/**
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			RestSupport::NAMESPACE_V1,
			'/homepage',
			array(
				\Yazan_REST_Guard::args( \WP_REST_Server::READABLE, array( __CLASS__, 'read' ), 'homepage.view' ),
				\Yazan_REST_Guard::args( \WP_REST_Server::EDITABLE, array( __CLASS__, 'save' ), 'homepage.edit' ),
			)
		);

		register_rest_route(
			RestSupport::NAMESPACE_V1,
			'/homepage/components',
			array(
				\Yazan_REST_Guard::args( \WP_REST_Server::READABLE, array( __CLASS__, 'components' ), 'homepage.view' ),
			)
		);

		register_rest_route(
			RestSupport::NAMESPACE_V1,
			'/homepage/publish',
			array(
				\Yazan_REST_Guard::args( \WP_REST_Server::CREATABLE, array( __CLASS__, 'publish' ), 'homepage.publish' ),
			)
		);

		register_rest_route(
			RestSupport::NAMESPACE_V1,
			'/homepage/revert-publish',
			array(
				\Yazan_REST_Guard::args( \WP_REST_Server::CREATABLE, array( __CLASS__, 'revert_publish' ), 'homepage.publish' ),
			)
		);

		register_rest_route(
			RestSupport::NAMESPACE_V1,
			'/homepage/schedule',
			array(
				\Yazan_REST_Guard::args( \WP_REST_Server::CREATABLE, array( __CLASS__, 'schedule' ), 'homepage.publish' ),
			)
		);

		register_rest_route(
			RestSupport::NAMESPACE_V1,
			'/homepage/preview-sections',
			array(
				\Yazan_REST_Guard::args( \WP_REST_Server::READABLE, array( __CLASS__, 'preview_sections' ), 'homepage.preview' ),
			)
		);

		register_rest_route(
			RestSupport::NAMESPACE_V1,
			'/homepage/sources/preview',
			array(
				\Yazan_REST_Guard::args( \WP_REST_Server::READABLE, array( __CLASS__, 'preview_source' ), 'homepage.edit' ),
			)
		);
	}

	/**
	 * Everything the builder needs to open.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function read( $request ) {
		return RestSupport::run(
			static function () use ( $request ) {
				return ServiceFactory::editor_document()->handle( RestSupport::key( $request ) );
			}
		);
	}

	/**
	 * The component catalog on its own — cheap enough to poll after a plugin change.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function components( $request ) {
		return RestSupport::run(
			static function () {
				$query = ServiceFactory::editor_document();

				return array(
					'components' => $query->catalog(),
					'design'     => \Yazan\Homepage\Domain\Design\DesignSchema::schema()->to_array(),
					'can'        => $query->capabilities(),
				);
			}
		);
	}

	/**
	 * Save the whole draft (autosave).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function save( $request ) {
		return RestSupport::run(
			static function () use ( $request ) {
				$sections = $request->get_param( 'sections' );

				if ( ! is_array( $sections ) ) {
					throw new \InvalidArgumentException( 'sections must be an array.' );
				}

				return ServiceFactory::save_draft()->handle(
					RestSupport::key( $request ),
					$sections,
					RestSupport::version( $request )
				);
			}
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function publish( $request ) {
		return RestSupport::run(
			static function () use ( $request ) {
				return ServiceFactory::publish()->handle(
					RestSupport::key( $request ),
					(string) $request->get_param( 'note' ),
					RestSupport::version( $request )
				);
			}
		);
	}

	/**
	 * Put the previous published version back on the live page. The draft is untouched.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function revert_publish( $request ) {
		return RestSupport::run(
			static function () use ( $request ) {
				return ServiceFactory::revert_publish()->handle(
					RestSupport::key( $request ),
					RestSupport::version( $request )
				);
			}
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function schedule( $request ) {
		return RestSupport::run(
			static function () use ( $request ) {
				$when = $request->get_param( 'when' );

				// The builder sends a site-local datetime; storage and comparison are UTC.
				$timestamp = is_numeric( $when )
					? (int) $when
					: (int) get_gmt_from_date( (string) $when, 'U' );

				if ( ! $timestamp ) {
					throw new \InvalidArgumentException( 'A valid publish time is required.' );
				}

				return ServiceFactory::publish()->schedule(
					RestSupport::key( $request ),
					$timestamp,
					RestSupport::version( $request )
				);
			}
		);
	}

	/**
	 * "What would this product rule actually show?" — answered before anything is saved.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function preview_source( $request ) {
		return RestSupport::run(
			static function () use ( $request ) {
				$spec = array(
					'source'    => (string) $request->get_param( 'source' ),
					'limit'     => (int) $request->get_param( 'limit' ),
					'terms'     => (array) $request->get_param( 'terms' ),
					'ids'       => (array) $request->get_param( 'ids' ),
					'attribute' => (string) $request->get_param( 'attribute' ),
					'orderby'   => (string) $request->get_param( 'orderby' ),
					'order'     => (string) $request->get_param( 'order' ),
				);

				return array( 'products' => ServiceFactory::product_query()->resolve( $spec ) );
			}
		);
	}

	/**
	 * The rendered HTML of a few DRAFT sections, for the preview patcher.
	 *
	 * A read, behind `homepage.preview` — the same permission the preview page itself checks, so
	 * the two can never disagree about who may look at unpublished work.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function preview_sections( $request ) {
		return RestSupport::run(
			static function () use ( $request ) {
				$raw = $request->get_param( 'ids' );
				$ids = array();

				foreach ( is_array( $raw ) ? $raw : explode( ',', (string) $raw ) as $id ) {
					$id = trim( (string) $id );

					// Shape-checked here rather than trusted: the id becomes a marker name the
					// browser then matches on, and only a uuid can ever be one.
					if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id ) ) {
						$ids[] = $id;
					}
				}

				if ( ! $ids ) {
					return array( 'sections' => new \stdClass() );
				}

				$html = \Yazan\Homepage\Presentation\Render\ThemeBridge::render_sections(
					RestSupport::key( $request ),
					$ids
				);

				return array( 'sections' => $html ? $html : new \stdClass() );
			}
		);
	}
}
