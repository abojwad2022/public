<?php
/**
 * Public campaign endpoint.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Rest\PublicV1;

use Yazan\Rewards\Modules\Campaigns\ParticipationService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * POST /yazan/v1/campaign/submit — submit content to a campaign task.
 * Flat body `{ campaign_id, task_id, url, metric? }`; delegates to the same
 * ParticipationService the first-party UI uses (auto-join, rate-limit, eligibility,
 * duplicate + review-queue handling all preserved).
 */
final class CampaignController extends AbstractPublicController {

	/**
	 * @inheritDoc
	 */
	protected function base(): string {
		return 'campaign';
	}

	/**
	 * @inheritDoc
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/' . $this->base() . '/submit', array(
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'submit' ),
				'permission_callback' => $this->auth->require_customer(),
				'args'                => array(
					'campaign_id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					'task_id'     => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					'url'         => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
					'metric'      => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				),
			),
		) );
	}

	/**
	 * POST /campaign/submit.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function submit( \WP_REST_Request $request ) {
		$result = $this->container->get( ParticipationService::class )->submit(
			get_current_user_id(),
			absint( $request->get_param( 'campaign_id' ) ),
			absint( $request->get_param( 'task_id' ) ),
			esc_url_raw( (string) $request->get_param( 'url' ) ),
			absint( $request->get_param( 'metric' ) )
		);
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}
}
