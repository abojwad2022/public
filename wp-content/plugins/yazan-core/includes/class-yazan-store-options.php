<?php
/**
 * Per-store option resolution — the one mechanism every module reads its settings through.
 *
 * THE PROBLEM
 * -----------
 * Every Yazan module keeps its ENTIRE configuration tree in a single `wp_options` row:
 * `yazan_rewards_settings`, `yazan_payment_bridge_settings`, `yazan_ai_settings`,
 * `yazan_homepage_experiments`, `yazan_promo_settings`. One row for the whole installation.
 *
 * The database columns were the easy half of multi-tenancy. This is the hard half: a second store
 * cannot have different reward rules, a different payment gateway, or a different homepage
 * experiment, because there is only one row and everybody reads it.
 *
 * THE MODEL — INHERIT, THEN OVERRIDE
 * ----------------------------------
 *     read : the store's own row, if it has one; otherwise the global `wp_options` row
 *     write: the global row for the default store; the store's own row for every other store
 *
 * The global row IS store 1's configuration. Store 1 was the platform before there was anything
 * else — the same reasoning that moved store-1 grants to the platform in the permission phase. So
 * at N=1 **not one byte moves and not one behaviour changes**: reads fall through to `get_option()`
 * and writes still land in `update_option()`, exactly as they do today.
 *
 * That also makes store 1 the template a new store inherits from, which is what an operator
 * expects: create store 2 and it starts out configured like the flagship, then diverges only where
 * it is deliberately changed.
 *
 * WHY THIS LIVES IN yazan-core AND NOT IN THE STORE ENGINE
 * -------------------------------------------------------
 * Not a preference. yazan-core boots its modules SYNCHRONOUSLY at plugin-load time
 * (`yazan-core.php:87, 98, 102`), while the store engine builds its container on `plugins_loaded`
 * priority 5. A resolver that depended on that container would not exist yet at the moment the
 * modules need it. The engine is also independently deactivatable, and yazan-core must keep working
 * without it.
 *
 * So this reads the engine's settings table directly and degrades to `get_option()` when the table
 * is absent — the same posture `Yazan_Store_Schema` takes toward its sibling plugins' tables.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves a WordPress option against the current store.
 */
class Yazan_Store_Options {

	/**
	 * Settings rows carrying a resolved option are prefixed with this.
	 *
	 * The engine's own module flags use `module.`; keeping a separate namespace means a module flag
	 * and an option can never collide, and a glance at the settings table says which is which.
	 */
	const KEY_PREFIX = 'option.';

	/**
	 * The store whose configuration IS the global `wp_options` row.
	 *
	 * Mirrors `Yazan_Store_Context::DEFAULT_STORE`, restated rather than referenced so this class
	 * still resolves if the kernel mu-plugin is missing.
	 */
	const DEFAULT_STORE = 1;

	/**
	 * Resolved option values, keyed `store|epoch|option`.
	 *
	 * The epoch is bumped by `Yazan_Store_Context::run_for()` on BOTH push and pop, so an entry
	 * cached inside a store switch becomes unreachable rather than merely stale. A memo keyed only
	 * by store would still be wrong — the same store id can mean different things either side of a
	 * `flush()`.
	 *
	 * @var array<string,mixed>
	 */
	private static $memo = array();

	/**
	 * All `option.*` rows for a store, keyed `store|epoch`.
	 *
	 * Loaded in ONE query per store per request rather than one query per option. A list screen
	 * that touches four modules would otherwise issue four queries to answer questions the first
	 * one already fetched.
	 *
	 * @var array<string,array<string,string>>
	 */
	private static $rows = array();

	/**
	 * Whether the engine's settings table exists. Null until first checked.
	 *
	 * @var bool|null
	 */
	private static $table_ready = null;

	/**
	 * Read an option, resolved against a store.
	 *
	 * ⚠️ LAZY ONLY. Never call this at file-load time. `Yazan_Store_Context` forbids queries on the
	 * resolution path — a capability check there re-enters through `user_has_cap` — and this issues
	 * one. Called from a module's settings accessor, which is itself lazy, that is never a problem.
	 *
	 * @param string   $option   Option name, e.g. 'yazan_rewards_settings'.
	 * @param mixed    $default  Returned when neither the store nor the global row has a value.
	 * @param int|null $store_id Store, or null for the current one.
	 * @return mixed
	 */
	public static function get( $option, $default = array(), $store_id = null ) {
		$option = (string) $option;
		$store  = self::store( $store_id );
		$key    = self::memo_key( $store, $option );

		if ( array_key_exists( $key, self::$memo ) ) {
			return self::$memo[ $key ];
		}

		$rows  = self::rows( $store );
		$field = self::KEY_PREFIX . $option;

		if ( array_key_exists( $field, $rows ) ) {
			$decoded = json_decode( $rows[ $field ], true );

			/*
			 * A row that will not decode is treated as absent rather than as an empty value. A
			 * corrupted row should make a store fall back to the platform's configuration, not run
			 * with no configuration at all — the second failure mode is silent and much worse.
			 */
			if ( null !== $decoded || 'null' === $rows[ $field ] ) {
				self::$memo[ $key ] = $decoded;

				return $decoded;
			}
		}

		$value = get_option( $option, $default );

		self::$memo[ $key ] = $value;

		return $value;
	}

	/**
	 * Whether a store has its own value, as opposed to inheriting the platform's.
	 *
	 * The admin surface needs this to render "inherited" differently from "set to the same value" —
	 * they look identical and mean different things the next time the platform default changes.
	 *
	 * @param string   $option   Option name.
	 * @param int|null $store_id Store, or null for the current one.
	 * @return bool
	 */
	public static function has_override( $option, $store_id = null ) {
		$store = self::store( $store_id );

		if ( self::DEFAULT_STORE === $store ) {
			return false;
		}

		return array_key_exists( self::KEY_PREFIX . (string) $option, self::rows( $store ) );
	}

	/**
	 * Write an option for a store.
	 *
	 * For the default store this is `update_option()` — see the class docblock. For any other store
	 * it writes a row, leaving the platform default untouched.
	 *
	 * @param string   $option   Option name.
	 * @param mixed    $value    Value.
	 * @param int|null $store_id Store, or null for the current one.
	 * @return bool
	 */
	public static function set( $option, $value, $store_id = null ) {
		$option = (string) $option;
		$store  = self::store( $store_id );

		if ( self::DEFAULT_STORE === $store || ! self::table_ready() ) {
			$ok = update_option( $option, $value, false );

			self::flush( $option );

			return $ok;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = false !== $wpdb->replace(
			self::table(),
			array(
				'store_id'      => $store,
				'setting_key'   => self::KEY_PREFIX . $option,
				'setting_value' => (string) wp_json_encode( $value ),
				'updated_at'    => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s' )
		);

		self::flush( $option );

		return $ok;
	}

	/**
	 * Drop a store's override so it inherits the platform value again.
	 *
	 * Deleting is not the same as writing the current platform value: the store must keep tracking
	 * the default as it changes, which a copied value would not.
	 *
	 * @param string   $option   Option name.
	 * @param int|null $store_id Store, or null for the current one.
	 * @return bool
	 */
	public static function clear( $option, $store_id = null ) {
		$store = self::store( $store_id );

		if ( self::DEFAULT_STORE === $store || ! self::table_ready() ) {
			return false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = false !== $wpdb->delete(
			self::table(),
			array(
				'store_id'    => $store,
				'setting_key' => self::KEY_PREFIX . (string) $option,
			),
			array( '%d', '%s' )
		);

		self::flush( (string) $option );

		return $ok;
	}

	/**
	 * Copy every option override from one store to another.
	 *
	 * Used by the `store/cloned` subscribers: a clone that inherited the platform defaults instead
	 * of its source's configuration would not be a clone.
	 *
	 * @param int $from Source store.
	 * @param int $to   Target store.
	 * @return int Rows copied.
	 */
	public static function copy_all( $from, $to ) {
		$from = (int) $from;
		$to   = (int) $to;

		if ( $from === $to || $to < 1 || ! self::table_ready() ) {
			return 0;
		}

		$copied = 0;

		foreach ( self::rows( $from ) as $field => $raw ) {
			if ( 0 !== strpos( $field, self::KEY_PREFIX ) ) {
				continue;
			}

			$option  = substr( $field, strlen( self::KEY_PREFIX ) );
			$decoded = json_decode( $raw, true );

			if ( '' !== $option && self::set( $option, $decoded, $to ) ) {
				++$copied;
			}
		}

		return $copied;
	}

	/**
	 * Forget cached values.
	 *
	 * @param string|null $option Only this option, or null for everything.
	 * @return void
	 */
	public static function flush( $option = null ) {
		self::$rows = array();

		if ( null === $option ) {
			self::$memo = array();

			return;
		}

		$suffix = '|' . (string) $option;

		foreach ( array_keys( self::$memo ) as $key ) {
			if ( $suffix === substr( $key, -strlen( $suffix ) ) ) {
				unset( self::$memo[ $key ] );
			}
		}
	}

	/**
	 * Every option override a store carries, decoded.
	 *
	 * @param int|null $store_id Store, or null for the current one.
	 * @return array<string,mixed>
	 */
	public static function overrides( $store_id = null ) {
		$out = array();

		foreach ( self::rows( self::store( $store_id ) ) as $field => $raw ) {
			if ( 0 !== strpos( $field, self::KEY_PREFIX ) ) {
				continue;
			}

			$out[ substr( $field, strlen( self::KEY_PREFIX ) ) ] = json_decode( $raw, true );
		}

		return $out;
	}

	/**
	 * The store to resolve against.
	 *
	 * @param int|null $store_id Explicit store, or null.
	 * @return int
	 */
	private static function store( $store_id ) {
		if ( null !== $store_id ) {
			return max( 1, (int) $store_id );
		}

		return class_exists( 'Yazan_Store_Context' ) ? Yazan_Store_Context::current() : self::DEFAULT_STORE;
	}

	/**
	 * Memo key: store, epoch, option.
	 *
	 * @param int    $store  Store.
	 * @param string $option Option name.
	 * @return string
	 */
	private static function memo_key( $store, $option ) {
		$epoch = class_exists( 'Yazan_Store_Context' ) ? Yazan_Store_Context::epoch() : 0;

		return $store . '|' . $epoch . '|' . $option;
	}

	/**
	 * A store's settings rows, loaded once per store per epoch.
	 *
	 * @param int $store Store.
	 * @return array<string,string>
	 */
	private static function rows( $store ) {
		$epoch = class_exists( 'Yazan_Store_Context' ) ? Yazan_Store_Context::epoch() : 0;
		$key   = $store . '|' . $epoch;

		if ( isset( self::$rows[ $key ] ) ) {
			return self::$rows[ $key ];
		}

		if ( ! self::table_ready() ) {
			self::$rows[ $key ] = array();

			return array();
		}

		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_results(
			$wpdb->prepare(
				// The bound `LIKE` keeps the scan to option rows; module flags are the engine's business.
				"SELECT setting_key, setting_value FROM {$table} WHERE store_id = %d AND setting_key LIKE %s LIMIT 500",
				$store,
				$wpdb->esc_like( self::KEY_PREFIX ) . '%'
			),
			ARRAY_A
		);

		$rows = array();

		foreach ( (array) $found as $row ) {
			$rows[ (string) $row['setting_key'] ] = (string) $row['setting_value'];
		}

		self::$rows[ $key ] = $rows;

		return $rows;
	}

	/**
	 * The store engine's settings table.
	 *
	 * ⚠️ NAMED BY STRING, ON PURPOSE. This is a SIBLING PLUGIN'S table, so `Yazan_DB::table()` — the
	 * authority for what yazan-core owns — would be the wrong claim to make. It is the same
	 * exemption `Yazan_Store_Schema` holds and for the same reason: naming a foreign table by string
	 * is what lets this degrade quietly when that plugin is inactive instead of fatalling.
	 *
	 * @return string
	 */
	private static function table() {
		global $wpdb;

		return $wpdb->prefix . 'yazan_store_settings';
	}

	/**
	 * Whether the engine's settings table is present.
	 *
	 * Checked once per request. When the store engine is deactivated this class becomes a pure
	 * pass-through to `get_option()`, which is exactly the behaviour every module had before this
	 * phase — deactivating the engine degrades the platform to a single store rather than breaking
	 * every module that reads a setting.
	 *
	 * @return bool
	 */
	private static function table_ready() {
		if ( null !== self::$table_ready ) {
			return self::$table_ready;
		}

		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		self::$table_ready = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return self::$table_ready;
	}
}
