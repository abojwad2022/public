<?php
/**
 * Social Connector engine module.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Social;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Contracts\Installable;
use Yazan\Rewards\Core\Contracts\PlatformAdapterInterface;
use Yazan\Rewards\Core\Contracts\PointsLedgerInterface;
use Yazan\Rewards\Core\Database\Database;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Hooks\HookLoader;
use Yazan\Rewards\Core\Module\AbstractModule;
use Yazan\Rewards\Core\Rest\RestBootstrap;
use Yazan\Rewards\Core\Security\RateLimiter;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Core\Support\Assets;
use Yazan\Rewards\Core\Support\Crypto;
use Yazan\Rewards\Core\Support\Http;
use Yazan\Rewards\Core\Support\Scheduler;
use Yazan\Rewards\Admin\SocialAdminPage;
use Yazan\Rewards\Rest\V1\SocialAdminController;
use Yazan\Rewards\Rest\V1\SocialController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the `social_actions` + `social_accounts` tables and the modular connector
 * framework: per-network OAuth connectors (from config/connectors.php), the
 * verification orchestrator (API when keys present, else manual), account linking,
 * and metrics. Depends on Points.
 */
final class SocialModule extends AbstractModule implements Installable {

	/**
	 * @inheritDoc
	 */
	public function id(): string {
		return 'social';
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
		$c->singleton( SocialRepository::class, static fn( Container $c ) => new SocialRepository( $c->get( Database::class ) ) );
		$c->singleton( SocialAccountRepository::class, static fn( Container $c ) => new SocialAccountRepository( $c->get( Database::class ) ) );
		$c->singleton( SocialSecrets::class, static fn() => new SocialSecrets() );
		$c->singleton( Http::class, static fn() => new Http() );
		$c->singleton( Crypto::class, static fn() => new Crypto() );

		// The connector registry, built from the filterable config list — nothing
		// hardcodes the network set. Each connector self-declares its id.
		$c->singleton(
			ConnectorRegistry::class,
			static function ( Container $c ) {
				$classes    = (array) apply_filters( 'yazan_rewards/social/connectors', require YAZAN_REWARDS_DIR . 'config/connectors.php' );
				$secrets    = $c->get( SocialSecrets::class );
				$http       = $c->get( Http::class );
				$settings   = $c->get( Settings::class );
				$connectors = array();
				foreach ( $classes as $class ) {
					if ( is_string( $class ) && class_exists( $class ) ) {
						$connectors[] = new $class( $secrets, $http, $settings );
					}
				}
				return new ConnectorRegistry( $connectors );
			}
		);

		$c->singleton( UrlFetcher::class, static fn() => new UrlFetcher() );
		$c->singleton( ContentScraper::class, static fn() => new ContentScraper() );
		$c->singleton(
			ManualVerifier::class,
			static fn( Container $c ) => new ManualVerifier(
				$c->get( UrlFetcher::class ),
				$c->get( ContentScraper::class ),
				$c->get( SocialRepository::class ),
				$c->get( Settings::class )
			)
		);
		$c->singleton(
			SocialVerificationService::class,
			static fn( Container $c ) => new SocialVerificationService(
				$c->get( ConnectorRegistry::class ),
				$c->get( SocialAccountRepository::class ),
				$c->get( ManualVerifier::class ),
				$c->get( Crypto::class ),
				$c->get( Settings::class )
			)
		);
		$c->singleton(
			OAuthService::class,
			static fn( Container $c ) => new OAuthService(
				$c->get( ConnectorRegistry::class ),
				$c->get( SocialAccountRepository::class ),
				$c->get( Crypto::class ),
				$c->get( EventBus::class )
			)
		);
		$c->singleton( OAuthController::class, static fn( Container $c ) => new OAuthController( $c->get( OAuthService::class ), $c->get( RateLimiter::class ) ) );
		$c->singleton(
			MetricsService::class,
			static fn( Container $c ) => new MetricsService(
				$c->get( SocialRepository::class ),
				$c->get( SocialAccountRepository::class ),
				$c->get( ConnectorRegistry::class ),
				$c->get( Scheduler::class ),
				$c->get( Crypto::class )
			)
		);
		$c->singleton( SocialAdminPage::class, static fn( Container $c ) => new SocialAdminPage( $c->get( Assets::class ) ) );

		// Legacy verdict adapter (kept for back-compat; superseded by the connectors).
		$c->singleton( PlatformAdapterInterface::class, static fn( Container $c ) => new ManualPlatformAdapter( $c->get( Settings::class ) ) );

		$c->singleton(
			SocialService::class,
			static fn( Container $c ) => new SocialService(
				$c->get( SocialRepository::class ),
				$c->get( PointsLedgerInterface::class ),
				$c->get( SocialVerificationService::class ),
				$c->get( ConnectorRegistry::class ),
				$c->get( EventBus::class ),
				$c->get( Settings::class ),
				$c->has( \Yazan\Rewards\Modules\AntiFraud\RiskEngine::class ) ? $c->get( \Yazan\Rewards\Modules\AntiFraud\RiskEngine::class ) : null,
				$c->has( \Yazan\Rewards\Modules\AntiFraud\FraudReviewService::class ) ? $c->get( \Yazan\Rewards\Modules\AntiFraud\FraudReviewService::class ) : null
			)
		);
	}

	/**
	 * @inheritDoc
	 */
	public function boot( Container $c ): void {
		if ( ! $c->get( Settings::class )->feature_enabled( 'social' ) ) {
			return;
		}
		$hooks = $c->get( HookLoader::class );
		$hooks->register( $c->get( OAuthController::class ) );   // public OAuth callback.
		$hooks->register( $c->get( MetricsService::class ) );    // async metrics hook.
		$hooks->register( $c->get( SocialAdminPage::class ) );   // Yazan Rewards → Social.

		$bus = $c->get( EventBus::class );
		$bus->subscribe( $c->get( MetricsService::class ) );
		// Keep a held submission in step with its fraud-case resolution.
		$bus->subscribe( new SocialFraudReconciler( $c->get( SocialService::class ) ) );

		$rest = $c->get( RestBootstrap::class );
		$rest->add( static fn( Container $c ) => new SocialController( $c ) );
		$rest->add( static fn( Container $c ) => new SocialAdminController( $c ) );
	}

	/**
	 * @inheritDoc
	 */
	public function schema( string $charset_collate ): array {
		global $wpdb;
		$actions  = $wpdb->prefix . Database::TABLE_PREFIX . 'social_actions';
		$accounts = $wpdb->prefix . Database::TABLE_PREFIX . 'social_accounts';

		return array(
			// Submitted social content + share intents. The media_type / verify_method /
			// checks / metrics / external_id / url_hash columns are 1.7.0 additions for the
			// connector framework (dbDelta adds them in place on upgrade). url_hash indexes
			// the normalised submission URL for O(1) duplicate-content detection.
			"CREATE TABLE {$actions} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				platform varchar(30) NOT NULL DEFAULT '',
				action_type varchar(20) NOT NULL DEFAULT '',
				media_type varchar(20) NOT NULL DEFAULT '',
				target_url varchar(255) NOT NULL DEFAULT '',
				submission_url varchar(255) NOT NULL DEFAULT '',
				url_hash char(32) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'pending',
				verify_method varchar(10) NOT NULL DEFAULT '',
				points_award bigint(20) unsigned NOT NULL DEFAULT 0,
				external_id varchar(190) NOT NULL DEFAULT '',
				checks longtext NULL,
				metrics longtext NULL,
				reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
				reviewed_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY status (status),
				KEY action_type (action_type),
				KEY url_hash (url_hash)
			) {$charset_collate};",

			// Connected social accounts (one per user+platform). external_id + the
			// encrypted access/refresh tokens + expiry + scope are 1.7.0 OAuth additions.
			"CREATE TABLE {$accounts} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				platform varchar(30) NOT NULL DEFAULT '',
				handle varchar(190) NOT NULL DEFAULT '',
				external_id varchar(190) NOT NULL DEFAULT '',
				profile_url varchar(255) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'connected',
				verified tinyint(1) NOT NULL DEFAULT 0,
				access_token text NULL,
				refresh_token text NULL,
				token_expires_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				scope varchar(255) NOT NULL DEFAULT '',
				meta longtext NULL,
				connected_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY user_platform (user_id,platform),
				KEY status (status)
			) {$charset_collate};",
		);
	}
}
