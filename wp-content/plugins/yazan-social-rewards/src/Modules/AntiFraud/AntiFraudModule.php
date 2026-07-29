<?php
/**
 * Anti-fraud engine module.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\AntiFraud;

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
use Yazan\Rewards\Core\Support\Assets;
use Yazan\Rewards\Core\Support\Fingerprint;
use Yazan\Rewards\Core\Support\Money;
use Yazan\Rewards\Modules\AntiFraud\Detection\DuplicateContentDetector;
use Yazan\Rewards\Modules\AntiFraud\Detection\EngagementAnomalyDetector;
use Yazan\Rewards\Modules\AntiFraud\Detection\MultiAccountDetector;
use Yazan\Rewards\Modules\Points\PointsRepository;
use Yazan\Rewards\Modules\Social\SocialRepository;
use Yazan\Rewards\Admin\FraudAdminPage;
use Yazan\Rewards\Rest\V1\FraudAdminController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the `fraud_flags` case store, the detectors + risk engine, the central flag
 * logger, and the review/enforcement service (hold → approve/release or reject/claw
 * back). Depends on Points.
 */
final class AntiFraudModule extends AbstractModule implements Installable {

	/**
	 * @inheritDoc
	 */
	public function id(): string {
		return 'antifraud';
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
		$c->singleton( FraudRepository::class, static fn( Container $c ) => new FraudRepository( $c->get( Database::class ) ) );
		// Fingerprint is also bound by the Referral module; bind here so anti-fraud
		// works even if referral is removed (stateless — rebinding is harmless).
		$c->singleton( Fingerprint::class, static fn() => new Fingerprint() );

		$c->singleton(
			RiskEngine::class,
			static function ( Container $c ) {
				$detectors = array(
					new ActivityMonitor( $c->get( RateLimiter::class ), $c->get( Settings::class ) ),
					new MultiAccountDetector( $c->get( Fingerprint::class ), $c->get( Settings::class ) ),
					new EngagementAnomalyDetector( $c->get( Settings::class ) ),
				);
				if ( $c->has( SocialRepository::class ) ) {
					$detectors[] = new DuplicateContentDetector( $c->get( SocialRepository::class ), $c->get( Settings::class ) );
				}
				/**
				 * Filter the active fraud detectors.
				 *
				 * @param \Yazan\Rewards\Core\Contracts\DetectorInterface[] $detectors Detectors.
				 */
				$detectors = (array) apply_filters( 'yazan_rewards/fraud/detectors', $detectors );

				return new RiskEngine(
					$detectors,
					$c->get( PointsRepository::class ),
					$c->get( EventBus::class ),
					$c->get( Settings::class ),
					$c->get( Fingerprint::class )
				);
			}
		);

		$c->singleton( FraudLogger::class, static fn( Container $c ) => new FraudLogger( $c->get( FraudRepository::class ) ) );
		$c->singleton(
			FraudReviewService::class,
			static fn( Container $c ) => new FraudReviewService(
				$c->get( FraudRepository::class ),
				$c->get( PointsLedgerInterface::class ),
				$c->get( Money::class ),
				$c->get( EventBus::class ),
				$c->has( WalletServiceInterface::class ) ? $c->get( WalletServiceInterface::class ) : null
			)
		);
		$c->singleton( FraudAdminPage::class, static fn( Container $c ) => new FraudAdminPage( $c->get( Assets::class ) ) );
	}

	/**
	 * @inheritDoc
	 */
	public function boot( Container $c ): void {
		if ( ! $c->get( Settings::class )->feature_enabled( 'antifraud' ) ) {
			return;
		}
		$hooks = $c->get( HookLoader::class );
		$hooks->register( $c->get( RiskEngine::class ) );
		$hooks->register( $c->get( FraudAdminPage::class ) );

		$bus = $c->get( EventBus::class );
		$bus->subscribe( $c->get( RiskEngine::class ) );
		$bus->subscribe( $c->get( FraudLogger::class ) );

		$c->get( RestBootstrap::class )->add( static fn( Container $c ) => new FraudAdminController( $c ) );
	}

	/**
	 * @inheritDoc
	 */
	public function schema( string $charset_collate ): array {
		global $wpdb;
		$table = $wpdb->prefix . Database::TABLE_PREFIX . 'fraud_flags';

		return array(
			// Fraud CASES (formerly bare flags). The status/source/reward_*/resolver
			// columns + `signals` are 1.8.0 additions turning this into a reviewable
			// case store with the four states suspicious|manual_review|approved|rejected
			// and the linkage needed to release or claw back the associated reward.
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				type varchar(40) NOT NULL DEFAULT '',
				severity varchar(20) NOT NULL DEFAULT 'low',
				status varchar(20) NOT NULL DEFAULT 'suspicious',
				score int(10) unsigned NOT NULL DEFAULT 0,
				context longtext NULL,
				signals longtext NULL,
				ip_hash varchar(32) NOT NULL DEFAULT '',
				source varchar(30) NOT NULL DEFAULT '',
				source_id bigint(20) unsigned NOT NULL DEFAULT 0,
				reward_kind varchar(10) NOT NULL DEFAULT 'none',
				reward_amount decimal(19,4) NOT NULL DEFAULT 0.0000,
				reward_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				resolved tinyint(1) NOT NULL DEFAULT 0,
				resolved_by bigint(20) unsigned NOT NULL DEFAULT 0,
				resolved_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				note varchar(190) NOT NULL DEFAULT '',
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY resolved (resolved),
				KEY type (type),
				KEY status (status),
				KEY source (source,source_id)
			) {$charset_collate};",
		);
	}
}
