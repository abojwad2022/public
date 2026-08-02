<?php
/**
 * Shared REST plumbing: one place that turns a domain failure into an HTTP answer.
 *
 * Every controller wraps its work in run(), so adding a new failure mode never means adding a
 * try/catch to a dozen methods — and a status code can never drift between two endpoints that
 * fail the same way.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Presentation\Rest;

use Yazan\Homepage\Domain\Document\DocumentKey;
use Yazan\Homepage\Domain\Exception\HomepageException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Controller helpers.
 */
final class RestSupport {

	/** The REST namespace this module lives in — the dashboard's own. */
	const NAMESPACE_V1 = 'yazan/v1';

	/** A section id in a route. Tight enough that `/sections/reorder` cannot match it. */
	const UUID_PATTERN = '(?P<id>[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})';

	/**
	 * Execute a use case and translate anything it throws.
	 *
	 * @param callable $work Work.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function run( callable $work ) {
		try {
			return rest_ensure_response( $work() );
		} catch ( HomepageException $e ) {
			return new \WP_Error(
				$e->error_code(),
				$e->getMessage(),
				array_merge( array( 'status' => $e->status() ), $e->context() )
			);
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error(
				'yazan_homepage_bad_request',
				$e->getMessage(),
				array( 'status' => 400 )
			);
		} catch ( \Throwable $e ) {
			// Never leak a stack trace or a file path to the client.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[yazan-homepage] ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}

			return new \WP_Error(
				'yazan_homepage_failed',
				__( 'The homepage could not be updated.', 'yazan' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * The document key a request targets. Only `default` exists today; the parameter is here so
	 * multi-homepage does not become a breaking API change later.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return DocumentKey
	 */
	public static function key( $request ) {
		$key = $request->get_param( 'doc' );

		return $key ? DocumentKey::from( $key ) : DocumentKey::default_key();
	}

	/**
	 * The expected document version, or null when the client did not send one.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return int|null
	 */
	public static function version( $request ) {
		$version = $request->get_param( 'version' );

		return ( null === $version || '' === $version ) ? null : (int) $version;
	}
}
