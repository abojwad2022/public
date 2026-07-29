<?php
/**
 * Points engine module.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Points;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Contracts\Installable;
use Yazan\Rewards\Core\Contracts\PointsLedgerInterface;
use Yazan\Rewards\Core\Contracts\RulesEngineInterface;
use Yazan\Rewards\Core\Database\Database;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Hooks\HookLoader;
use Yazan\Rewards\Core\Module\AbstractModule;
use Yazan\Rewards\Core\Rest\RestBootstrap;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Core\Support\Scheduler;
use Yazan\Rewards\Rest\V1\MeController;
use Yazan\Rewards\Integration\WooCommerce\OrderObserver;
use Yazan\Rewards\Integration\WooCommerce\RefundObserver;
use Yazan\Rewards\Integration\WordPress\ReviewObserver;
use Yazan\Rewards\Integration\WordPress\UserObserver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the `points_ledger` table, the ledger service (bound as
 * PointsLedgerInterface), the earning subscriber, the expiry job, and the
 * WooCommerce/WordPress observers that translate store events into earning.
 */
final class PointsModule extends AbstractModule implements Installable {

	/**
	 * @inheritDoc
	 */
	public function id(): string {
		return 'points';
	}

	/**
	 * @inheritDoc
	 */
	public function dependencies(): array {
		return array( 'rules' );
	}

	/**
	 * @inheritDoc
	 */
	public function register( Container $c ): void {
		$c->singleton( PointsRepository::class, static fn( Container $c ) => new PointsRepository( $c->get( Database::class ) ) );
		$c->singleton( BalanceCache::class, static fn() => new BalanceCache() );
		$c->singleton(
			PointsLedger::class,
			static fn( Container $c ) => new PointsLedger(
				$c->get( PointsRepository::class ),
				$c->get( BalanceCache::class ),
				$c->get( EventBus::class )
			)
		);
		$c->alias( PointsLedgerInterface::class, PointsLedger::class );

		$c->singleton(
			ExpiryService::class,
			static fn( Container $c ) => new ExpiryService(
				$c->get( PointsRepository::class ),
				$c->get( PointsLedger::class ),
				$c->get( EventBus::class ),
				$c->get( Scheduler::class ),
				$c->get( Settings::class )
			)
		);

		$c->singleton(
			ExpiryReminderService::class,
			static fn( Container $c ) => new ExpiryReminderService(
				$c->get( PointsRepository::class ),
				$c->get( EventBus::class ),
				$c->get( Scheduler::class ),
				$c->get( Settings::class )
			)
		);

		$c->singleton(
			PointsEarnSubscriber::class,
			static fn( Container $c ) => new PointsEarnSubscriber(
				$c->get( PointsLedgerInterface::class ),
				$c->get( RulesEngineInterface::class ),
				$c->get( Settings::class ),
				$c->get( ExpiryService::class )
			)
		);

		// Observers (translate store hooks → domain events).
		$c->singleton( OrderObserver::class, static fn( Container $c ) => new OrderObserver( $c->get( EventBus::class ), $c->get( Settings::class ) ) );
		$c->singleton( RefundObserver::class, static fn( Container $c ) => new RefundObserver( $c->get( EventBus::class ) ) );
		$c->singleton( UserObserver::class, static fn( Container $c ) => new UserObserver( $c->get( EventBus::class ) ) );
		$c->singleton( ReviewObserver::class, static fn( Container $c ) => new ReviewObserver( $c->get( EventBus::class ) ) );

		$c->singleton( \Yazan\Rewards\Admin\PointsAdminPage::class, static fn( Container $c ) => new \Yazan\Rewards\Admin\PointsAdminPage( $c->get( \Yazan\Rewards\Core\Support\Assets::class ) ) );
	}

	/**
	 * @inheritDoc
	 */
	public function boot( Container $c ): void {
		/** @var HookLoader $hooks */
		$hooks = $c->get( HookLoader::class );

		// Wire the observers' declared hooks.
		$hooks->register( $c->get( OrderObserver::class ) );
		$hooks->register( $c->get( RefundObserver::class ) );
		$hooks->register( $c->get( UserObserver::class ) );
		$hooks->register( $c->get( ReviewObserver::class ) );

		// Subscribe the earning engine to those events.
		$c->get( EventBus::class )->subscribe( $c->get( PointsEarnSubscriber::class ) );

		// Expiry job + pre-expiry reminder — both self-schedule on `init` (gated on
		// expiry/reminders being enabled).
		$hooks->register( $c->get( ExpiryService::class ) );
		$hooks->register( $c->get( ExpiryReminderService::class ) );

		// Customer REST surface. Registered as a factory so it resolves after every
		// module (incl. Wallet) has bound its services.
		$c->get( RestBootstrap::class )->add(
			static fn( Container $c ) => new MeController( $c )
		);

		// Admin: pending-points queue + manual adjustments.
		$hooks->register( $c->get( \Yazan\Rewards\Admin\PointsAdminPage::class ) );
		$c->get( RestBootstrap::class )->add(
			static fn( Container $c ) => new \Yazan\Rewards\Rest\V1\PointsAdminController( $c )
		);
	}

	/**
	 * @inheritDoc
	 */
	public function activate( Container $c ): void {
		// Align any legacy status vocabulary to Pending/Approved/Rejected/Expired.
		$c->get( PointsRepository::class )->migrate_statuses();
	}

	/**
	 * @inheritDoc
	 */
	public function schema( string $charset_collate ): array {
		global $wpdb;
		$table = $wpdb->prefix . Database::TABLE_PREFIX . 'points_ledger';

		return array(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				points bigint(20) NOT NULL DEFAULT 0,
				balance_before bigint(20) NOT NULL DEFAULT 0,
				balance_after bigint(20) NOT NULL DEFAULT 0,
				type varchar(20) NOT NULL DEFAULT 'earn',
				reason varchar(190) NOT NULL DEFAULT '',
				source varchar(30) NOT NULL DEFAULT '',
				source_id bigint(20) unsigned NOT NULL DEFAULT 0,
				campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'approved',
				note varchar(255) NOT NULL DEFAULT '',
				expires_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY source (source,source_id),
				KEY status (status),
				KEY expires_at (expires_at)
			) {$charset_collate};",
		);
	}
}
