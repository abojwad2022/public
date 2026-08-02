<?php
/**
 * Brings an installed copy up to the current version.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Core\Install;

use Yazan\Stores\Core\Container;
use Yazan\Stores\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Version gate.
 */
final class Migrator {

	/** Stores the PLUGIN version. Schema has its own option. */
	public const VERSION_OPTION = 'yazan_stores_version';

	public function __construct( private Container $container ) {}

	/**
	 * Run pending migrations.
	 *
	 * Hooked on `init` priority 1 rather than `admin_init`: the additive DDL has to land before any
	 * front-end, REST, webhook or cron path can write, and only `init` is early enough for all
	 * four. The version comparison makes this a cheap no-op on every other request.
	 *
	 * @return void
	 */
	public function maybe_migrate(): void {
		if ( YAZAN_STORES_VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}

		$schema = $this->container->get( Schema::class );
		$schema->install( $schema->installables() );

		// Every activate() is idempotent by contract, so re-running is how new seeds arrive.
		foreach ( Plugin::instance()->modules() as $module ) {
			$module->activate( $this->container );
		}

		update_option( self::VERSION_OPTION, YAZAN_STORES_VERSION, false );

		do_action( 'yazan_stores/migrated', YAZAN_STORES_VERSION, $this->container );
	}
}
