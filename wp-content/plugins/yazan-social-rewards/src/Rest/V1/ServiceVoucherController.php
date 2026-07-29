<?php
/**
 * Service voucher REST controller.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Rest\V1;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Events\Events;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Events\GenericEvent;
use Yazan\Rewards\Core\Rest\AbstractController;
use Yazan\Rewards\Core\Security\Capabilities;
use Yazan\Rewards\Modules\Rewards\ServiceVoucherRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service-reward vouchers.
 * Customer: GET /service-vouchers/me — the caller's vouchers.
 * Admin (manage_yazan_rewards):
 *   GET  /service-vouchers          — the open fulfillment queue.
 *   POST /service-vouchers/{id}/status  — transition (scheduled/fulfilled/cancelled).
 */
final class ServiceVoucherController extends AbstractController {

	private ServiceVoucherRepository $repo;

	private EventBus $bus;

	/**
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		parent::__construct( $container );
		$this->repo = $container->get( ServiceVoucherRepository::class );
		$this->bus  = $container->get( EventBus::class );
	}

	/**
	 * @inheritDoc
	 */
	protected function base(): string {
		return 'service-vouchers';
	}

	/**
	 * @inheritDoc
	 */
	public function register_routes(): void {
		$manager = $this->auth->require_cap( Capabilities::MANAGE );

		register_rest_route( $this->namespace, '/' . $this->base(), array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'queue' ), 'permission_callback' => $manager ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/me', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'mine' ), 'permission_callback' => $this->auth->require_customer() ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/(?P<id>\d+)/status', array(
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'set_status' ),
				'permission_callback' => $manager,
				'args'                => array(
					'id'     => array( 'sanitize_callback' => 'absint' ),
					'status' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
				),
			),
		) );
	}

	/**
	 * GET /service-vouchers (admin queue).
	 *
	 * @return \WP_REST_Response
	 */
	public function queue(): \WP_REST_Response {
		$items = array_map(
			static function ( $v ) {
				$user = get_userdata( (int) $v->user_id );
				return array(
					'id'         => (int) $v->id,
					'user'       => $user ? $user->display_name : '',
					'user_id'    => (int) $v->user_id,
					'type'       => (string) $v->type,
					'code'       => (string) $v->code,
					'status'     => (string) $v->status,
					'notes'      => (string) $v->notes,
					'created_at' => (string) $v->created_at,
				);
			},
			$this->repo->open( 200 )
		);
		return $this->ok( array( 'items' => $items ) );
	}

	/**
	 * GET /service-vouchers/me.
	 *
	 * @return \WP_REST_Response
	 */
	public function mine(): \WP_REST_Response {
		return $this->ok( array( 'items' => $this->repo->for_user( get_current_user_id(), 50 ) ) );
	}

	/**
	 * POST /service-vouchers/{id}/status.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function set_status( \WP_REST_Request $request ) {
		$id     = absint( $request['id'] );
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$allowed = array(
			ServiceVoucherRepository::STATUS_SCHEDULED,
			ServiceVoucherRepository::STATUS_FULFILLED,
			ServiceVoucherRepository::STATUS_CANCELLED,
		);
		if ( ! in_array( $status, $allowed, true ) ) {
			return $this->error( 'bad_status', __( 'Invalid status.', 'yazan-rewards' ), 400 );
		}
		$voucher = $this->repo->get( $id );
		if ( ! $voucher ) {
			return $this->error( 'not_found', __( 'Voucher not found.', 'yazan-rewards' ), 404 );
		}

		$this->repo->set_status( $id, $status );

		if ( ServiceVoucherRepository::STATUS_FULFILLED === $status ) {
			$this->bus->dispatch(
				new GenericEvent(
					Events::SERVICE_FULFILLED,
					array( 'user_id' => (int) $voucher->user_id, 'voucher_id' => $id, 'type' => (string) $voucher->type, 'code' => (string) $voucher->code )
				)
			);
		}

		return $this->ok( array( 'ok' => true, 'id' => $id, 'status' => $status ) );
	}
}
