<?php
/**
 * Referral REST controller.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Rest\V1;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Rest\AbstractController;
use Yazan\Rewards\Modules\Referral\ReferralCodes;
use Yazan\Rewards\Modules\Referral\ReferralEarningsRepository;
use Yazan\Rewards\Modules\Referral\ReferralRepository;
use Yazan\Rewards\Modules\Referral\ReferralTree;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GET /referral — the caller's personal referral code, shareable link, signup/
 * conversion stats, generated revenue, their 2-level referral tree counts, and
 * their referral earnings (including any held pending review).
 */
final class ReferralController extends AbstractController {

	private ReferralCodes $codes;

	private ReferralRepository $repo;

	private ReferralTree $tree;

	private ReferralEarningsRepository $earnings;

	/**
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		parent::__construct( $container );
		$this->codes    = $container->get( ReferralCodes::class );
		$this->repo     = $container->get( ReferralRepository::class );
		$this->tree     = $container->get( ReferralTree::class );
		$this->earnings = $container->get( ReferralEarningsRepository::class );
	}

	/**
	 * @inheritDoc
	 */
	protected function base(): string {
		return 'referral';
	}

	/**
	 * @inheritDoc
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->base(),
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'me' ),
					'permission_callback' => $this->auth->require_customer(),
				),
			)
		);
	}

	/**
	 * GET /referral.
	 *
	 * @return \WP_REST_Response
	 */
	public function me(): \WP_REST_Response {
		$user_id = get_current_user_id();
		$code    = $this->codes->for_user( $user_id );
		$totals  = $this->earnings->totals_for( $user_id );

		return $this->ok(
			array(
				'code'     => $code,
				'link'     => add_query_arg( 'ref', rawurlencode( $code ), home_url( '/' ) ),
				'stats'    => $this->repo->stats_for_referrer( $user_id ),
				'tree'     => $this->tree->downline_counts( $user_id, 2 ),
				'earnings' => array(
					'points_total'  => (int) $totals['points'],
					'credit_total'  => (string) $totals['credit'],
					'pending_count' => (int) $totals['pending'],
					'items'         => array_map(
						static function ( $r ) {
							return array(
								'level'         => (int) $r->level,
								'role'          => (string) $r->role,
								'reward_kind'   => (string) $r->reward_kind,
								'reward_amount' => (string) $r->reward_amount,
								'status'        => (string) $r->status,
								'created_at'    => (string) $r->created_at,
							);
						},
						$this->earnings->for_beneficiary( $user_id, 20 )
					),
				),
			)
		);
	}
}
