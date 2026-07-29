<?php
/**
 * Admin reports REST controller.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Rest\V1;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Rest\AbstractController;
use Yazan\Rewards\Core\Security\Capabilities;
use Yazan\Rewards\Modules\Analytics\LiabilityCalculator;
use Yazan\Rewards\Modules\Analytics\ReportRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GET /reports/summary — liability + 30-day totals + open fraud count.
 * GET /reports/series  — a metric's daily series (?metric=&from=&to=).
 *
 * Admin only (manage_yazan_rewards).
 */
final class ReportsController extends AbstractController {

	private ReportRepository $reports;

	private LiabilityCalculator $liability;

	/**
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		parent::__construct( $container );
		$this->reports   = $container->get( ReportRepository::class );
		$this->liability = $container->get( LiabilityCalculator::class );
	}

	/**
	 * @inheritDoc
	 */
	protected function base(): string {
		return 'reports';
	}

	/**
	 * @inheritDoc
	 */
	public function register_routes(): void {
		$manager = $this->auth->require_cap( Capabilities::MANAGE );

		register_rest_route(
			$this->namespace,
			'/' . $this->base() . '/summary',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'summary' ),
					'permission_callback' => $manager,
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->base() . '/series',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'series' ),
					'permission_callback' => $manager,
					'args'                => array(
						'metric' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
						'from'   => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
						'to'     => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					),
				),
			)
		);
	}

	/**
	 * GET /reports/summary.
	 *
	 * @return \WP_REST_Response
	 */
	public function summary(): \WP_REST_Response {
		$to   = date_i18n( 'Y-m-d' );
		$from = date_i18n( 'Y-m-d', strtotime( '-29 days' ) );

		$fraud_open = 0;
		if ( $this->container->has( \Yazan\Rewards\Modules\AntiFraud\FraudRepository::class ) ) {
			$fraud_open = $this->container->get( \Yazan\Rewards\Modules\AntiFraud\FraudRepository::class )->open_count();
		}

		return $this->ok(
			array(
				'range'     => array( 'from' => $from, 'to' => $to ),
				'liability' => $this->liability->snapshot(),
				'totals'    => array(
					'points_issued'   => $this->reports->sum( 'points_issued', $from, $to ),
					'points_spent'    => $this->reports->sum( 'points_spent', $from, $to ),
					'credit_issued'   => $this->reports->sum( 'credit_issued', $from, $to ),
					'credit_spent'    => $this->reports->sum( 'credit_spent', $from, $to ),
					'redemptions'     => $this->reports->sum( 'redemptions', $from, $to ),
					'commission_paid' => $this->reports->sum( 'commission_paid', $from, $to ),
					'orders_rewarded' => $this->reports->sum( 'orders_rewarded', $from, $to ),
				),
				'fraud_open' => $fraud_open,
			)
		);
	}

	/**
	 * GET /reports/series.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function series( \WP_REST_Request $request ): \WP_REST_Response {
		$metric = sanitize_key( (string) $request->get_param( 'metric' ) );
		$to     = $request->get_param( 'to' ) ? sanitize_text_field( (string) $request->get_param( 'to' ) ) : date_i18n( 'Y-m-d' );
		$from   = $request->get_param( 'from' ) ? sanitize_text_field( (string) $request->get_param( 'from' ) ) : date_i18n( 'Y-m-d', strtotime( '-29 days' ) );

		return $this->ok(
			array(
				'metric' => $metric,
				'series' => $this->reports->series( $metric, $from, $to ),
			)
		);
	}
}
