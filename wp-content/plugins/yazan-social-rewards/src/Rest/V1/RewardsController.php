<?php
/**
 * Rewards REST controller.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Rest\V1;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Contracts\PointsLedgerInterface;
use Yazan\Rewards\Core\Rest\AbstractController;
use Yazan\Rewards\Modules\Rewards\RedemptionRepository;
use Yazan\Rewards\Modules\Rewards\RedemptionService;
use Yazan\Rewards\Modules\Rewards\RewardRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GET  /rewards            — the redeemable catalog + the caller's balance.
 * POST /rewards/redeem     — redeem a reward (body: { reward_id }).
 * GET  /rewards/history    — the caller's redemption history.
 *
 * Requires a logged-in customer. The redeem endpoint's real authorization is the
 * points balance itself, enforced server-side in the service.
 */
final class RewardsController extends AbstractController {

	private RewardRepository $rewards;

	private RedemptionService $service;

	private PointsLedgerInterface $points;

	/**
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		parent::__construct( $container );
		$this->rewards = $container->get( RewardRepository::class );
		$this->service = $container->get( RedemptionService::class );
		$this->points  = $container->get( PointsLedgerInterface::class );
	}

	/**
	 * @inheritDoc
	 */
	protected function base(): string {
		return 'rewards';
	}

	/**
	 * @inheritDoc
	 */
	public function register_routes(): void {
		$auth = $this->auth->require_customer();

		register_rest_route(
			$this->namespace,
			'/' . $this->base(),
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'catalog' ),
					'permission_callback' => $auth,
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->base() . '/redeem',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'redeem' ),
					'permission_callback' => $auth,
					'args'                => array(
						'reward_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->base() . '/history',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'history' ),
					'permission_callback' => $auth,
				),
			)
		);
	}

	/**
	 * GET /rewards.
	 *
	 * @return \WP_REST_Response
	 */
	public function catalog(): \WP_REST_Response {
		$user_id = get_current_user_id();
		$items   = array_map(
			static fn( $reward ) => $reward->to_array(),
			$this->rewards->active()
		);

		return $this->ok(
			array(
				'balance' => $this->points->balance( $user_id ),
				'items'   => array_values( $items ),
			)
		);
	}

	/**
	 * POST /rewards/redeem.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function redeem( \WP_REST_Request $request ) {
		$reward_id = absint( $request->get_param( 'reward_id' ) );
		$result    = $this->service->redeem( get_current_user_id(), $reward_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->ok( $result );
	}

	/**
	 * GET /rewards/history.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function history( \WP_REST_Request $request ): \WP_REST_Response {
		$repo = $this->container->get( RedemptionRepository::class );
		return $this->ok(
			$repo->history(
				get_current_user_id(),
				absint( $request->get_param( 'per_page' ) ?: 20 ),
				absint( $request->get_param( 'page' ) ?: 1 )
			)
		);
	}
}
