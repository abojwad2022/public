<?php
/**
 * Homepage import / export.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Presentation\Rest;

use Yazan\Homepage\Domain\Event\DomainEvent;
use Yazan\Homepage\Infrastructure\Bootstrap\ServiceFactory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * /homepage/export and /homepage/import.
 */
final class PortingController {

	/** Refuse anything larger than this. A homepage package is text; megabytes mean a mistake. */
	const MAX_PACKAGE_BYTES = 2097152;

	/**
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			RestSupport::NAMESPACE_V1,
			'/homepage/export',
			array(
				\Yazan_REST_Guard::args( \WP_REST_Server::READABLE, array( __CLASS__, 'export' ), 'homepage.export' ),
			)
		);

		register_rest_route(
			RestSupport::NAMESPACE_V1,
			'/homepage/import',
			array(
				\Yazan_REST_Guard::args( \WP_REST_Server::CREATABLE, array( __CLASS__, 'import' ), 'homepage.import' ),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function export( $request ) {
		return RestSupport::run(
			static function () use ( $request ) {
				$document = ServiceFactory::documents()->get( RestSupport::key( $request ) );
				$package  = ServiceFactory::porting()->export( $document, (bool) $request->get_param( 'live' ) );

				ServiceFactory::events()->dispatch(
					DomainEvent::document(
						DomainEvent::DOCUMENT_EXPORTED,
						$document->key()->value(),
						array( 'sections' => count( $package['document']['sections'] ) )
					)
				);

				return $package;
			}
		);
	}

	/**
	 * Dry run by default. A real import must ask for it explicitly.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function import( $request ) {
		return RestSupport::run(
			static function () use ( $request ) {
				$package = $request->get_param( 'package' );

				if ( is_string( $package ) ) {
					if ( strlen( $package ) > self::MAX_PACKAGE_BYTES ) {
						throw new \InvalidArgumentException( 'That package is too large to be a homepage.' );
					}
					$package = json_decode( $package, true );
				}

				if ( ! is_array( $package ) ) {
					throw new \InvalidArgumentException( 'The package could not be read as JSON.' );
				}

				// Default to the dry run: the destructive path has to be asked for by name.
				if ( ! $request->get_param( 'confirm' ) ) {
					return ServiceFactory::import()->dry_run( $package );
				}

				return ServiceFactory::import()->handle(
					RestSupport::key( $request ),
					$package,
					RestSupport::version( $request )
				);
			}
		);
	}
}
