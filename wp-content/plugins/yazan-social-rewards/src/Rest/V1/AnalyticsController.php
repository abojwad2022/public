<?php
/**
 * Admin analytics controller.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Rest\V1;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Rest\AbstractController;
use Yazan\Rewards\Core\Security\Capabilities;
use Yazan\Rewards\Modules\Analytics\AnalyticsMetrics;
use Yazan\Rewards\Modules\Analytics\ReportRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only analytics for the dashboard (admin, `manage_yazan_rewards`):
 *   GET /admin/analytics/overview?range=30|90|365|all — all KPIs + charts data.
 *   GET /admin/analytics/series?metric=&from=&to=      — one time-series.
 *   GET /admin/analytics/export?report=customers|campaigns|business — { filename, csv }.
 */
final class AnalyticsController extends AbstractController {

	private AnalyticsMetrics $metrics;

	private ReportRepository $reports;

	/**
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		parent::__construct( $container );
		$this->metrics = $container->get( AnalyticsMetrics::class );
		$this->reports = $container->get( ReportRepository::class );
	}

	/**
	 * @inheritDoc
	 */
	protected function base(): string {
		return 'admin/analytics';
	}

	/**
	 * @inheritDoc
	 */
	public function register_routes(): void {
		$manager = $this->auth->require_cap( Capabilities::MANAGE );

		register_rest_route( $this->namespace, '/' . $this->base() . '/overview', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'overview' ), 'permission_callback' => $manager ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/series', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'series' ), 'permission_callback' => $manager ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/export', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'export' ), 'permission_callback' => $manager ),
		) );
	}

	/**
	 * GET /admin/analytics/overview.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function overview( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->ok( $this->metrics->overview( $this->range_days( $request ) ) );
	}

	/**
	 * GET /admin/analytics/series.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function series( \WP_REST_Request $request ): \WP_REST_Response {
		$metric = sanitize_key( (string) $request->get_param( 'metric' ) );
		list( $from, $to ) = $this->metrics->range( $this->range_days( $request ) );
		if ( null !== $request->get_param( 'from' ) ) {
			$from = sanitize_text_field( (string) $request->get_param( 'from' ) );
		}
		if ( null !== $request->get_param( 'to' ) ) {
			$to = sanitize_text_field( (string) $request->get_param( 'to' ) );
		}
		return $this->ok( array( 'metric' => $metric, 'series' => $this->reports->series( $metric, $from, $to ) ) );
	}

	/**
	 * GET /admin/analytics/export — returns a CSV string for the JS to download.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function export( \WP_REST_Request $request ): \WP_REST_Response {
		$report = sanitize_key( (string) $request->get_param( 'report' ) );
		$data   = $this->metrics->overview( $this->range_days( $request ) );
		$rows   = array();

		if ( 'campaigns' === $report ) {
			$rows[] = array( 'Campaign', 'Participants', 'Submissions', 'Points generated', 'Engagement %' );
			foreach ( (array) $data['campaigns']['best'] as $c ) {
				$rows[] = array( $c['title'], $c['participants'], $c['submissions'], $c['points_generated'], round( $c['engagement_rate'] * 100, 1 ) );
			}
		} elseif ( 'business' === $report ) {
			$b      = $data['business'];
			$rows[] = array( 'Metric', 'Value' );
			$rows[] = array( 'Referral revenue', $b['referral_revenue'] );
			$rows[] = array( 'Reward cost (points)', $b['reward_cost']['points'] );
			$rows[] = array( 'Reward cost (value)', $b['reward_cost']['value'] );
			$rows[] = array( 'Redemptions', $b['reward_cost']['redemptions'] );
			$rows[] = array( 'Point liability (points)', $b['point_liability']['points_outstanding'] );
			$rows[] = array( 'Point liability (value)', $b['point_liability']['total_value'] );
			$rows[] = array( 'Retention %', round( $b['retention_rate'] * 100, 1 ) );
			$rows[] = array( 'Customer lifetime value', $b['clv'] );
			$rows[] = array( 'Purchasing customers', $b['purchasing_customers'] );
		} else {
			$report = 'customers';
			$cu     = $data['customers'];
			$rows[] = array( 'Metric', 'Value' );
			$rows[] = array( 'Total customers', $cu['total'] );
			$rows[] = array( 'VIP customers', $cu['vip'] );
			$rows[] = array( 'Active ambassadors', $cu['active_ambassadors'] );
			$rows[] = array( '', '' );
			$rows[] = array( 'Tier', 'Customers' );
			foreach ( (array) $cu['tiers'] as $t ) {
				$rows[] = array( $t['label'], $t['count'] );
			}
		}

		return $this->ok( array(
			'filename' => 'yazan-' . $report . '-' . gmdate( 'Ymd' ) . '.csv',
			'csv'      => $this->to_csv( $rows ),
		) );
	}

	/* --------------------------------------------------------------------- */

	/**
	 * Parse the `range` param (30|90|365|all) into days.
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

	/**
	 * Build a CSV string with formula-injection-safe cells.
	 *
	 * @param array<int,array> $rows Rows.
	 * @return string
	 */
	private function to_csv( array $rows ): string {
		$lines = array();
		foreach ( $rows as $row ) {
			$cells = array();
			foreach ( $row as $value ) {
				$value = (string) $value;
				// Neutralise spreadsheet formula injection.
				if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@' ), true ) ) {
					$value = "'" . $value;
				}
				$cells[] = '"' . str_replace( '"', '""', $value ) . '"';
			}
			$lines[] = implode( ',', $cells );
		}
		return implode( "\r\n", $lines );
	}
}
