<?php
/**
 * Rewards engine module.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Rewards;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Contracts\Installable;
use Yazan\Rewards\Core\Contracts\PointsLedgerInterface;
use Yazan\Rewards\Core\Contracts\WalletServiceInterface;
use Yazan\Rewards\Core\Database\Database;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Hooks\HookLoader;
use Yazan\Rewards\Core\Module\AbstractModule;
use Yazan\Rewards\Core\Rest\RestBootstrap;
use Yazan\Rewards\Core\Security\RateLimiter;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Core\Support\Money;
use Yazan\Rewards\Frontend\AccountHub;
use Yazan\Rewards\Frontend\Assets;
use Yazan\Rewards\Admin\DashboardEmbed;
use Yazan\Rewards\Admin\RewardsAdminPage;
use Yazan\Rewards\Admin\ServiceAdminPage;
use Yazan\Rewards\Rest\V1\RewardsController;
use Yazan\Rewards\Rest\V1\RewardsAdminController;
use Yazan\Rewards\Rest\V1\ServiceVoucherController;
use Yazan\Rewards\Rest\V1\WalletController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the `rewards` catalog and `redemptions` tables, the redemption service and
 * its issuers, the customer REST surface, and the My Account "Rewards" hub.
 */
final class RewardsModule extends AbstractModule implements Installable {

	/**
	 * @inheritDoc
	 */
	public function id(): string {
		return 'rewards';
	}

	/**
	 * @inheritDoc
	 */
	public function dependencies(): array {
		return array( 'points', 'wallet' );
	}

	/**
	 * @inheritDoc
	 */
	public function register( Container $c ): void {
		$c->singleton( RewardRepository::class, static fn( Container $c ) => new RewardRepository( $c->get( Database::class ) ) );
		$c->singleton( RedemptionRepository::class, static fn( Container $c ) => new RedemptionRepository( $c->get( Database::class ) ) );
		$c->singleton( ServiceVoucherRepository::class, static fn( Container $c ) => new ServiceVoucherRepository( $c->get( Database::class ) ) );
		$c->singleton( WalletIssuer::class, static fn( Container $c ) => new WalletIssuer( $c->get( WalletServiceInterface::class ), $c->get( Money::class ) ) );
		$c->singleton( CouponIssuer::class, static fn() => new CouponIssuer() );
		$c->singleton( ServiceIssuer::class, static fn( Container $c ) => new ServiceIssuer( $c->get( ServiceVoucherRepository::class ), $c->get( EventBus::class ) ) );

		// Reward fulfillment registry: built-in type→issuer map, then a filter so
		// third parties can add/override a provider via yazan_register_reward_provider().
		$c->singleton(
			RewardProviderRegistry::class,
			static function ( Container $c ) {
				$registry = new RewardProviderRegistry();
				$coupon   = $c->get( CouponIssuer::class );
				$service  = $c->get( ServiceIssuer::class );
				$registry->register( Reward::TYPE_CREDIT, $c->get( WalletIssuer::class ) );
				$registry->register( Reward::TYPE_COUPON, $coupon );
				$registry->register( Reward::TYPE_FREE_SHIPPING, $coupon );
				$registry->register( Reward::TYPE_FREE_PRODUCT, $coupon );
				foreach ( array( Reward::TYPE_VIP_SERVICE, Reward::TYPE_RING_CLEANING, Reward::TYPE_RING_POLISHING, Reward::TYPE_EXCLUSIVE_PRODUCT ) as $svc_type ) {
					$registry->register( $svc_type, $service );
				}

				/**
				 * Filter the reward provider registry so add-ons can register a custom
				 * reward type + fulfillment provider.
				 *
				 * @param RewardProviderRegistry $registry The registry.
				 */
				return apply_filters( 'yazan_rewards/rewards/providers', $registry );
			}
		);

		$c->singleton(
			RedemptionService::class,
			static fn( Container $c ) => new RedemptionService(
				$c->get( RewardRepository::class ),
				$c->get( RedemptionRepository::class ),
				$c->get( PointsLedgerInterface::class ),
				$c->get( RewardProviderRegistry::class ),
				$c->get( EventBus::class ),
				$c->get( RateLimiter::class ),
				$c->get( Settings::class )
			)
		);

		$c->singleton( AccountHub::class, static fn( Container $c ) => new AccountHub( $c ) );
		$c->singleton( Assets::class, static fn( Container $c ) => new Assets( $c->get( \Yazan\Rewards\Core\Support\Assets::class ) ) );
		$c->singleton( RewardsAdminPage::class, static fn( Container $c ) => new RewardsAdminPage( $c->get( \Yazan\Rewards\Core\Support\Assets::class ) ) );
		$c->singleton( ServiceAdminPage::class, static fn( Container $c ) => new ServiceAdminPage( $c->get( \Yazan\Rewards\Core\Support\Assets::class ) ) );
		$c->singleton( DashboardEmbed::class, static fn( Container $c ) => new DashboardEmbed( $c, $c->get( \Yazan\Rewards\Core\Support\Assets::class ) ) );
	}

	/**
	 * @inheritDoc
	 */
	public function boot( Container $c ): void {
		/** @var HookLoader $hooks */
		$hooks = $c->get( HookLoader::class );

		// My Account hub (endpoint, menu item, render, assets).
		$hub = $c->get( AccountHub::class );
		$hooks->register( $hub );
		$hooks->register( $c->get( Assets::class ) );

		// Admin manager pages (rewards catalog + service fulfillment queue).
		$hooks->register( $c->get( RewardsAdminPage::class ) );
		$hooks->register( $c->get( ServiceAdminPage::class ) );

		// Chrome-less embed renderer (?yzrw_embed={screen}) so the standalone
		// /dashboard can iframe each rewards admin screen. Front-end, always on.
		$hooks->register( $c->get( DashboardEmbed::class ) );

		// Customer + admin REST controllers (lazy factories — resolve at rest_api_init).
		$rest = $c->get( RestBootstrap::class );
		$rest->add( static fn( Container $c ) => new RewardsController( $c ) );
		$rest->add( static fn( Container $c ) => new WalletController( $c ) );
		$rest->add( static fn( Container $c ) => new RewardsAdminController( $c ) );
		$rest->add( static fn( Container $c ) => new ServiceVoucherController( $c ) );
	}

	/**
	 * @inheritDoc
	 */
	public function activate( Container $c ): void {
		// Register the account endpoint so the Activator's rewrite flush includes it.
		$c->get( AccountHub::class )->add_endpoint();

		$this->seed_rewards( $c );
	}

	/**
	 * Seed a small starter catalog once.
	 *
	 * @param Container $c Container.
	 * @return void
	 */
	private function seed_rewards( Container $c ): void {
		$repo = $c->get( RewardRepository::class );
		if ( $repo->count_all() > 0 ) {
			return;
		}

		$repo->create(
			array(
				'type'        => Reward::TYPE_CREDIT,
				'title'       => __( '$5 Store Credit', 'yazan-rewards' ),
				'description' => __( 'Redeem points for $5 of Yazan store credit.', 'yazan-rewards' ),
				'cost_points' => 500,
				'value'       => array( 'amount' => 5 ),
				'active'      => true,
				'sort'        => 10,
			)
		);
		$repo->create(
			array(
				'type'        => Reward::TYPE_CREDIT,
				'title'       => __( '$15 Store Credit', 'yazan-rewards' ),
				'description' => __( 'Redeem points for $15 of Yazan store credit.', 'yazan-rewards' ),
				'cost_points' => 1400,
				'value'       => array( 'amount' => 15 ),
				'active'      => true,
				'sort'        => 20,
			)
		);
		$repo->create(
			array(
				'type'        => Reward::TYPE_COUPON,
				'title'       => __( '10% Off Coupon', 'yazan-rewards' ),
				'description' => __( 'A single-use 10% discount on your next order.', 'yazan-rewards' ),
				'cost_points' => 800,
				'value'       => array( 'discount_type' => 'percent', 'amount' => 10, 'expiry_days' => 30 ),
				'active'      => true,
				'sort'        => 30,
			)
		);
		$repo->create(
			array(
				'type'        => Reward::TYPE_FREE_SHIPPING,
				'title'       => __( 'Complimentary Shipping', 'yazan-rewards' ),
				'description' => __( 'A single-use free-shipping coupon.', 'yazan-rewards' ),
				'cost_points' => 300,
				'value'       => array( 'expiry_days' => 30 ),
				'active'      => true,
				'sort'        => 40,
			)
		);
		// Service rewards (fulfilled via voucher + queue) — one redemption per customer.
		$repo->create(
			array(
				'type'           => Reward::TYPE_RING_CLEANING,
				'title'          => __( 'Complimentary Ring Cleaning', 'yazan-rewards' ),
				'description'    => __( 'Professional ultrasonic cleaning for one ring.', 'yazan-rewards' ),
				'cost_points'    => 600,
				'value'          => array(),
				'active'         => true,
				'sort'           => 50,
				'per_user_limit' => 2,
			)
		);
		$repo->create(
			array(
				'type'           => Reward::TYPE_RING_POLISHING,
				'title'          => __( 'Ring Polishing Service', 'yazan-rewards' ),
				'description'    => __( 'Hand polishing to restore your ring\'s shine.', 'yazan-rewards' ),
				'cost_points'    => 900,
				'value'          => array(),
				'active'         => true,
				'sort'           => 60,
				'per_user_limit' => 2,
			)
		);
		$repo->create(
			array(
				'type'           => Reward::TYPE_VIP_SERVICE,
				'title'          => __( 'VIP Concierge Session', 'yazan-rewards' ),
				'description'    => __( 'A private styling & selection session with a Yazan advisor.', 'yazan-rewards' ),
				'cost_points'    => 3000,
				'value'          => array(),
				'active'         => true,
				'sort'           => 70,
				'per_user_limit' => 1,
			)
		);
	}

	/**
	 * @inheritDoc
	 */
	public function schema( string $charset_collate ): array {
		global $wpdb;
		$rewards     = $wpdb->prefix . Database::TABLE_PREFIX . 'rewards';
		$redemptions = $wpdb->prefix . Database::TABLE_PREFIX . 'redemptions';
		$vouchers    = $wpdb->prefix . Database::TABLE_PREFIX . 'service_vouchers';

		return array(
			"CREATE TABLE {$rewards} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				type varchar(30) NOT NULL DEFAULT 'credit',
				title varchar(190) NOT NULL DEFAULT '',
				description text NULL,
				cost_points bigint(20) unsigned NOT NULL DEFAULT 0,
				value longtext NULL,
				stock int(11) NOT NULL DEFAULT -1,
				tier_min int(11) NOT NULL DEFAULT 0,
				active tinyint(1) NOT NULL DEFAULT 1,
				sort int(11) NOT NULL DEFAULT 0,
				available_from datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				available_to datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				per_user_limit int(11) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY active (active),
				KEY type (type)
			) {$charset_collate};",

			"CREATE TABLE {$redemptions} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				reward_id bigint(20) unsigned NOT NULL DEFAULT 0,
				points_spent bigint(20) unsigned NOT NULL DEFAULT 0,
				result_type varchar(20) NOT NULL DEFAULT '',
				result_ref varchar(60) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'complete',
				note varchar(255) NOT NULL DEFAULT '',
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY reward_id (reward_id)
			) {$charset_collate};",

			"CREATE TABLE {$vouchers} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				reward_id bigint(20) unsigned NOT NULL DEFAULT 0,
				redemption_id bigint(20) unsigned NOT NULL DEFAULT 0,
				type varchar(30) NOT NULL DEFAULT '',
				code varchar(40) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'requested',
				notes varchar(255) NOT NULL DEFAULT '',
				scheduled_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				fulfilled_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY code (code),
				KEY user_id (user_id),
				KEY status (status)
			) {$charset_collate};",
		);
	}
}
