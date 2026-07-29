<?php
/**
 * Public customer endpoints.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Rest\PublicV1;

use Yazan\Rewards\Core\Contracts\PointsLedgerInterface;
use Yazan\Rewards\Core\Contracts\WalletServiceInterface;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Rest\V1\MeController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GET /yazan/v1/customer/points   — the caller's points balance (+ store credit).
 * GET /yazan/v1/customer/profile  — the caller's full rewards profile.
 * Both require a logged-in user and only ever expose the caller's own data.
 */
final class CustomerController extends AbstractPublicController {

	/**
	 * @inheritDoc
	 */
	protected function base(): string {
		return 'customer';
	}

	/**
	 * @inheritDoc
	 */
	public function register_routes(): void {
		$auth = $this->auth->require_customer();

		register_rest_route( $this->namespace, '/' . $this->base() . '/points', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'points' ), 'permission_callback' => $auth ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/profile', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'profile' ), 'permission_callback' => $auth ),
		) );
	}

	/**
	 * GET /customer/points.
	 *
	 * @return \WP_REST_Response
	 */
	public function points(): \WP_REST_Response {
		$user_id  = get_current_user_id();
		$points   = $this->container->get( PointsLedgerInterface::class );
		$settings = $this->container->get( Settings::class );
		$wallet   = $this->container->has( WalletServiceInterface::class ) ? $this->container->get( WalletServiceInterface::class ) : null;

		return $this->ok( array(
			'user_id' => $user_id,
			'balance' => $points->balance( $user_id ),
			'label'   => array(
				'singular' => (string) $settings->get( 'currency_name_singular', 'Point' ),
				'plural'   => (string) $settings->get( 'currency_name_plural', 'Points' ),
			),
			'wallet'  => array(
				'enabled' => null !== $wallet,
				'balance' => $wallet ? $wallet->balance( $user_id ) : '0',
			),
		) );
	}

	/**
	 * GET /customer/profile — reuses the tested `/me` assembly (balance, wallet,
	 * tier, membership level, ranking).
	 *
	 * @return \WP_REST_Response
	 */
	public function profile(): \WP_REST_Response {
		return ( new MeController( $this->container ) )->me();
	}
}
