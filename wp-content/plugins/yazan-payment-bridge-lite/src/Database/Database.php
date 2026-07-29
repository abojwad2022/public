<?php
/**
 * $wpdb wrapper.
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

namespace Yazan\PaymentBridge\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralises access to $wpdb and this plugin's table naming.
 *
 * The prefix segment is `yazan_payment_`, so table( 'events' ) resolves to
 * `{$wpdb->prefix}yazan_payment_events` — the table name the specification
 * requires, while still giving uninstall.php a prefix it can safely enumerate.
 */
final class Database {

	/** Table-name prefix segment owned by this plugin (after the site prefix). */
	public const TABLE_PREFIX = 'yazan_payment_';

	/**
	 * The global $wpdb instance.
	 *
	 * @return \wpdb
	 */
	public function wpdb(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	/**
	 * Fully-qualified table name: {site_prefix}yazan_payment_{$name}.
	 *
	 * @param string $name Bare table name (e.g. "events").
	 * @return string
	 */
	public function table( string $name ): string {
		return $this->wpdb()->prefix . self::TABLE_PREFIX . $name;
	}

	/**
	 * The payment-events table.
	 *
	 * @return string
	 */
	public function events_table(): string {
		return $this->table( 'events' );
	}

	/**
	 * Charset/collate clause for CREATE TABLE.
	 *
	 * @return string
	 */
	public function charset_collate(): string {
		return $this->wpdb()->get_charset_collate();
	}

	/**
	 * Current UTC time in MySQL DATETIME format.
	 *
	 * @return string
	 */
	public function now(): string {
		return current_time( 'mysql', true );
	}
}
