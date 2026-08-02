<?php
/**
 * Aggregates every Installable's tables and runs them through dbDelta.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Core\Install;

use Yazan\Stores\Core\Container;
use Yazan\Stores\Core\Contracts\Installable;
use Yazan\Stores\Core\Database\Database;
use Yazan\Stores\Infrastructure\StoreRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema installer.
 */
final class Schema {

	public const VERSION_OPTION = 'yazan_stores_db_version';

	/** Bump when ANY schema() changes. */
	public const VERSION = '1.1.0';

	public function __construct( private Container $container ) {}

	public function is_current(): bool {
		return get_option( self::VERSION_OPTION ) === self::VERSION;
	}

	/**
	 * Create or update the tables.
	 *
	 * ⚠️ dbDelta is additive only. It adds columns and indexes; it does NOT drop a column, and it
	 * CANNOT ALTER A PRIMARY KEY — it ignores the change silently. Any future migration that needs
	 * either has to issue an explicit ALTER behind a VERSION bump, not hope dbDelta notices.
	 *
	 * @param Installable[] $installables Sources.
	 * @return void
	 */
	public function install( array $installables ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $this->container->get( Database::class )->charset_collate();

		foreach ( $installables as $installable ) {
			foreach ( $installable->schema( $collate ) as $sql ) {
				dbDelta( $sql );
			}
		}

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Everything that owns tables.
	 *
	 * The repository is included explicitly as well as any Installable module: the repository owns
	 * the DDL for the tables it reads, which keeps the CREATE statement next to the queries that
	 * depend on it rather than in a distant installer.
	 *
	 * @return Installable[]
	 */
	public function installables(): array {
		$out = array( $this->container->get( StoreRepository::class ) );

		foreach ( \Yazan\Stores\Core\Plugin::instance()->modules() as $module ) {
			if ( $module instanceof Installable ) {
				$out[] = $module;
			}
		}

		return $out;
	}
}
