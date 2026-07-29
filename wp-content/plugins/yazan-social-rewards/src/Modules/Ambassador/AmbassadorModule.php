<?php
/**
 * Ambassador engine module.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Ambassador;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Contracts\Installable;
use Yazan\Rewards\Core\Contracts\PayoutAdapterInterface;
use Yazan\Rewards\Core\Contracts\WalletServiceInterface;
use Yazan\Rewards\Core\Database\Database;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Hooks\HookLoader;
use Yazan\Rewards\Core\Module\AbstractModule;
use Yazan\Rewards\Core\Rest\RestBootstrap;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Core\Support\Money;
use Yazan\Rewards\Frontend\AmbassadorDashboard;
use Yazan\Rewards\Modules\Referral\ReferralCodes;
use Yazan\Rewards\Modules\Referral\ReferralRepository;
use Yazan\Rewards\Rest\V1\AmbassadorController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the `ambassadors` table, applications/approvals, and commission payout on
 * attributed orders. Depends on Wallet (payout) and Referral (attribution).
 */
final class AmbassadorModule extends AbstractModule implements Installable {

	/**
	 * @inheritDoc
	 */
	public function id(): string {
		return 'ambassador';
	}

	/**
	 * @inheritDoc
	 */
	public function dependencies(): array {
		return array( 'wallet', 'referral' );
	}

	/**
	 * @inheritDoc
	 */
	public function register( Container $c ): void {
		$c->singleton( AmbassadorRepository::class, static fn( Container $c ) => new AmbassadorRepository( $c->get( Database::class ) ) );

		// The v1 payout adapter (store credit). Swap this binding to change payout.
		$c->singleton( PayoutAdapterInterface::class, static fn( Container $c ) => new StoreCreditPayoutAdapter( $c->get( WalletServiceInterface::class ) ) );

		$c->singleton(
			ApplicationService::class,
			static fn( Container $c ) => new ApplicationService(
				$c->get( AmbassadorRepository::class ),
				$c->get( ReferralCodes::class ),
				$c->get( Settings::class ),
				$c->get( EventBus::class )
			)
		);

		$c->singleton(
			CommissionService::class,
			static fn( Container $c ) => new CommissionService(
				$c->get( AmbassadorRepository::class ),
				$c->get( ReferralRepository::class ),
				$c->get( PayoutAdapterInterface::class ),
				$c->get( WalletServiceInterface::class ),
				$c->get( EventBus::class ),
				$c->get( Money::class ),
				$c->get( Settings::class )
			)
		);

		// Member/ambassador dashboard — My Account endpoint (aggregation + presentation).
		$c->singleton( AmbassadorDashboard::class, static fn( Container $c ) => new AmbassadorDashboard( $c ) );
	}

	/**
	 * @inheritDoc
	 */
	public function boot( Container $c ): void {
		if ( ! $c->get( Settings::class )->feature_enabled( 'ambassador' ) ) {
			return;
		}
		$c->get( EventBus::class )->subscribe( $c->get( CommissionService::class ) );
		$c->get( RestBootstrap::class )->add( static fn( Container $c ) => new AmbassadorController( $c ) );

		// My Account member dashboard (endpoint, menu item, render, assets).
		$c->get( HookLoader::class )->register( $c->get( AmbassadorDashboard::class ) );
	}

	/**
	 * @inheritDoc
	 */
	public function activate( Container $c ): void {
		// Register the endpoint so the Activator's rewrite flush includes
		// /my-account/yazan-ambassador (otherwise the tab 404s until re-save).
		$c->get( AmbassadorDashboard::class )->add_endpoint();
	}

	/**
	 * @inheritDoc
	 */
	public function schema( string $charset_collate ): array {
		global $wpdb;
		$table = $wpdb->prefix . Database::TABLE_PREFIX . 'ambassadors';

		return array(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				code varchar(32) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'pending',
				tier varchar(30) NOT NULL DEFAULT '',
				commission_rate decimal(6,2) NOT NULL DEFAULT 0,
				stats longtext NULL,
				applied_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				approved_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY user_id (user_id),
				KEY status (status)
			) {$charset_collate};",
		);
	}
}
