<?php
/**
 * Analytics engine module.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Analytics;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Contracts\Installable;
use Yazan\Rewards\Core\Database\Database;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Hooks\HookLoader;
use Yazan\Rewards\Core\Module\AbstractModule;
use Yazan\Rewards\Core\Rest\RestBootstrap;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Core\Support\Assets;
use Yazan\Rewards\Core\Support\Money;
use Yazan\Rewards\Admin\AnalyticsAdminPage;
use Yazan\Rewards\Rest\V1\AnalyticsController;
use Yazan\Rewards\Rest\V1\ReportsController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the `analytics_daily` rollup table, the event-driven rollup subscriber,
 * the liability calculator, and the admin reports REST surface.
 */
final class AnalyticsModule extends AbstractModule implements Installable {

	/**
	 * @inheritDoc
	 */
	public function id(): string {
		return 'analytics';
	}

	/**
	 * @inheritDoc
	 */
	public function register( Container $c ): void {
		$c->singleton( ReportRepository::class, static fn( Container $c ) => new ReportRepository( $c->get( Database::class ) ) );
		$c->singleton( RollupService::class, static fn( Container $c ) => new RollupService( $c->get( ReportRepository::class ) ) );
		$c->singleton( LiabilityCalculator::class, static fn( Container $c ) => new LiabilityCalculator( $c, $c->get( Money::class ) ) );
		$c->singleton(
			AnalyticsMetrics::class,
			static fn( Container $c ) => new AnalyticsMetrics(
				$c,
				$c->get( Database::class ),
				$c->get( ReportRepository::class ),
				$c->get( LiabilityCalculator::class ),
				$c->get( Money::class ),
				$c->get( Settings::class )
			)
		);
		$c->singleton( AnalyticsAdminPage::class, static fn( Container $c ) => new AnalyticsAdminPage( $c->get( Assets::class ) ) );
	}

	/**
	 * @inheritDoc
	 */
	public function boot( Container $c ): void {
		if ( ! $c->get( Settings::class )->feature_enabled( 'analytics' ) ) {
			return;
		}
		$c->get( EventBus::class )->subscribe( $c->get( RollupService::class ) );
		$c->get( HookLoader::class )->register( $c->get( AnalyticsAdminPage::class ) );

		$rest = $c->get( RestBootstrap::class );
		$rest->add( static fn( Container $c ) => new ReportsController( $c ) );
		$rest->add( static fn( Container $c ) => new AnalyticsController( $c ) );
	}

	/**
	 * @inheritDoc
	 */
	public function schema( string $charset_collate ): array {
		global $wpdb;
		$table = $wpdb->prefix . Database::TABLE_PREFIX . 'analytics_daily';

		return array(
			"CREATE TABLE {$table} (
				stat_date date NOT NULL DEFAULT '0000-00-00',
				metric varchar(40) NOT NULL DEFAULT '',
				dimension varchar(60) NOT NULL DEFAULT '',
				value decimal(19,4) NOT NULL DEFAULT 0,
				PRIMARY KEY  (stat_date,metric,dimension),
				KEY metric (metric)
			) {$charset_collate};",
		);
	}
}
