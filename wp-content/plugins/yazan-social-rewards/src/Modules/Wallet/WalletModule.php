<?php
/**
 * Wallet engine module.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Wallet;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Contracts\Installable;
use Yazan\Rewards\Core\Contracts\WalletServiceInterface;
use Yazan\Rewards\Core\Database\Database;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Hooks\HookLoader;
use Yazan\Rewards\Core\Module\AbstractModule;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Core\Support\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the `wallet_ledger` table, the wallet service (bound as
 * WalletServiceInterface), and the checkout-credit application.
 */
final class WalletModule extends AbstractModule implements Installable {

	/**
	 * @inheritDoc
	 */
	public function id(): string {
		return 'wallet';
	}

	/**
	 * @inheritDoc
	 */
	public function register( Container $c ): void {
		$c->singleton( WalletRepository::class, static fn( Container $c ) => new WalletRepository( $c->get( Database::class ) ) );
		$c->singleton(
			WalletService::class,
			static fn( Container $c ) => new WalletService(
				$c->get( WalletRepository::class ),
				$c->get( EventBus::class ),
				$c->get( Money::class )
			)
		);
		$c->alias( WalletServiceInterface::class, WalletService::class );

		$c->singleton(
			CheckoutCredit::class,
			static fn( Container $c ) => new CheckoutCredit(
				$c->get( WalletServiceInterface::class ),
				$c->get( Settings::class ),
				$c->get( Money::class )
			)
		);
	}

	/**
	 * @inheritDoc
	 */
	public function boot( Container $c ): void {
		if ( ! $c->get( Settings::class )->feature_enabled( 'wallet' ) ) {
			return;
		}
		$c->get( HookLoader::class )->register( $c->get( CheckoutCredit::class ) );
	}

	/**
	 * @inheritDoc
	 */
	public function schema( string $charset_collate ): array {
		global $wpdb;
		$table = $wpdb->prefix . Database::TABLE_PREFIX . 'wallet_ledger';

		return array(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				amount decimal(19,4) NOT NULL DEFAULT 0,
				balance_after decimal(19,4) NOT NULL DEFAULT 0,
				type varchar(20) NOT NULL DEFAULT 'adjust',
				source varchar(30) NOT NULL DEFAULT '',
				source_id bigint(20) unsigned NOT NULL DEFAULT 0,
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'cleared',
				note varchar(255) NOT NULL DEFAULT '',
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY source (source,source_id),
				KEY order_id (order_id)
			) {$charset_collate};",
		);
	}
}
