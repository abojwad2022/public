<?php
/**
 * Achievements REST controller.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Rest\V1;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Rest\AbstractController;
use Yazan\Rewards\Modules\Achievement\AchievementRepository;
use Yazan\Rewards\Modules\Achievement\TierEngine;
use Yazan\Rewards\Modules\Achievement\TierRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GET /achievements — the caller's badges (unlocked + locked), current tier, and
 * the tier ladder.
 */
final class AchievementsController extends AbstractController {

	private AchievementRepository $repo;

	private TierEngine $tiers;

	private TierRepository $tier_repo;

	/**
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		parent::__construct( $container );
		$this->repo      = $container->get( AchievementRepository::class );
		$this->tiers     = $container->get( TierEngine::class );
		$this->tier_repo = $container->get( TierRepository::class );
	}

	/**
	 * @inheritDoc
	 */
	protected function base(): string {
		return 'achievements';
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
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $this->auth->require_customer(),
				),
			)
		);
	}

	/**
	 * GET /achievements.
	 *
	 * @return \WP_REST_Response
	 */
	public function index(): \WP_REST_Response {
		$user_id  = get_current_user_id();
		$unlocked = array_flip( $this->repo->unlocked_ids( $user_id ) );

		$badges = array();
		foreach ( $this->repo->all() as $a ) {
			$badges[] = array(
				'key'          => (string) $a->akey,
				'name'         => (string) $a->name,
				'description'  => (string) $a->description,
				'icon'         => (string) $a->icon,
				'points_award' => (int) $a->points_award,
				'unlocked'     => isset( $unlocked[ (int) $a->id ] ),
			);
		}

		$ladder = array();
		foreach ( $this->tier_repo->all() as $t ) {
			$ladder[] = array(
				'slug'       => (string) $t->slug,
				'name'       => (string) $t->name,
				'threshold'  => (int) $t->threshold_points,
				'multiplier' => (float) $t->multiplier,
			);
		}

		return $this->ok(
			array(
				'tier'         => $this->tiers->current( $user_id ),
				'tier_ladder'  => $ladder,
				'achievements' => $badges,
			)
		);
	}
}
