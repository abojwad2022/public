<?php
/**
 * Referral engine module.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Referral;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Contracts\Installable;
use Yazan\Rewards\Core\Contracts\PointsLedgerInterface;
use Yazan\Rewards\Core\Contracts\WalletServiceInterface;
use Yazan\Rewards\Core\Database\Database;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Hooks\HookLoader;
use Yazan\Rewards\Core\Module\AbstractModule;
use Yazan\Rewards\Core\Rest\RestBootstrap;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Core\Support\Assets;
use Yazan\Rewards\Core\Support\Fingerprint;
use Yazan\Rewards\Core\Support\Money;
use Yazan\Rewards\Admin\ReferralAdminPage;
use Yazan\Rewards\Rest\V1\ReferralAdminController;
use Yazan\Rewards\Rest\V1\ReferralController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the `referrals` table, per-user referral codes, link tracking, and the
 * attribution/conversion logic. Depends on Points (rewards both parties).
 */
final class ReferralModule extends AbstractModule implements Installable {

	/**
	 * @inheritDoc
	 */
	public function id(): string {
		return 'referral';
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
		$c->singleton( ReferralRepository::class, static fn( Container $c ) => new ReferralRepository( $c->get( Database::class ) ) );
		$c->singleton( ReferralEarningsRepository::class, static fn( Container $c ) => new ReferralEarningsRepository( $c->get( Database::class ) ) );
		$c->singleton( ReferralCodes::class, static fn() => new ReferralCodes() );
		$c->singleton( Fingerprint::class, static fn() => new Fingerprint() );
		$c->singleton(
			LinkTracker::class,
			static fn( Container $c ) => new LinkTracker( $c->get( ReferralCodes::class ), $c->get( Settings::class ) )
		);
		$c->singleton( ReferralTree::class, static fn( Container $c ) => new ReferralTree( $c->get( ReferralRepository::class ) ) );
		$c->singleton( ReferralRewardRules::class, static fn( Container $c ) => new ReferralRewardRules( $c->get( Settings::class ) ) );
		$c->singleton(
			ReferralGuard::class,
			static fn( Container $c ) => new ReferralGuard(
				$c->get( ReferralRepository::class ),
				$c->get( Fingerprint::class ),
				$c->get( EventBus::class )
			)
		);
		$c->singleton(
			ReferralRewardResolver::class,
			static fn( Container $c ) => new ReferralRewardResolver(
				$c->get( ReferralRepository::class ),
				$c->get( ReferralEarningsRepository::class ),
				$c->get( ReferralTree::class ),
				$c->get( ReferralRewardRules::class ),
				$c->get( ReferralGuard::class ),
				$c->get( PointsLedgerInterface::class ),
				$c->get( Money::class ),
				$c->get( EventBus::class ),
				$c->get( Settings::class ),
				$c->has( WalletServiceInterface::class ) ? $c->get( WalletServiceInterface::class ) : null
			)
		);
		$c->singleton(
			AttributionService::class,
			static fn( Container $c ) => new AttributionService(
				$c->get( ReferralRepository::class ),
				$c->get( ReferralCodes::class ),
				$c->get( LinkTracker::class ),
				$c->get( ReferralRewardResolver::class ),
				$c->get( Fingerprint::class ),
				$c->get( EventBus::class ),
				$c->get( Settings::class )
			)
		);
		$c->singleton( ReferralAdminPage::class, static fn( Container $c ) => new ReferralAdminPage( $c->get( Assets::class ) ) );
	}

	/**
	 * @inheritDoc
	 */
	public function boot( Container $c ): void {
		if ( ! $c->get( Settings::class )->feature_enabled( 'referral' ) ) {
			return;
		}
		$hooks = $c->get( HookLoader::class );
		$hooks->register( $c->get( LinkTracker::class ) );
		$hooks->register( $c->get( ReferralAdminPage::class ) );

		$c->get( EventBus::class )->subscribe( $c->get( AttributionService::class ) );

		$rest = $c->get( RestBootstrap::class );
		$rest->add( static fn( Container $c ) => new ReferralController( $c ) );
		$rest->add( static fn( Container $c ) => new ReferralAdminController( $c ) );
	}

	/**
	 * @inheritDoc
	 */
	public function schema( string $charset_collate ): array {
		global $wpdb;
		$referrals = $wpdb->prefix . Database::TABLE_PREFIX . 'referrals';
		$earnings  = $wpdb->prefix . Database::TABLE_PREFIX . 'referral_earnings';

		return array(
			// The attribution funnel + per-referral revenue/points tracking. The
			// revenue_total / orders_count / points_generated / last_order_at columns
			// and the email_hash + ip_hash indexes are 1.6.0 additions (dbDelta adds
			// them in place on upgrade).
			"CREATE TABLE {$referrals} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				referrer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				referred_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				code varchar(32) NOT NULL DEFAULT '',
				click_token varchar(40) NOT NULL DEFAULT '',
				ip_hash varchar(32) NOT NULL DEFAULT '',
				email_hash varchar(64) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'signed_up',
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				reward_points bigint(20) unsigned NOT NULL DEFAULT 0,
				revenue_total decimal(19,4) NOT NULL DEFAULT 0.0000,
				orders_count int(10) unsigned NOT NULL DEFAULT 0,
				points_generated bigint(20) unsigned NOT NULL DEFAULT 0,
				last_order_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				converted_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY referrer (referrer_user_id),
				KEY referred (referred_user_id),
				KEY status (status),
				KEY ip_hash (ip_hash),
				KEY email_hash (email_hash)
			) {$charset_collate};",

			// Append-only earnings ledger: one row per beneficiary per rewarded order
			// (level 1 = direct referrer, level 2 = grand-referrer). Records the order
			// value that generated it and holds the pending→approved lifecycle used by
			// the anti-fraud "hold for review" path.
			"CREATE TABLE {$earnings} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				referral_id bigint(20) unsigned NOT NULL DEFAULT 0,
				beneficiary_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				referred_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				level tinyint(3) unsigned NOT NULL DEFAULT 1,
				role varchar(20) NOT NULL DEFAULT 'referrer',
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				order_total decimal(19,4) NOT NULL DEFAULT 0.0000,
				reward_kind varchar(10) NOT NULL DEFAULT 'points',
				reward_amount decimal(19,4) NOT NULL DEFAULT 0.0000,
				status varchar(20) NOT NULL DEFAULT 'approved',
				note varchar(190) NOT NULL DEFAULT '',
				ledger_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				approved_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY beneficiary (beneficiary_user_id),
				KEY referral (referral_id),
				KEY status (status),
				KEY orderid (order_id)
			) {$charset_collate};",
		);
	}
}
