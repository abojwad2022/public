<?php
/**
 * Admin fraud-review controller.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Rest\V1;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Rest\AbstractController;
use Yazan\Rewards\Core\Security\Capabilities;
use Yazan\Rewards\Modules\AntiFraud\FraudRepository;
use Yazan\Rewards\Modules\AntiFraud\FraudReviewService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fraud case management (admin, `manage_yazan_rewards`):
 *   GET  /admin/fraud                     — cases (filter status|type|severity) + stats.
 *   GET  /admin/fraud/{id}                — one case (decoded context/signals).
 *   POST /admin/fraud/{id}/{approve|reject|review}.
 *   GET  /admin/fraud/user/{id}           — a customer's risk score + cases.
 */
final class FraudAdminController extends AbstractController {

	private FraudRepository $cases;

	private FraudReviewService $review;

	/**
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		parent::__construct( $container );
		$this->cases  = $container->get( FraudRepository::class );
		$this->review = $container->get( FraudReviewService::class );
	}

	/**
	 * @inheritDoc
	 */
	protected function base(): string {
		return 'admin/fraud';
	}

	/**
	 * @inheritDoc
	 */
	public function register_routes(): void {
		$manager = $this->auth->require_cap( Capabilities::MANAGE );

		register_rest_route( $this->namespace, '/' . $this->base(), array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'index' ), 'permission_callback' => $manager ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/(?P<id>\d+)', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'show' ), 'permission_callback' => $manager, 'args' => array( 'id' => array( 'sanitize_callback' => 'absint' ) ) ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/(?P<id>\d+)/(?P<decision>approve|reject|review)', array(
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'resolve' ), 'permission_callback' => $manager, 'args' => array( 'id' => array( 'sanitize_callback' => 'absint' ) ) ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/user/(?P<id>\d+)', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'user' ), 'permission_callback' => $manager, 'args' => array( 'id' => array( 'sanitize_callback' => 'absint' ) ) ),
		) );
	}

	/**
	 * GET /admin/fraud.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		$filters = array(
			'status'   => sanitize_key( (string) $request->get_param( 'status' ) ),
			'type'     => sanitize_key( (string) $request->get_param( 'type' ) ),
			'severity' => sanitize_key( (string) $request->get_param( 'severity' ) ),
		);
		$items = array_map( array( $this, 'row' ), $this->cases->list_cases( array_filter( $filters ), 200 ) );
		return $this->ok( array(
			'items'      => $items,
			'stats'      => $this->cases->stats(),
			'open_count' => $this->cases->open_count(),
		) );
	}

	/**
	 * GET /admin/fraud/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function show( \WP_REST_Request $request ) {
		$case = $this->cases->find( absint( $request['id'] ) );
		return $case ? $this->ok( $this->row( $case, true ) ) : $this->error( 'not_found', __( 'Case not found.', 'yazan-rewards' ), 404 );
	}

	/**
	 * POST /admin/fraud/{id}/{approve|reject|review}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function resolve( \WP_REST_Request $request ) {
		$id       = absint( $request['id'] );
		$decision = (string) $request['decision'];
		$reviewer = get_current_user_id();

		if ( 'review' === $decision ) {
			$this->review->send_to_review( $id, $reviewer );
			return $this->ok( array( 'ok' => true, 'id' => $id, 'status' => FraudRepository::STATUS_REVIEW ) );
		}
		$ok = ( 'approve' === $decision ) ? $this->review->approve( $id, $reviewer ) : $this->review->reject( $id, $reviewer );
		return $ok
			? $this->ok( array( 'ok' => true, 'id' => $id, 'status' => 'approve' === $decision ? 'approved' : 'rejected' ) )
			: $this->error( 'not_open', __( 'That case is already resolved.', 'yazan-rewards' ), 409 );
	}

	/**
	 * GET /admin/fraud/user/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function user( \WP_REST_Request $request ): \WP_REST_Response {
		$uid  = absint( $request['id'] );
		$user = $uid > 0 ? get_userdata( $uid ) : false;
		return $this->ok( array(
			'user'        => array(
				'id'   => $uid,
				'name' => $user ? $user->display_name : ( '#' . $uid ),
			),
			'risk_score'  => (int) get_user_meta( $uid, FraudReviewService::META_RISK_SCORE, true ),
			'high_risk'   => (bool) get_user_meta( $uid, FraudReviewService::META_RISK_FLAGGED, true ),
			'cases'       => array_map( array( $this, 'row' ), $this->cases->for_user( $uid, 50 ) ),
		) );
	}

	/**
	 * Shape a case row for the API.
	 *
	 * @param object $c    Case row.
	 * @param bool   $full Include decoded context.
	 * @return array<string,mixed>
	 */
	private function row( object $c, bool $full = false ): array {
		$user = get_userdata( (int) $c->user_id );
		$out  = array(
			'id'         => (int) $c->id,
			'user'       => $user ? $user->display_name : ( '#' . (int) $c->user_id ),
			'user_id'    => (int) $c->user_id,
			'type'       => (string) $c->type,
			'severity'   => (string) $c->severity,
			'status'     => (string) $c->status,
			'score'      => (int) $c->score,
			'source'     => (string) $c->source,
			'source_id'  => (int) $c->source_id,
			'reward'     => array(
				'kind'   => (string) $c->reward_kind,
				'amount' => (string) $c->reward_amount,
			),
			'created_at' => (string) $c->created_at,
		);
		if ( $full ) {
			$out['context'] = json_decode( (string) ( $c->context ?? '' ), true ) ?: array();
			$out['signals'] = json_decode( (string) ( $c->signals ?? '' ), true ) ?: array();
			$out['note']    = (string) $c->note;
		}
		return $out;
	}
}
