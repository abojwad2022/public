<?php
/**
 * Admin rewards catalog controller.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Rest\V1;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Rest\AbstractController;
use Yazan\Rewards\Core\Security\Capabilities;
use Yazan\Rewards\Modules\Rewards\Reward;
use Yazan\Rewards\Modules\Rewards\RewardRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * No-code rewards management (admin, `manage_yazan_rewards`):
 *   GET    /admin/rewards          — list all rewards.
 *   POST   /admin/rewards          — create.
 *   GET    /admin/rewards/{id}     — one.
 *   PUT    /admin/rewards/{id}     — update.
 *   DELETE /admin/rewards/{id}     — delete.
 *   GET    /admin/rewards/schema   — reward types + field hints (drives the UI).
 */
final class RewardsAdminController extends AbstractController {

	private RewardRepository $repo;

	/**
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		parent::__construct( $container );
		$this->repo = $container->get( RewardRepository::class );
	}

	/**
	 * @inheritDoc
	 */
	protected function base(): string {
		return 'admin/rewards';
	}

	/** Allowed reward types. */
	private function types(): array {
		return array(
			Reward::TYPE_CREDIT, Reward::TYPE_COUPON, Reward::TYPE_FREE_SHIPPING, Reward::TYPE_FREE_PRODUCT,
			Reward::TYPE_VIP_SERVICE, Reward::TYPE_RING_CLEANING, Reward::TYPE_RING_POLISHING, Reward::TYPE_EXCLUSIVE_PRODUCT,
		);
	}

	/**
	 * @inheritDoc
	 */
	public function register_routes(): void {
		$manager = $this->auth->require_cap( Capabilities::MANAGE );

		register_rest_route( $this->namespace, '/' . $this->base(), array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'index' ), 'permission_callback' => $manager ),
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'create' ), 'permission_callback' => $manager ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/schema', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'schema' ), 'permission_callback' => $manager ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/(?P<id>\d+)', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'show' ), 'permission_callback' => $manager, 'args' => array( 'id' => array( 'sanitize_callback' => 'absint' ) ) ),
			array( 'methods' => \WP_REST_Server::EDITABLE, 'callback' => array( $this, 'update' ), 'permission_callback' => $manager, 'args' => array( 'id' => array( 'sanitize_callback' => 'absint' ) ) ),
			array( 'methods' => \WP_REST_Server::DELETABLE, 'callback' => array( $this, 'destroy' ), 'permission_callback' => $manager, 'args' => array( 'id' => array( 'sanitize_callback' => 'absint' ) ) ),
		) );
	}

	/**
	 * GET /admin/rewards.
	 *
	 * @return \WP_REST_Response
	 */
	public function index(): \WP_REST_Response {
		$wpdb  = $this->container->get( \Yazan\Rewards\Core\Database\Database::class )->wpdb();
		$table = $this->container->get( \Yazan\Rewards\Core\Database\Database::class )->table( 'rewards' );
		/*
		 * BOUNDED. This was an unbounded `SELECT *` over the whole rewards catalogue — the only
		 * query in this plugin that had no LIMIT at all. It is fine at four rows and becomes a
		 * memory-exhaustion vector the day a store bulk-imports a catalogue, on a route that is
		 * merely `manage_yazan_rewards` rather than administrator.
		 *
		 * The cap is deliberately far above any plausible real catalogue, so this changes nothing
		 * observable today; it just stops the failure mode from being unbounded. The sibling
		 * endpoint (ReferralAdminController::index) already caps at 200.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY sort ASC, id DESC LIMIT 1000" );
		$items = array_map( static fn( $r ) => Reward::from_row( $r )->to_array(), is_array( $rows ) ? $rows : array() );
		return $this->ok( array( 'items' => $items ) );
	}

	/**
	 * GET /admin/rewards/schema.
	 *
	 * @return \WP_REST_Response
	 */
	public function schema(): \WP_REST_Response {
		return $this->ok(
			array(
				'types' => array(
					array( 'value' => Reward::TYPE_CREDIT, 'label' => __( 'Store credit', 'yazan-rewards' ), 'fields' => array( 'amount' ) ),
					array( 'value' => Reward::TYPE_COUPON, 'label' => __( 'Discount coupon', 'yazan-rewards' ), 'fields' => array( 'discount_type', 'amount', 'expiry_days' ) ),
					array( 'value' => Reward::TYPE_FREE_SHIPPING, 'label' => __( 'Free shipping', 'yazan-rewards' ), 'fields' => array( 'expiry_days' ) ),
					array( 'value' => Reward::TYPE_FREE_PRODUCT, 'label' => __( 'Free product', 'yazan-rewards' ), 'fields' => array( 'product_ids', 'qty', 'expiry_days' ) ),
					array( 'value' => Reward::TYPE_VIP_SERVICE, 'label' => __( 'VIP service', 'yazan-rewards' ), 'fields' => array() ),
					array( 'value' => Reward::TYPE_RING_CLEANING, 'label' => __( 'Ring cleaning', 'yazan-rewards' ), 'fields' => array() ),
					array( 'value' => Reward::TYPE_RING_POLISHING, 'label' => __( 'Ring polishing', 'yazan-rewards' ), 'fields' => array() ),
					array( 'value' => Reward::TYPE_EXCLUSIVE_PRODUCT, 'label' => __( 'Exclusive product', 'yazan-rewards' ), 'fields' => array() ),
				),
			)
		);
	}

	/**
	 * GET /admin/rewards/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function show( \WP_REST_Request $request ) {
		$reward = $this->repo->get( absint( $request['id'] ) );
		return $reward ? $this->ok( $reward->to_array() ) : $this->error( 'not_found', __( 'Reward not found.', 'yazan-rewards' ), 404 );
	}

	/**
	 * POST /admin/rewards.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create( \WP_REST_Request $request ) {
		$data = $this->validate( $request, false );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$id = $this->repo->create( $data );
		return $id ? $this->ok( $this->repo->get( $id )->to_array(), 201 ) : $this->error( 'create_failed', __( 'Could not save the reward.', 'yazan-rewards' ), 500 );
	}

	/**
	 * PUT /admin/rewards/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update( \WP_REST_Request $request ) {
		$id = absint( $request['id'] );
		if ( ! $this->repo->get( $id ) ) {
			return $this->error( 'not_found', __( 'Reward not found.', 'yazan-rewards' ), 404 );
		}
		$data = $this->validate( $request, true );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$this->repo->update_reward( $id, $data );
		return $this->ok( $this->repo->get( $id )->to_array() );
	}

	/**
	 * DELETE /admin/rewards/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function destroy( \WP_REST_Request $request ): \WP_REST_Response {
		$id = absint( $request['id'] );
		$this->repo->delete_reward( $id );
		return $this->ok( array( 'deleted' => true, 'id' => $id ) );
	}

	/**
	 * Validate + sanitise a reward payload.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param bool             $partial Update mode.
	 * @return array|\WP_Error
	 */
	private function validate( \WP_REST_Request $request, bool $partial ) {
		$out = array();

		$type = $request->get_param( 'type' );
		if ( null !== $type ) {
			$type = sanitize_key( (string) $type );
			if ( ! in_array( $type, $this->types(), true ) ) {
				return $this->error( 'bad_type', __( 'Unknown reward type.', 'yazan-rewards' ), 400 );
			}
			$out['type'] = $type;
		} elseif ( ! $partial ) {
			return $this->error( 'missing_type', __( 'A reward type is required.', 'yazan-rewards' ), 400 );
		}

		if ( null !== $request->get_param( 'title' ) ) {
			$out['title'] = sanitize_text_field( (string) $request->get_param( 'title' ) );
		} elseif ( ! $partial ) {
			return $this->error( 'missing_title', __( 'A title is required.', 'yazan-rewards' ), 400 );
		}
		if ( null !== $request->get_param( 'description' ) ) {
			$out['description'] = sanitize_textarea_field( (string) $request->get_param( 'description' ) );
		}
		foreach ( array( 'cost_points', 'stock', 'tier_min', 'sort', 'per_user_limit' ) as $intk ) {
			if ( null !== $request->get_param( $intk ) ) {
				$out[ $intk ] = (int) $request->get_param( $intk );
			}
		}
		if ( null !== $request->get_param( 'active' ) ) {
			$out['active'] = (bool) $request->get_param( 'active' );
		}
		foreach ( array( 'available_from', 'available_to' ) as $dt ) {
			if ( null !== $request->get_param( $dt ) ) {
				$out[ $dt ] = sanitize_text_field( (string) $request->get_param( $dt ) );
			}
		}
		if ( null !== $request->get_param( 'value' ) ) {
			$value = (array) $request->get_param( 'value' );
			$clean = array();
			foreach ( $value as $k => $v ) {
				$key = sanitize_key( (string) $k );
				if ( 'product_ids' === $key ) {
					$clean[ $key ] = array_values( array_filter( array_map( 'absint', (array) $v ) ) );
				} elseif ( is_array( $v ) ) {
					$clean[ $key ] = array_map( 'sanitize_text_field', $v );
				} else {
					$clean[ $key ] = is_numeric( $v ) ? $v + 0 : sanitize_text_field( (string) $v );
				}
			}
			$out['value'] = $clean;
		}

		return $out;
	}
}
