<?php
/**
 * Activity log module.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Activity;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Contracts\Installable;
use Yazan\Rewards\Core\Database\Database;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Module\AbstractModule;
use Yazan\Rewards\Core\Rest\RestBootstrap;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Rest\V1\ActivityController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the `activity_logs` table and the event-driven activity logger + customer
 * feed REST. No hard module deps — it only listens on the event bus, so it can be
 * added or removed freely.
 */
final class ActivityModule extends AbstractModule implements Installable {

	/**
	 * @inheritDoc
	 */
	public function id(): string {
		return 'activity';
	}

	/**
	 * @inheritDoc
	 */
	public function register( Container $c ): void {
		$c->singleton( ActivityRepository::class, static fn( Container $c ) => new ActivityRepository( $c->get( Database::class ) ) );
		$c->singleton( ActivityLogger::class, static fn( Container $c ) => new ActivityLogger( $c->get( ActivityRepository::class ), $c->get( Settings::class ) ) );
	}

	/**
	 * @inheritDoc
	 */
	public function boot( Container $c ): void {
		if ( ! $c->get( Settings::class )->feature_enabled( 'activity' ) ) {
			return;
		}
		$c->get( EventBus::class )->subscribe( $c->get( ActivityLogger::class ) );
		$c->get( RestBootstrap::class )->add( static fn( Container $c ) => new ActivityController( $c ) );
	}

	/**
	 * @inheritDoc
	 */
	public function schema( string $charset_collate ): array {
		global $wpdb;
		$table = $wpdb->prefix . Database::TABLE_PREFIX . 'activity_logs';

		return array(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				activity_type varchar(40) NOT NULL DEFAULT '',
				description varchar(255) NOT NULL DEFAULT '',
				object_type varchar(30) NOT NULL DEFAULT '',
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				points bigint(20) NOT NULL DEFAULT 0,
				meta longtext NULL,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY activity_type (activity_type),
				KEY created_at (created_at)
			) {$charset_collate};",
		);
	}
}
