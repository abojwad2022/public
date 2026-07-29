<?php
/**
 * Admin marketing campaigns controller.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Rest\V1;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Rest\AbstractController;
use Yazan\Rewards\Core\Security\Capabilities;
use Yazan\Rewards\Modules\Campaigns\CampaignAnalytics;
use Yazan\Rewards\Modules\Campaigns\CampaignTaskRepository;
use Yazan\Rewards\Modules\Campaigns\CampaignWorkflow;
use Yazan\Rewards\Modules\Campaigns\MarketingCampaignRepository;
use Yazan\Rewards\Modules\Campaigns\SubmissionRepository;
use Yazan\Rewards\Modules\Campaigns\SubmissionReviewService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Campaign management (admin, `manage_yazan_rewards`):
 *   GET/POST   /admin/campaigns              — list / create (tasks embedded).
 *   GET/PUT/DELETE /admin/campaigns/{id}     — read / update / delete.
 *   POST       /admin/campaigns/{id}/status  — workflow transition.
 *   GET        /admin/campaigns/{id}/analytics
 *   GET        /admin/campaigns/submissions           — review queue.
 *   POST       /admin/campaigns/submissions/{id}/(approve|reject)
 */
final class CampaignsAdminController extends AbstractController {

	private MarketingCampaignRepository $repo;

	private CampaignTaskRepository $tasks;

	private CampaignWorkflow $workflow;

	private SubmissionRepository $submissions;

	private SubmissionReviewService $review;

	private CampaignAnalytics $analytics;

	/**
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		parent::__construct( $container );
		$this->repo        = $container->get( MarketingCampaignRepository::class );
		$this->tasks       = $container->get( CampaignTaskRepository::class );
		$this->workflow    = $container->get( CampaignWorkflow::class );
		$this->submissions = $container->get( SubmissionRepository::class );
		$this->review      = $container->get( SubmissionReviewService::class );
		$this->analytics   = $container->get( CampaignAnalytics::class );
	}

	/**
	 * @inheritDoc
	 */
	protected function base(): string {
		return 'admin/campaigns';
	}

	/**
	 * @inheritDoc
	 */
	public function register_routes(): void {
		$m = $this->auth->require_cap( Capabilities::MANAGE );

		register_rest_route( $this->namespace, '/' . $this->base(), array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'index' ), 'permission_callback' => $m ),
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'create' ), 'permission_callback' => $m ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/submissions', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'queue' ), 'permission_callback' => $m ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/submissions/(?P<id>\d+)/(?P<decision>approve|reject)', array(
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'review' ), 'permission_callback' => $m, 'args' => array( 'id' => array( 'sanitize_callback' => 'absint' ) ) ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/(?P<id>\d+)', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'show' ), 'permission_callback' => $m, 'args' => array( 'id' => array( 'sanitize_callback' => 'absint' ) ) ),
			array( 'methods' => \WP_REST_Server::EDITABLE, 'callback' => array( $this, 'update' ), 'permission_callback' => $m, 'args' => array( 'id' => array( 'sanitize_callback' => 'absint' ) ) ),
			array( 'methods' => \WP_REST_Server::DELETABLE, 'callback' => array( $this, 'destroy' ), 'permission_callback' => $m, 'args' => array( 'id' => array( 'sanitize_callback' => 'absint' ) ) ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/(?P<id>\d+)/status', array(
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'set_status' ), 'permission_callback' => $m, 'args' => array( 'id' => array( 'sanitize_callback' => 'absint' ) ) ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/(?P<id>\d+)/analytics', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'campaign_analytics' ), 'permission_callback' => $m, 'args' => array( 'id' => array( 'sanitize_callback' => 'absint' ) ) ),
		) );
	}

	/**
	 * GET /admin/campaigns.
	 *
	 * @return \WP_REST_Response
	 */
	public function index(): \WP_REST_Response {
		$items = array_map(
			function ( $campaign ) {
				$data          = $campaign->to_array();
				$data['tasks'] = count( $this->tasks->for_campaign( $campaign->id ) );
				return $data;
			},
			$this->repo->all()
		);
		return $this->ok( array( 'items' => $items, 'statuses' => $this->workflow->statuses() ) );
	}

	/**
	 * GET /admin/campaigns/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function show( \WP_REST_Request $request ) {
		$campaign = $this->repo->get( absint( $request['id'] ) );
		if ( ! $campaign ) {
			return $this->error( 'not_found', __( 'Campaign not found.', 'yazan-rewards' ), 404 );
		}
		$data          = $campaign->to_array();
		$data['tasks'] = $this->task_list( $campaign->id );
		return $this->ok( $data );
	}

	/**
	 * POST /admin/campaigns.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create( \WP_REST_Request $request ) {
		if ( '' === trim( (string) $request->get_param( 'title' ) ) ) {
			return $this->error( 'missing_title', __( 'A title is required.', 'yazan-rewards' ), 400 );
		}
		$id = $this->repo->create( $this->payload( $request ) );
		if ( ! $id ) {
			return $this->error( 'create_failed', __( 'Could not create the campaign.', 'yazan-rewards' ), 500 );
		}
		$this->save_tasks( $id, $request->get_param( 'tasks' ) );
		return $this->show_by_id( $id, 201 );
	}

	/**
	 * PUT /admin/campaigns/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update( \WP_REST_Request $request ) {
		$id = absint( $request['id'] );
		if ( ! $this->repo->get( $id ) ) {
			return $this->error( 'not_found', __( 'Campaign not found.', 'yazan-rewards' ), 404 );
		}
		$this->repo->update_campaign( $id, $this->payload( $request, true ) );
		if ( null !== $request->get_param( 'tasks' ) ) {
			$this->save_tasks( $id, $request->get_param( 'tasks' ) );
		}
		return $this->show_by_id( $id );
	}

	/**
	 * DELETE /admin/campaigns/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function destroy( \WP_REST_Request $request ): \WP_REST_Response {
		$id = absint( $request['id'] );
		$this->tasks->delete_for_campaign( $id );
		$this->repo->delete_campaign( $id );
		return $this->ok( array( 'deleted' => true, 'id' => $id ) );
	}

	/**
	 * POST /admin/campaigns/{id}/status.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function set_status( \WP_REST_Request $request ) {
		$id     = absint( $request['id'] );
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( ! $this->workflow->transition( $id, $status ) ) {
			return $this->error( 'bad_transition', __( 'That status change is not allowed.', 'yazan-rewards' ), 400 );
		}
		return $this->show_by_id( $id );
	}

	/**
	 * GET /admin/campaigns/{id}/analytics.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function campaign_analytics( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->ok( $this->analytics->for_campaign( absint( $request['id'] ) ) );
	}

	/**
	 * GET /admin/campaigns/submissions.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function queue( \WP_REST_Request $request ): \WP_REST_Response {
		$campaign_id = absint( $request->get_param( 'campaign_id' ) );
		$items       = array_map(
			static function ( $s ) {
				$user = get_userdata( (int) $s->user_id );
				return array(
					'id'           => (int) $s->id,
					'campaign_id'  => (int) $s->campaign_id,
					'task_id'      => (int) $s->task_id,
					'user'         => $user ? $user->display_name : '',
					'type'         => (string) $s->type,
					'content_url'  => (string) $s->content_url,
					'metric_value' => (int) $s->metric_value,
					'points_award' => (int) $s->points_award,
					'created_at'   => (string) $s->created_at,
				);
			},
			$this->submissions->queue( $campaign_id, 200 )
		);
		return $this->ok( array( 'items' => $items ) );
	}

	/**
	 * POST /admin/campaigns/submissions/{id}/(approve|reject).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function review( \WP_REST_Request $request ) {
		$id     = absint( $request['id'] );
		$result = ( 'approve' === (string) $request['decision'] )
			? $this->review->approve( $id, get_current_user_id() )
			: $this->review->reject( $id, get_current_user_id() );
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * @param int $id     Campaign id.
	 * @param int $status HTTP status.
	 * @return \WP_REST_Response
	 */
	private function show_by_id( int $id, int $status = 200 ): \WP_REST_Response {
		$data          = $this->repo->get( $id )->to_array();
		$data['tasks'] = $this->task_list( $id );
		return $this->ok( $data, $status );
	}

	/**
	 * @param int $campaign_id Campaign id.
	 * @return array<int,array<string,mixed>>
	 */
	private function task_list( int $campaign_id ): array {
		return array_map(
			static function ( $t ) {
				$criteria = json_decode( (string) $t->criteria, true );
				return array(
					'id'           => (int) $t->id,
					'name'         => (string) $t->name,
					'task_type'    => (string) $t->task_type,
					'points_award' => (int) $t->points_award,
					'required'     => (bool) ( is_array( $criteria ) ? ( $criteria['required'] ?? false ) : false ),
					'criteria'     => is_array( $criteria ) ? $criteria : array(),
				);
			},
			$this->tasks->for_campaign( $campaign_id )
		);
	}

	/**
	 * Build the campaign column payload from the request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param bool             $partial Update mode.
	 * @return array<string,mixed>
	 */
	private function payload( \WP_REST_Request $request, bool $partial = false ): array {
		$out = array();
		foreach ( array( 'title', 'description', 'status', 'starts_at', 'ends_at' ) as $k ) {
			if ( null !== $request->get_param( $k ) ) {
				$out[ $k ] = (string) $request->get_param( $k );
			}
		}
		if ( null !== $request->get_param( 'reward_amount' ) ) {
			$out['reward_amount'] = absint( $request->get_param( 'reward_amount' ) );
		}
		if ( null !== $request->get_param( 'media' ) ) {
			$media = (array) $request->get_param( 'media' );
			$out['media'] = array(
				'images' => array_map( 'esc_url_raw', (array) ( $media['images'] ?? array() ) ),
				'videos' => array_map( 'esc_url_raw', (array) ( $media['videos'] ?? array() ) ),
			);
		}
		if ( null !== $request->get_param( 'target' ) ) {
			$target = (array) $request->get_param( 'target' );
			$out['target'] = array(
				'type'   => sanitize_key( (string) ( $target['type'] ?? 'all' ) ),
				'values' => array_map( 'sanitize_text_field', (array) ( $target['values'] ?? array() ) ),
			);
		}
		return $out;
	}

	/**
	 * Replace a campaign's tasks with the provided list.
	 *
	 * @param int   $campaign_id Campaign id.
	 * @param mixed $tasks       Tasks array.
	 * @return void
	 */
	private function save_tasks( int $campaign_id, $tasks ): void {
		if ( ! is_array( $tasks ) ) {
			return;
		}
		$this->tasks->delete_for_campaign( $campaign_id );
		$sort = 0;
		foreach ( $tasks as $t ) {
			if ( ! is_array( $t ) || '' === trim( (string) ( $t['name'] ?? '' ) ) ) {
				continue;
			}
			$criteria = is_array( $t['criteria'] ?? null ) ? $t['criteria'] : array();
			if ( ! empty( $t['required'] ) ) {
				$criteria['required'] = true;
			}
			if ( isset( $t['views'] ) ) {
				$criteria['views'] = absint( $t['views'] );
			}
			$this->tasks->create(
				array(
					'campaign_id'  => $campaign_id,
					'name'         => sanitize_text_field( (string) $t['name'] ),
					'task_type'    => sanitize_key( (string) ( $t['task_type'] ?? 'custom' ) ),
					'criteria'     => $criteria,
					'points_award' => absint( $t['points_award'] ?? 0 ),
					'sort'         => $sort++,
					'active'       => true,
				)
			);
		}
	}
}
