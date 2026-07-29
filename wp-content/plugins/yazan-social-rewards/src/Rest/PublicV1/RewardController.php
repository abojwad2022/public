<?php
/**
 * Public reward endpoint.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Rest\PublicV1;

use Yazan\Rewards\Modules\Rewards\RedemptionService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * POST /yazan/v1/reward/redeem — redeem a catalog reward for points.
 * Body `{ reward_id }`; delegates to the transactional RedemptionService (rate-limit
 * → validate → reserve stock → debit → fulfill via the reward provider registry →
 * record → announce, with automatic points refund if fulfillment fails).
 */
final class RewardController extends AbstractPublicController {

	/**
	 * @inheritDoc
	 */
	protected function base(): string {
		return 'reward';
	}

	/**
	 * @inheritDoc
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/' . $this->base() . '/redeem', array(
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'redeem' ),
				'permission_callback' => $this->auth->require_customer(),
				'args'                => array(
					'reward_id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				),
			),
		) );
	}

	/**
	 * POST /reward/redeem.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function redeem( \WP_REST_Request $request ) {
		$result = $this->container->get( RedemptionService::class )->redeem(
			get_current_user_id(),
			absint( $request->get_param( 'reward_id' ) )
		);
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}
}
