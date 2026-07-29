<?php
/**
 * Achievement + tier engine module.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Achievement;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Contracts\Installable;
use Yazan\Rewards\Core\Contracts\PointsLedgerInterface;
use Yazan\Rewards\Core\Database\Database;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Hooks\HookLoader;
use Yazan\Rewards\Core\Module\AbstractModule;
use Yazan\Rewards\Core\Rest\RestBootstrap;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Core\Support\Money;
use Yazan\Rewards\Modules\Points\PointsRepository;
use Yazan\Rewards\Rest\V1\AchievementsController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the `achievements`, `user_achievements`, and `tiers` tables, the loyalty
 * tier engine, and the achievement progress tracker. Depends on Points.
 */
final class AchievementModule extends AbstractModule implements Installable {

	/**
	 * @inheritDoc
	 */
	public function id(): string {
		return 'achievement';
	}

	/**
	 * @inheritDoc
	 */
	public function dependencies(): array {
		return array( 'points' );
	}

	/**
	 * @inheritDoc
	 */
	public function register( Container $c ): void {
		$c->singleton( TierRepository::class, static fn( Container $c ) => new TierRepository( $c->get( Database::class ) ) );
		$c->singleton( AchievementRepository::class, static fn( Container $c ) => new AchievementRepository( $c->get( Database::class ) ) );

		$c->singleton(
			TierEngine::class,
			static fn( Container $c ) => new TierEngine(
				$c->get( TierRepository::class ),
				$c->get( PointsRepository::class ),
				$c->get( EventBus::class ),
				$c->get( Settings::class ),
				$c->get( Money::class )
			)
		);

		$c->singleton(
			ProgressTracker::class,
			static fn( Container $c ) => new ProgressTracker(
				$c->get( AchievementRepository::class ),
				$c->get( PointsLedgerInterface::class ),
				$c->get( EventBus::class ),
				$c->get( Settings::class )
			)
		);

		$c->singleton( TierDiscount::class, static fn( Container $c ) => new TierDiscount( $c->get( TierEngine::class ), $c->get( Settings::class ), $c->get( Money::class ) ) );
	}

	/**
	 * @inheritDoc
	 */
	public function boot( Container $c ): void {
		if ( ! $c->get( Settings::class )->feature_enabled( 'achievement' ) ) {
			return;
		}
		$tier = $c->get( TierEngine::class );
		$c->get( HookLoader::class )->register( $tier );        // Tier multiplier filter.
		$c->get( EventBus::class )->subscribe( $tier );          // Recalc on credit.
		$c->get( EventBus::class )->subscribe( $c->get( ProgressTracker::class ) );
		$c->get( RestBootstrap::class )->add( static fn( Container $c ) => new AchievementsController( $c ) );

		// Member-level checkout discount.
		$c->get( HookLoader::class )->register( $c->get( TierDiscount::class ) );
	}

	/**
	 * @inheritDoc
	 */
	public function activate( Container $c ): void {
		$this->seed_tiers( $c );
		$this->seed_achievements( $c );
	}

	/**
	 * The four membership levels with badge/color/discount/benefits. Levels are
	 * reached by lifetime EARNED points (threshold_points).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function level_defaults(): array {
		return array(
			array(
				'name' => __( 'Bronze', 'yazan-rewards' ), 'slug' => 'bronze', 'threshold_points' => 0,
				'multiplier' => 1.0, 'sort' => 10, 'badge' => 'bronze', 'color' => '#A9714B', 'discount_percent' => 0,
				'benefits' => array( __( 'Earn points on every order', 'yazan-rewards' ), __( 'Access member rewards', 'yazan-rewards' ) ),
			),
			array(
				'name' => __( 'Silver', 'yazan-rewards' ), 'slug' => 'silver', 'threshold_points' => 1000,
				'multiplier' => 1.1, 'sort' => 20, 'badge' => 'silver', 'color' => '#A8B0B8', 'discount_percent' => 5,
				'benefits' => array( __( '1.1× points on purchases', 'yazan-rewards' ), __( '5% member discount', 'yazan-rewards' ) ),
			),
			array(
				'name' => __( 'Gold', 'yazan-rewards' ), 'slug' => 'gold', 'threshold_points' => 5000,
				'multiplier' => 1.25, 'sort' => 30, 'badge' => 'gold', 'color' => '#C9A24B', 'discount_percent' => 10,
				'benefits' => array( __( '1.25× points on purchases', 'yazan-rewards' ), __( '10% member discount', 'yazan-rewards' ), __( 'Priority support', 'yazan-rewards' ) ),
			),
			array(
				'name' => __( 'Platinum', 'yazan-rewards' ), 'slug' => 'platinum', 'threshold_points' => 15000,
				'multiplier' => 1.5, 'sort' => 40, 'badge' => 'platinum', 'color' => '#8E9AAF', 'discount_percent' => 15,
				'benefits' => array( __( '1.5× points on purchases', 'yazan-rewards' ), __( '15% member discount', 'yazan-rewards' ), __( 'VIP concierge & early access', 'yazan-rewards' ) ),
			),
		);
	}

	/**
	 * Seed the loyalty tiers, or (if they already exist) sync the presentation +
	 * benefit fields onto them — idempotent, so an upgrade backfills badge/color/
	 * discount without disturbing thresholds set by an admin.
	 *
	 * @param Container $c Container.
	 * @return void
	 */
	private function seed_tiers( Container $c ): void {
		$repo   = $c->get( TierRepository::class );
		$levels = $this->level_defaults();

		if ( $repo->count_all() > 0 ) {
			foreach ( $levels as $t ) {
				if ( $repo->find_by_slug( $t['slug'] ) ) {
					$repo->update_by_slug( $t['slug'], array( 'badge' => $t['badge'], 'color' => $t['color'], 'discount_percent' => $t['discount_percent'], 'benefits' => $t['benefits'] ) );
				}
			}
			return;
		}
		foreach ( $levels as $t ) {
			$repo->create( $t );
		}
	}

	/**
	 * Seed default achievements.
	 *
	 * @param Container $c Container.
	 * @return void
	 */
	private function seed_achievements( Container $c ): void {
		$repo = $c->get( AchievementRepository::class );
		if ( $repo->count_all() > 0 ) {
			return;
		}
		$defs = array(
			array( 'key' => 'first_purchase', 'name' => __( 'First Purchase', 'yazan-rewards' ), 'description' => __( 'Placed your first order.', 'yazan-rewards' ), 'criteria' => array( 'event' => 'order_rewarded', 'threshold' => 1 ), 'points_award' => 100, 'badge' => 'first-purchase' ),
			array( 'key' => 'loyal_five', 'name' => __( 'Loyal Five', 'yazan-rewards' ), 'description' => __( 'Completed five orders.', 'yazan-rewards' ), 'criteria' => array( 'event' => 'order_rewarded', 'threshold' => 5 ), 'points_award' => 300, 'badge' => 'loyal-five' ),
			array( 'key' => 'connoisseur', 'name' => __( 'Connoisseur', 'yazan-rewards' ), 'description' => __( 'Placed an order over $500.', 'yazan-rewards' ), 'criteria' => array( 'event' => 'order_rewarded', 'threshold' => 1, 'min_total' => 500 ), 'points_award' => 250, 'badge' => 'connoisseur' ),
			array( 'key' => 'reviewer', 'name' => __( 'Reviewer', 'yazan-rewards' ), 'description' => __( 'Wrote your first review.', 'yazan-rewards' ), 'criteria' => array( 'event' => 'review_created', 'threshold' => 1 ), 'points_award' => 50, 'badge' => 'reviewer' ),
			array( 'key' => 'connector', 'name' => __( 'Connector', 'yazan-rewards' ), 'description' => __( 'Referred a friend who ordered.', 'yazan-rewards' ), 'criteria' => array( 'event' => 'referral_converted', 'threshold' => 1 ), 'points_award' => 150, 'badge' => 'connector' ),
			array( 'key' => 'ambassador', 'name' => __( 'Ambassador', 'yazan-rewards' ), 'description' => __( 'Became a Yazan ambassador.', 'yazan-rewards' ), 'criteria' => array( 'event' => 'ambassador_approved', 'threshold' => 1 ), 'points_award' => 200, 'badge' => 'ambassador' ),
		);
		foreach ( $defs as $d ) {
			$repo->create_definition( $d );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function schema( string $charset_collate ): array {
		global $wpdb;
		$prefix           = $wpdb->prefix . Database::TABLE_PREFIX;
		$achievements     = $prefix . 'achievements';
		$user_achievements = $prefix . 'user_achievements';
		$tiers            = $prefix . 'tiers';

		return array(
			"CREATE TABLE {$achievements} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				akey varchar(60) NOT NULL DEFAULT '',
				name varchar(190) NOT NULL DEFAULT '',
				description text NULL,
				icon varchar(190) NOT NULL DEFAULT '',
				criteria longtext NULL,
				points_award bigint(20) unsigned NOT NULL DEFAULT 0,
				badge varchar(60) NOT NULL DEFAULT '',
				tier varchar(30) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				UNIQUE KEY akey (akey)
			) {$charset_collate};",

			"CREATE TABLE {$user_achievements} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				achievement_id bigint(20) unsigned NOT NULL DEFAULT 0,
				progress bigint(20) unsigned NOT NULL DEFAULT 0,
				unlocked_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY user_achievement (user_id,achievement_id),
				KEY user_id (user_id)
			) {$charset_collate};",

			"CREATE TABLE {$tiers} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(190) NOT NULL DEFAULT '',
				slug varchar(30) NOT NULL DEFAULT '',
				threshold_points bigint(20) unsigned NOT NULL DEFAULT 0,
				multiplier decimal(6,2) NOT NULL DEFAULT 1,
				benefits longtext NULL,
				sort int(11) NOT NULL DEFAULT 0,
				badge varchar(60) NOT NULL DEFAULT '',
				color varchar(20) NOT NULL DEFAULT '',
				discount_percent decimal(6,2) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug)
			) {$charset_collate};",
		);
	}
}
