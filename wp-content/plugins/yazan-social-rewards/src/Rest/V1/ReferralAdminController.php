<?php
/**
 * Admin referral controller.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Rest\V1;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Database\Database;
use Yazan\Rewards\Core\Rest\AbstractController;
use Yazan\Rewards\Core\Security\Capabilities;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Modules\Referral\ReferralEarningsRepository;
use Yazan\Rewards\Modules\Referral\ReferralRepository;
use Yazan\Rewards\Modules\Referral\ReferralRewardResolver;
use Yazan\Rewards\Modules\Referral\ReferralTree;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Referral management (admin, `manage_yazan_rewards`):
 *   GET  /admin/referrals                       — recent referrals + revenue.
 *   GET  /admin/referrals/tree/{user}           — a member's upline + downline.
 *   GET  /admin/referrals/earnings              — earnings (default: pending queue).
 *   POST /admin/referrals/earnings/{id}/approve — pay a held reward.
 *   POST /admin/referrals/earnings/{id}/reject  — decline a held reward.
 *   GET/POST /admin/referrals/settings          — reward-rule config.
 */
final class ReferralAdminController extends AbstractController {

	private ReferralRepository $repo;

	private ReferralEarningsRepository $earnings;

	private ReferralRewardResolver $resolver;

	private ReferralTree $tree;

	private Settings $settings;

	/**
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		parent::__construct( $container );
		$this->repo     = $container->get( ReferralRepository::class );
		$this->earnings = $container->get( ReferralEarningsRepository::class );
		$this->resolver = $container->get( ReferralRewardResolver::class );
		$this->tree     = $container->get( ReferralTree::class );
		$this->settings = $container->get( Settings::class );
	}

	/**
	 * @inheritDoc
	 */
	protected function base(): string {
		return 'admin/referrals';
	}

	/**
	 * @inheritDoc
	 */
	public function register_routes(): void {
		$manager = $this->auth->require_cap( Capabilities::MANAGE );
		$id_arg  = array( 'id' => array( 'sanitize_callback' => 'absint' ) );

		register_rest_route( $this->namespace, '/' . $this->base(), array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'index' ), 'permission_callback' => $manager ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/tree/(?P<user>\d+)', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'tree' ), 'permission_callback' => $manager, 'args' => array( 'user' => array( 'sanitize_callback' => 'absint' ) ) ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/earnings', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'earnings' ), 'permission_callback' => $manager ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/earnings/(?P<id>\d+)/approve', array(
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'approve' ), 'permission_callback' => $manager, 'args' => $id_arg ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/earnings/(?P<id>\d+)/reject', array(
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'reject' ), 'permission_callback' => $manager, 'args' => $id_arg ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/settings', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'get_settings' ), 'permission_callback' => $manager ),
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'save_settings' ), 'permission_callback' => $manager ),
		) );
	}

	/**
	 * GET /admin/referrals — recent referrals with names + revenue.
	 *
	 * @return \WP_REST_Response
	 */
	public function index(): \WP_REST_Response {
		$db    = $this->container->get( Database::class );
		$wpdb  = $db->wpdb();
		$table = $db->table( 'referrals' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 200" );

		$items = array_map(
			function ( $r ) {
				return array(
					'id'               => (int) $r->id,
					'referrer'         => $this->user_label( (int) $r->referrer_user_id ),
					'referred'         => $this->user_label( (int) $r->referred_user_id ),
					'status'           => (string) $r->status,
					'orders_count'     => (int) $r->orders_count,
					'revenue_total'    => (string) $r->revenue_total,
					'points_generated' => (int) $r->points_generated,
					'created_at'       => (string) $r->created_at,
				);
			},
			is_array( $rows ) ? $rows : array()
		);

		return $this->ok(
			array(
				'items'          => $items,
				'pending_payouts' => $this->earnings->pending_count(),
			)
		);
	}

	/**
	 * GET /admin/referrals/tree/{user}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function tree( \WP_REST_Request $request ): \WP_REST_Response {
		$user = absint( $request['user'] );
		return $this->ok(
			array(
				'user'     => $this->user_label( $user ),
				'upline'   => array_map( array( $this, 'user_label' ), $this->tree->upline( $user, 2 ) ),
				'downline' => array_map(
					fn( $ids ) => array_map( array( $this, 'user_label' ), $ids ),
					$this->tree->downline( $user, 2 )
				),
			)
		);
	}

	/**
	 * GET /admin/referrals/earnings?status=pending.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function earnings( \WP_REST_Request $request ): \WP_REST_Response {
		$rows  = $this->earnings->pending( 200 );
		$items = array_map(
			function ( $r ) {
				return array(
					'id'            => (int) $r->id,
					'beneficiary'   => $this->user_label( (int) $r->beneficiary_user_id ),
					'referred'      => $this->user_label( (int) $r->referred_user_id ),
					'level'         => (int) $r->level,
					'role'          => (string) $r->role,
					'order_id'      => (int) $r->order_id,
					'order_total'   => (string) $r->order_total,
					'reward_kind'   => (string) $r->reward_kind,
					'reward_amount' => (string) $r->reward_amount,
					'note'          => (string) $r->note,
					'created_at'    => (string) $r->created_at,
				);
			},
			$rows
		);
		return $this->ok( array( 'items' => $items ) );
	}

	/**
	 * POST /admin/referrals/earnings/{id}/approve.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function approve( \WP_REST_Request $request ) {
		$ok = $this->resolver->approve( absint( $request['id'] ) );
		return $ok ? $this->ok( array( 'approved' => true, 'id' => absint( $request['id'] ) ) )
			: $this->error( 'not_pending', __( 'That reward is not pending.', 'yazan-rewards' ), 409 );
	}

	/**
	 * POST /admin/referrals/earnings/{id}/reject.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function reject( \WP_REST_Request $request ) {
		$ok = $this->resolver->reject( absint( $request['id'] ) );
		return $ok ? $this->ok( array( 'rejected' => true, 'id' => absint( $request['id'] ) ) )
			: $this->error( 'not_pending', __( 'That reward is not pending.', 'yazan-rewards' ), 409 );
	}

	/**
	 * GET /admin/referrals/settings.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_settings(): \WP_REST_Response {
		return $this->ok( array( 'referral' => (array) $this->settings->get( 'referral', array() ) ) );
	}

	/**
	 * POST /admin/referrals/settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$in  = (array) $request->get_param( 'referral' );
		$cur = (array) $this->settings->get( 'referral', array() );

		$scalars = array(
			'cookie_days'     => 'absint',
			'max_levels'      => 'absint',
			'min_order_total' => 'floatval',
		);
		foreach ( $scalars as $key => $fn ) {
			if ( isset( $in[ $key ] ) ) {
				$cur[ $key ] = $fn( $in[ $key ] );
			}
		}
		// Enum settings: allow-list at write time (not only on read).
		if ( isset( $in['abuse_action'] ) ) {
			$action               = sanitize_key( (string) $in['abuse_action'] );
			$cur['abuse_action'] = in_array( $action, array( 'hold', 'block', 'flag' ), true ) ? $action : 'hold';
		}
		if ( isset( $in['trigger'] ) ) {
			$trigger         = sanitize_key( (string) $in['trigger'] );
			$cur['trigger'] = in_array( $trigger, array( 'first_order', 'every_order' ), true ) ? $trigger : 'first_order';
		}
		foreach ( array( 'referred', 'referrer', 'level2' ) as $spec_key ) {
			if ( isset( $in[ $spec_key ] ) && is_array( $in[ $spec_key ] ) ) {
				$spec              = $in[ $spec_key ];
				$kind              = sanitize_key( (string) ( $spec['kind'] ?? 'points' ) );
				$mode              = sanitize_key( (string) ( $spec['mode'] ?? 'fixed' ) );
				$cur[ $spec_key ] = array(
					'kind'  => in_array( $kind, array( 'points', 'credit' ), true ) ? $kind : 'points',
					'mode'  => in_array( $mode, array( 'fixed', 'percent' ), true ) ? $mode : 'fixed',
					'value' => max( 0.0, (float) ( $spec['value'] ?? 0 ) ),
				);
			}
		}

		$all             = $this->settings->all();
		$all['referral'] = $cur;
		$this->settings->save( $all );

		return $this->ok( array( 'referral' => $cur ) );
	}

	/**
	 * A compact { id, name } label for a user id.
	 *
	 * @param int $user_id User id.
	 * @return array{id:int,name:string}
	 */
	private function user_label( int $user_id ): array {
		$u = $user_id > 0 ? get_userdata( $user_id ) : false;
		return array(
			'id'   => $user_id,
			'name' => $u ? (string) $u->display_name : ( $user_id > 0 ? '#' . $user_id : '—' ),
		);
	}
}
