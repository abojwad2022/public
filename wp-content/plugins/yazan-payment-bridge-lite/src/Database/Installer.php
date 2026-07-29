<?php
/**
 * Schema installer + version gate.
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

namespace Yazan\PaymentBridge\Database;

use Yazan\PaymentBridge\Security\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates wp_yazan_payment_events via dbDelta and keeps it current.
 *
 * The two UNIQUE keys are the plugin's idempotency guarantee (H1), so the table
 * is created with them from v1.0 onward — dbDelta cannot retro-fit a UNIQUE key
 * onto a table that already contains duplicate rows, so they must never be
 * added in a later pass.
 */
final class Installer {

	/** Option storing the installed schema version. */
	public const DB_VERSION_OPTION = 'yazan_payment_bridge_db_version';

	/** Option storing the installed plugin version. */
	public const VERSION_OPTION = 'yazan_payment_bridge_version';

	/** Current schema version. Bump when schema() changes. */
	public const DB_VERSION = '1.0.0';

	/**
	 * Activation: install schema and grant capabilities.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::install();

		$map = require YAZAN_PB_DIR . 'config/capabilities.php';
		( new Capabilities( is_array( $map ) ? $map : array() ) )->grant();

		update_option( self::VERSION_OPTION, YAZAN_PB_VERSION, false );
	}

	/**
	 * Run dbDelta. Safe to call repeatedly.
	 *
	 * @return void
	 */
	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$db = new Database();

		foreach ( self::schema( $db ) as $sql ) {
			dbDelta( $sql );
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Whether the installed schema is current.
	 *
	 * @return bool
	 */
	public static function is_current(): bool {
		return get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION
			&& get_option( self::VERSION_OPTION ) === YAZAN_PB_VERSION;
	}

	/**
	 * Re-run the installer after a plugin/schema version bump.
	 *
	 * Hooked on `init` priority 1 rather than `admin_init`: a gateway webhook or
	 * a cron pass can write events long before anyone visits wp-admin, and those
	 * writes must never hit an outdated table shape.
	 *
	 * @return void
	 */
	public static function maybe_migrate(): void {
		if ( self::is_current() ) {
			return;
		}

		self::install();
		update_option( self::VERSION_OPTION, YAZAN_PB_VERSION, false );

		/**
		 * Fires after the Bridge's schema has been installed or upgraded.
		 *
		 * @param string $version Plugin version now installed.
		 */
		do_action( 'yazan_payment_bridge/migrated', YAZAN_PB_VERSION );
	}

	/**
	 * CREATE TABLE statements.
	 *
	 * dbDelta is fussy: lowercase column types, two spaces after PRIMARY KEY,
	 * KEY (not INDEX), and one field per line.
	 *
	 * @param Database $db Database helper.
	 * @return string[]
	 */
	public static function schema( Database $db ): array {
		$table   = $db->events_table();
		$collate = $db->charset_collate();

		return array(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_uuid char(36) NOT NULL DEFAULT '',
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_type varchar(32) NOT NULL DEFAULT '',
				source varchar(16) NOT NULL DEFAULT 'gateway',
				gateway varchar(64) NOT NULL DEFAULT '',
				transaction_id varchar(191) NULL DEFAULT NULL,
				amount decimal(19,4) NOT NULL DEFAULT 0,
				currency char(3) NOT NULL DEFAULT '',
				payment_status varchar(32) NOT NULL DEFAULT '',
				integration_status varchar(16) NOT NULL DEFAULT 'pending',
				processed tinyint(1) NOT NULL DEFAULT 0,
				error_message varchar(500) NULL DEFAULT NULL,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY uq_order_event (order_id,event_type),
				UNIQUE KEY uq_event_uuid (event_uuid),
				KEY idx_transaction_id (transaction_id),
				KEY idx_integration_status (integration_status)
			) {$collate};",
		);
	}
}
