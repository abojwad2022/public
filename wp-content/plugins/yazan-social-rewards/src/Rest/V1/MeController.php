<?php
/**
 * Customer "me" REST controller.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Rest\V1;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Contracts\PointsLedgerInterface;
use Yazan\Rewards\Core\Contracts\WalletServiceInterface;
use Yazan\Rewards\Core\Rest\AbstractController;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Modules\Points\PointsRepository;
use Yazan\Rewards\Modules\Wallet\WalletRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GET /me           — the signed-in customer's balances + tier + currency labels.
 * GET /me/history   — paginated points ledger.
 * GET /me/wallet    — paginated wallet ledger.
 *
 * Read-only; requires a logged-in customer (cookie + wp_rest nonce). No admin
 * capability needed — a customer sees only their own data.
 */
final class MeController extends AbstractController {

	private PointsLedgerInterface $points;

	private ?WalletServiceInterface $wallet;

	private Settings $settings;

	/**
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		parent::__construct( $container );
		$this->points   = $container->get( PointsLedgerInterface::class );
		$this->settings = $container->get( Settings::class );
		$this->wallet   = $container->has( WalletServiceInterface::class ) ? $container->get( WalletServiceInterface::class ) : null;
	}

	/**
	 * @inheritDoc
	 */
	protected function base(): string {
		return 'me';
	}

	/**
	 * @inheritDoc
	 */
	public function register_routes(): void {
		$auth = $this->auth->require_customer();

		register_rest_route(
			$this->namespace,
			'/' . $this->base(),
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'me' ),
					'permission_callback' => $auth,
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->base() . '/history',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'history' ),
					'permission_callback' => $auth,
					'args'                => $this->pagination_args(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->base() . '/wallet',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'wallet_history' ),
					'permission_callback' => $auth,
					'args'                => $this->pagination_args(),
				),
			)
		);
	}

	/**
	 * GET /me.
	 *
	 * @return \WP_REST_Response
	 */
	public function me(): \WP_REST_Response {
		$user_id = get_current_user_id();

		return $this->ok(
			array(
				'user_id'  => $user_id,
				'points'   => array(
					'balance' => $this->points->balance( $user_id ),
					'label'   => array(
						'singular' => (string) $this->settings->get( 'currency_name_singular', 'Point' ),
						'plural'   => (string) $this->settings->get( 'currency_name_plural', 'Points' ),
					),
				),
				'wallet'   => array(
					'enabled' => null !== $this->wallet,
					'balance' => $this->wallet ? $this->wallet->balance( $user_id ) : '0',
					'balance_html' => $this->wallet && function_exists( 'wc_price' )
						? wp_strip_all_tags( wc_price( (float) $this->wallet->balance( $user_id ) ) )
						: '',
				),
				// Cached loyalty tier (written by the Achievement engine's TierEngine;
				// read from user meta to avoid a hard dependency on that module).
				'tier'     => array(
					'slug' => (string) get_user_meta( $user_id, '_yzrw_tier', true ),
					'name' => (string) get_user_meta( $user_id, '_yzrw_tier_name', true ),
				),
				// Full membership level (badge/color/discount + next-level progress),
				// driven by lifetime EARNED points. Optional — absent if achievements off.
				'level'    => $this->level( $user_id ),
				'ranking'  => $this->ranking( $user_id ),
			)
		);
	}

	/**
	 * Full level detail via the tier engine, or null when unavailable.
	 *
	 * @param int $user_id User id.
	 * @return array<string,mixed>|null
	 */
	private function level( int $user_id ): ?array {
		$engine = \Yazan\Rewards\Modules\Achievement\TierEngine::class;
		return $this->container->has( $engine ) ? $this->container->get( $engine )->level( $user_id ) : null;
	}

	/**
	 * Cached leaderboard ranking by lifetime points.
	 *
	 * @param int $user_id User id.
	 * @return array{rank:int,total:int,lifetime:int}
	 */
	private function ranking( int $user_id ): array {
		$key    = 'yzrw_rank_' . $user_id;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$rank = $this->container->get( PointsRepository::class )->rank_by_lifetime( $user_id );
		set_transient( $key, $rank, 10 * MINUTE_IN_SECONDS );
		return $rank;
	}

	/**
	 * GET /me/history.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function history( \WP_REST_Request $request ): \WP_REST_Response {
		$repo = $this->container->get( PointsRepository::class );
		$data = $repo->history(
			get_current_user_id(),
			absint( $request->get_param( 'per_page' ) ?: 20 ),
			absint( $request->get_param( 'page' ) ?: 1 )
		);
		return $this->ok( $data );
	}

	/**
	 * GET /me/wallet.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function wallet_history( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->container->has( WalletRepository::class ) ) {
			return $this->ok( array( 'items' => array(), 'total' => 0, 'total_pages' => 0, 'page' => 1, 'per_page' => 20 ) );
		}
		$repo = $this->container->get( WalletRepository::class );
		$data = $repo->history(
			get_current_user_id(),
			absint( $request->get_param( 'per_page' ) ?: 20 ),
			absint( $request->get_param( 'page' ) ?: 1 )
		);
		return $this->ok( $data );
	}

	/**
	 * Shared pagination arg schema.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function pagination_args(): array {
		return array(
			'page'     => array( 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ),
			'per_page' => array( 'type' => 'integer', 'default' => 20, 'sanitize_callback' => 'absint' ),
		);
	}
}
