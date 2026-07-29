<?php
/**
 * Public statistics endpoint.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Rest\PublicV1;

use Yazan\Rewards\Core\Security\Capabilities;
use Yazan\Rewards\Modules\Analytics\AnalyticsMetrics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GET /yazan/v1/statistics?range=7|30|90|365|all — store-wide rewards analytics
 * (customers / campaigns / business KPIs / series). Restricted to the read
 * capability `view_yazan_rewards` because it exposes business + financial figures;
 * it is NOT a public/customer endpoint.
 */
final class StatisticsController extends AbstractPublicController {

	/**
	 * @inheritDoc
	 */
	protected function base(): string {
		return 'statistics';
	}

	/**
	 * @inheritDoc
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/' . $this->base(), array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'statistics' ),
				'permission_callback' => $this->auth->require_cap( Capabilities::VIEW ),
			),
		) );
	}

	/**
	 * GET /statistics.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function statistics( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->ok( $this->container->get( AnalyticsMetrics::class )->overview( $this->range_days( $request ) ) );
	}

	/**
	 * Parse `range` (7|30|90|365|all) into days (0 = all-time).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return int
	 */
	private function range_days( \WP_REST_Request $request ): int {
		$range = sanitize_key( (string) $request->get_param( 'range' ) );
		if ( 'all' === $range ) {
			return 0;
		}
		$days = (int) $range;
		return in_array( $days, array( 7, 30, 90, 365 ), true ) ? $days : 30;
	}
}
