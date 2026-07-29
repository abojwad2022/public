<?php
/**
 * Admin points ledger controller.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Rest\V1;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Contracts\PointsLedgerInterface;
use Yazan\Rewards\Core\Rest\AbstractController;
use Yazan\Rewards\Core\Security\Capabilities;
use Yazan\Rewards\Modules\Points\PointsLedger;
use Yazan\Rewards\Modules\Points\PointsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The admin side of the financial ledger (manage_yazan_rewards):
 *   GET  /admin/points/pending        — the pending-entries review queue.
 *   POST /admin/points/{id}/approve   — approve a pending entry (it now counts).
 *   POST /admin/points/{id}/reject    — reject a pending entry (never counts).
 *   POST /admin/points/adjust         — manual credit/debit with a reason,
 *                                       optionally created as Pending.
 */
final class PointsAdminController extends AbstractController {

	private PointsRepository $repo;

	private PointsLedger $ledger;

	/**
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		parent::__construct( $container );
		$this->repo   = $container->get( PointsRepository::class );
		$this->ledger = $container->get( PointsLedger::class );
	}

	/**
	 * @inheritDoc
	 */
	protected function base(): string {
		return 'admin/points';
	}

	/**
	 * @inheritDoc
	 */
	public function register_routes(): void {
		$manager = $this->auth->require_cap( Capabilities::MANAGE );

		register_rest_route( $this->namespace, '/' . $this->base() . '/pending', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'pending' ), 'permission_callback' => $manager ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/(?P<id>\d+)/(?P<decision>approve|reject)', array(
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'decide' ), 'permission_callback' => $manager, 'args' => array( 'id' => array( 'sanitize_callback' => 'absint' ) ) ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/adjust', array(
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'adjust' ),
				'permission_callback' => $manager,
				'args'                => array(
					'user_id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					'points'  => array( 'required' => true, 'type' => 'integer' ),
					'reason'  => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'pending' => array( 'type' => 'boolean', 'default' => false ),
				),
			),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/users', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search_users' ),
				'permission_callback' => $manager,
				'args'                => array( 'q' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) ),
			),
		) );
	}

	/**
	 * GET /admin/points/users?q= — typeahead for the manual-adjustment form.
	 *
	 * Resolves free text (username, display name, or email) to a small list of
	 * matching users so an operator never has to know a numeric user ID.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function search_users( \WP_REST_Request $request ): \WP_REST_Response {
		$q = trim( (string) $request->get_param( 'q' ) );
		if ( mb_strlen( $q ) < 2 ) {
			return $this->ok( array( 'items' => array() ) );
		}

		$query = new \WP_User_Query(
			array(
				'search'         => '*' . $q . '*',
				'search_columns' => array( 'user_login', 'user_email', 'user_nicename', 'display_name' ),
				'number'         => 10,
				'orderby'        => 'display_name',
				'order'          => 'ASC',
				'fields'         => array( 'ID', 'display_name', 'user_login', 'user_email' ),
			)
		);

		$items = array_map(
			static function ( $u ) {
				return array(
					'id'       => (int) $u->ID,
					'name'     => (string) $u->display_name,
					'username' => (string) $u->user_login,
					'email'    => (string) $u->user_email,
				);
			},
			$query->get_results()
		);

		return $this->ok( array( 'items' => $items ) );
	}

	/**
	 * GET /admin/points/pending.
	 *
	 * @return \WP_REST_Response
	 */
	public function pending(): \WP_REST_Response {
		$rows = array_map(
			static function ( $r ) {
				$user = get_userdata( (int) $r->user_id );
				return array(
					'id'         => (int) $r->id,
					'user_id'    => (int) $r->user_id,
					'user'       => $user ? $user->display_name : '',
					'points'     => (int) $r->points,
					'reason'     => (string) $r->reason,
					'source'     => (string) $r->source,
					'created_at' => (string) $r->created_at,
				);
			},
			$this->repo->pending( 200 )
		);
		return $this->ok( array( 'items' => $rows ) );
	}

	/**
	 * POST /admin/points/{id}/{approve|reject}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function decide( \WP_REST_Request $request ) {
		$id = absint( $request['id'] );
		$ok = ( 'approve' === (string) $request['decision'] ) ? $this->ledger->approve( $id ) : $this->ledger->reject( $id );
		if ( ! $ok ) {
			return $this->error( 'not_pending', __( 'That entry is not pending.', 'yazan-rewards' ), 400 );
		}
		return $this->ok( array( 'ok' => true, 'id' => $id, 'decision' => (string) $request['decision'] ) );
	}

	/**
	 * POST /admin/points/adjust — manual credit (positive) or debit (negative).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function adjust( \WP_REST_Request $request ) {
		$user_id = absint( $request->get_param( 'user_id' ) );
		$points  = (int) $request->get_param( 'points' );
		$reason  = sanitize_text_field( (string) $request->get_param( 'reason' ) );
		$pending = (bool) $request->get_param( 'pending' );

		if ( $user_id <= 0 || 0 === $points ) {
			return $this->error( 'bad_input', __( 'A user and a non-zero points amount are required.', 'yazan-rewards' ), 400 );
		}
		if ( '' === $reason ) {
			$reason = __( 'Manual adjustment', 'yazan-rewards' );
		}

		if ( $points > 0 ) {
			$id = $this->ledger->credit(
				$user_id,
				$points,
				'manual',
				get_current_user_id(),
				array( 'type' => 'adjust', 'reason' => $reason, 'note' => $reason, 'status' => $pending ? 'pending' : 'approved' )
			);
		} else {
			// Debits are always immediate (approved); pending only applies to credits.
			$id = $this->ledger->debit( $user_id, abs( $points ), 'manual', get_current_user_id(), array( 'type' => 'adjust', 'reason' => $reason, 'note' => $reason ) );
		}

		return $this->ok(
			array(
				'ok'      => $id > 0,
				'entry'   => $id,
				'balance' => $this->container->get( PointsLedgerInterface::class )->balance( $user_id ),
			),
			201
		);
	}
}
