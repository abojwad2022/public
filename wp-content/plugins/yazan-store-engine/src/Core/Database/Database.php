<?php
/**
 * The single place a store-engine table name is built.
 *
 * One method owns every table this plugin creates. That is what gives the engine one injection
 * point if the storage strategy ever changes — the same property that made the sibling plugins'
 * Database::table() the cheapest part of the multi-store audit, and the property yazan-core
 * lacked until Yazan_DB was introduced.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Core\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wpdb wrapper and table-name authority.
 */
final class Database {

	/** Segment between $wpdb->prefix and the table name. */
	public const TABLE_PREFIX = 'yazan_store_';

	/** Tables this plugin owns, without prefix. */
	public const TABLES = array( 'stores', 'domains', 'settings' );

	/**
	 * The wpdb instance.
	 *
	 * Read through a method rather than captured in a property so a db.php drop-in or a test that
	 * swaps $wpdb is honoured rather than shadowed by a stale reference.
	 *
	 * @return \wpdb
	 */
	public function wpdb(): \wpdb {
		global $wpdb;

		return $wpdb;
	}

	/**
	 * Fully-qualified table name.
	 *
	 * NOTE the naming: `wp_yazan_store_stores` looks redundant but is deliberate. The prefix marks
	 * ownership (this plugin), the suffix names the table. Collapsing it to `wp_yazan_stores` would
	 * make the ownership prefix unusable for the sibling tables (`domains`, `settings`), which are
	 * far too generic to sit unprefixed in a shared database.
	 *
	 * @param string $name One of self::TABLES.
	 * @return string
	 */
	public function table( string $name ): string {
		return $this->wpdb()->prefix . self::TABLE_PREFIX . $name;
	}

	/** @return string[] Every owned table, fully qualified. */
	public function tables(): array {
		return array_map( fn( string $n ): string => $this->table( $n ), self::TABLES );
	}

	public function charset_collate(): string {
		return $this->wpdb()->get_charset_collate();
	}
}
