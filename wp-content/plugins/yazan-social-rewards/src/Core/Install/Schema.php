<?php
/**
 * Schema aggregator + installer.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Install;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Contracts\Installable;
use Yazan\Rewards\Core\Database\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects CREATE TABLE statements from every module that implements
 * {@see Installable} and runs them through dbDelta(). Idempotent — dbDelta only
 * applies diffs — and gated by a schema-version option so it runs on install and
 * on version bumps, never on every request.
 */
final class Schema {

	/** Option storing the installed schema version. */
	public const VERSION_OPTION = 'yazan_rewards_db_version';

	/** Current schema version. Bump when any module's schema() changes. */
	public const VERSION = '1.11.0'; // 1.11.0: public yazan/v1 REST API + developer registration hooks + reward-provider seam (no table change).

	private Database $db;

	/**
	 * @param Container $container Service container.
	 */
	public function __construct( private Container $container ) {
		$this->db = $container->get( Database::class );
	}

	/**
	 * Whether the installed schema is current.
	 *
	 * @return bool
	 */
	public function is_current(): bool {
		return get_option( self::VERSION_OPTION ) === self::VERSION;
	}

	/**
	 * Install/upgrade all tables. Safe to call repeatedly.
	 *
	 * @param Installable[] $installables Modules contributing schema.
	 * @return void
	 */
	public function install( array $installables ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate    = $this->db->charset_collate();
		$statements = array();

		foreach ( $installables as $installable ) {
			foreach ( $installable->schema( $collate ) as $sql ) {
				$statements[] = $sql;
			}
		}

		foreach ( $statements as $sql ) {
			dbDelta( $sql );
		}

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Pull the Installable modules out of the module list.
	 *
	 * @return Installable[]
	 */
	public function installable_modules(): array {
		$plugin  = $this->container->get( \Yazan\Rewards\Core\Plugin::class );
		$modules = $plugin->modules();
		return array_values( array_filter( $modules, static fn( $m ) => $m instanceof Installable ) );
	}
}
