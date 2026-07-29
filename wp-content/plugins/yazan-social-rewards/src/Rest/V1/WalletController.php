<?php
/**
 * Wallet REST controller (checkout credit).
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Rest\V1;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Contracts\WalletServiceInterface;
use Yazan\Rewards\Core\Rest\AbstractController;
use Yazan\Rewards\Core\Support\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * POST /wallet/apply — choose how much store credit to apply to the cart. The
 * amount is stored in the WooCommerce session and validated against the live
 * balance + cart on every recalculation, so this endpoint only records intent.
 */
final class WalletController extends AbstractController {

	private WalletServiceInterface $wallet;

	private Money $money;

	/**
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		parent::__construct( $container );
		$this->wallet = $container->get( WalletServiceInterface::class );
		$this->money  = $container->get( Money::class );
	}

	/**
	 * @inheritDoc
	 */
	protected function base(): string {
		return 'wallet';
	}

	/**
	 * @inheritDoc
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->base() . '/apply',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'apply' ),
					'permission_callback' => $this->auth->require_customer(),
					'args'                => array(
						'amount' => array(
							'required' => true,
							'type'     => 'number',
						),
					),
				),
			)
		);
	}

	/**
	 * POST /wallet/apply.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function apply( \WP_REST_Request $request ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return $this->error( 'no_session', __( 'No active cart session.', 'yazan-rewards' ), 400 );
		}

		$user_id = get_current_user_id();
		$amount  = max( 0.0, (float) $request->get_param( 'amount' ) );
		$balance = (float) $this->wallet->balance( $user_id );

		// Cap the requested amount at the balance; deeper cart/percent capping is
		// applied at fee-calculation time by CheckoutCredit.
		$amount = min( $amount, $balance );

		WC()->session->set( \Yazan\Rewards\Modules\Wallet\CheckoutCredit::SESSION_KEY, $this->money->format( $amount ) );

		return $this->ok(
			array(
				'applied'  => $this->money->format( $amount ),
				'balance'  => $this->money->format( $balance ),
			)
		);
	}
}
