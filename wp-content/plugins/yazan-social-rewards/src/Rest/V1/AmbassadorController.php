<?php
/**
 * Ambassador REST controller.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Rest\V1;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Rest\AbstractController;
use Yazan\Rewards\Core\Security\Capabilities;
use Yazan\Rewards\Modules\Ambassador\ApplicationService;
use Yazan\Rewards\Modules\Ambassador\AmbassadorRepository;
use Yazan\Rewards\Modules\Referral\ReferralRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customer:
 *   GET  /ambassador/me      — the caller's ambassador status, code, link, stats.
 *   POST /ambassador/apply   — apply to the programme.
 * Admin (manage_yazan_rewards):
 *   POST /ambassador/{id}/approve
 *   POST /ambassador/{id}/suspend
 */
final class AmbassadorController extends AbstractController {

	private ApplicationService $service;

	private AmbassadorRepository $repo;

	private ReferralRepository $referrals;

	/**
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		parent::__construct( $container );
		$this->service   = $container->get( ApplicationService::class );
		$this->repo      = $container->get( AmbassadorRepository::class );
		$this->referrals = $container->get( ReferralRepository::class );
	}

	/**
	 * @inheritDoc
	 */
	protected function base(): string {
		return 'ambassador';
	}

	/**
	 * @inheritDoc
	 */
	public function register_routes(): void {
		$customer = $this->auth->require_customer();
		$manager  = $this->auth->require_cap( Capabilities::MANAGE );

		register_rest_route(
			$this->namespace,
			'/' . $this->base() . '/me',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'me' ),
					'permission_callback' => $customer,
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->base() . '/apply',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'apply' ),
					'permission_callback' => $customer,
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->base() . '/(?P<id>\d+)/approve',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'approve' ),
					'permission_callback' => $manager,
					'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->base() . '/(?P<id>\d+)/suspend',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'suspend' ),
					'permission_callback' => $manager,
					'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
				),
			)
		);
	}

	/**
	 * GET /ambassador/me.
	 *
	 * @return \WP_REST_Response
	 */
	public function me(): \WP_REST_Response {
		$user_id    = get_current_user_id();
		$ambassador = $this->repo->get_by_user( $user_id );

		if ( ! $ambassador ) {
			return $this->ok( array( 'is_ambassador' => false, 'status' => 'none' ) );
		}

		$data                  = $ambassador->to_array();
		$data['is_ambassador'] = true;
		$data['link']          = add_query_arg( 'ref', rawurlencode( $ambassador->code ), home_url( '/' ) );
		$data['referral_stats'] = $this->referrals->stats_for_referrer( $user_id );

		return $this->ok( $data );
	}

	/**
	 * POST /ambassador/apply.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function apply() {
		$result = $this->service->apply( get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->ok( $result->to_array() );
	}

	/**
	 * POST /ambassador/{id}/approve.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function approve( \WP_REST_Request $request ) {
		$result = $this->service->approve( absint( $request['id'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->ok( $result->to_array() );
	}

	/**
	 * POST /ambassador/{id}/suspend.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function suspend( \WP_REST_Request $request ) {
		$result = $this->service->suspend( absint( $request['id'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->ok( $result->to_array() );
	}
}
