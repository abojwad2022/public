<?php
/**
 * Customer campaigns controller.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Rest\V1;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Rest\AbstractController;
use Yazan\Rewards\Modules\Campaigns\CampaignEligibility;
use Yazan\Rewards\Modules\Campaigns\CampaignTaskRepository;
use Yazan\Rewards\Modules\Campaigns\MarketingCampaignRepository;
use Yazan\Rewards\Modules\Campaigns\ParticipantRepository;
use Yazan\Rewards\Modules\Campaigns\ParticipationService;
use Yazan\Rewards\Modules\Campaigns\SubmissionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customer participation (require_customer):
 *   GET  /campaigns                                   — active campaigns you can join.
 *   GET  /campaigns/{id}                              — detail + your tasks/submissions.
 *   POST /campaigns/{id}/join
 *   POST /campaigns/{id}/tasks/{taskId}/submit        — { content_url, metric_value }.
 *   GET  /campaigns/me                                — campaigns you've joined.
 */
final class CampaignsController extends AbstractController {

	private MarketingCampaignRepository $repo;

	private CampaignTaskRepository $tasks;

	private ParticipationService $participation;

	private SubmissionRepository $submissions;

	private ParticipantRepository $participants;

	private CampaignEligibility $eligibility;

	/**
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		parent::__construct( $container );
		$this->repo          = $container->get( MarketingCampaignRepository::class );
		$this->tasks         = $container->get( CampaignTaskRepository::class );
		$this->participation = $container->get( ParticipationService::class );
		$this->submissions   = $container->get( SubmissionRepository::class );
		$this->participants  = $container->get( ParticipantRepository::class );
		$this->eligibility   = $container->get( CampaignEligibility::class );
	}

	/**
	 * @inheritDoc
	 */
	protected function base(): string {
		return 'campaigns';
	}

	/**
	 * @inheritDoc
	 */
	public function register_routes(): void {
		$auth = $this->auth->require_customer();

		register_rest_route( $this->namespace, '/' . $this->base(), array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'index' ), 'permission_callback' => $auth ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/me', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'mine' ), 'permission_callback' => $auth ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/(?P<id>\d+)', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'show' ), 'permission_callback' => $auth, 'args' => array( 'id' => array( 'sanitize_callback' => 'absint' ) ) ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/(?P<id>\d+)/join', array(
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'join' ), 'permission_callback' => $auth, 'args' => array( 'id' => array( 'sanitize_callback' => 'absint' ) ) ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/(?P<id>\d+)/tasks/(?P<task>\d+)/submit', array(
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'submit' ),
				'permission_callback' => $auth,
				'args'                => array(
					'id'          => array( 'sanitize_callback' => 'absint' ),
					'task'        => array( 'sanitize_callback' => 'absint' ),
					'content_url' => array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
					'metric_value' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				),
			),
		) );
	}

	/**
	 * GET /campaigns.
	 *
	 * @return \WP_REST_Response
	 */
	public function index(): \WP_REST_Response {
		$user_id = get_current_user_id();
		$items   = array();
		foreach ( $this->repo->active() as $campaign ) {
			if ( ! $this->eligibility->eligible( $user_id, $campaign->target ) ) {
				continue;
			}
			$items[] = $this->campaign_payload( $campaign, $user_id );
		}
		return $this->ok( array( 'items' => $items ) );
	}

	/**
	 * GET /campaigns/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function show( \WP_REST_Request $request ) {
		$campaign = $this->repo->get( absint( $request['id'] ) );
		if ( ! $campaign ) {
			return $this->error( 'not_found', __( 'Campaign not found.', 'yazan-rewards' ), 404 );
		}
		return $this->ok( $this->campaign_payload( $campaign, get_current_user_id() ) );
	}

	/**
	 * POST /campaigns/{id}/join.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function join( \WP_REST_Request $request ) {
		$result = $this->participation->join( get_current_user_id(), absint( $request['id'] ) );
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}

	/**
	 * POST /campaigns/{id}/tasks/{task}/submit.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function submit( \WP_REST_Request $request ) {
		$result = $this->participation->submit(
			get_current_user_id(),
			absint( $request['id'] ),
			absint( $request['task'] ),
			(string) $request->get_param( 'content_url' ),
			absint( $request->get_param( 'metric_value' ) )
		);
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}

	/**
	 * GET /campaigns/me.
	 *
	 * @return \WP_REST_Response
	 */
	public function mine(): \WP_REST_Response {
		$user_id = get_current_user_id();
		$items   = array();
		foreach ( $this->participants->campaigns_for_user( $user_id ) as $cid ) {
			$campaign = $this->repo->get( $cid );
			if ( $campaign ) {
				$items[] = $this->campaign_payload( $campaign, $user_id );
			}
		}
		return $this->ok( array( 'items' => $items ) );
	}

	/**
	 * Build a campaign payload with the caller's task/submission state.
	 *
	 * @param \Yazan\Rewards\Modules\Campaigns\Campaign $campaign Campaign.
	 * @param int                                       $user_id  User id.
	 * @return array<string,mixed>
	 */
	private function campaign_payload( $campaign, int $user_id ): array {
		$mine = array();
		foreach ( $this->submissions->for_user_campaign( $campaign->id, $user_id ) as $s ) {
			$mine[ (int) $s->task_id ] = (string) $s->status;
		}
		$tasks = array_map(
			function ( $t ) use ( $mine ) {
				return array(
					'id'           => (int) $t->id,
					'name'         => (string) $t->name,
					'task_type'    => (string) $t->task_type,
					'points_award' => (int) $t->points_award,
					'my_status'    => $mine[ (int) $t->id ] ?? 'none',
				);
			},
			$this->tasks->for_campaign( $campaign->id )
		);

		$participant = $this->participants->get( $campaign->id, $user_id );
		$data          = $campaign->to_array();
		$data['tasks'] = $tasks;
		$data['joined'] = null !== $participant;
		$data['my_points'] = $participant ? (int) $participant->points_earned : 0;
		$data['my_status'] = $participant ? (string) $participant->status : 'none';
		return $data;
	}
}
