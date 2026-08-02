<?php
/**
 * Yazan RBAC — permission resolution and caching.
 *
 * Resolves a user to the union of every permission their roles grant. Because this is consulted on
 * every screen, every button and every REST call, it is cached in three tiers, cheapest first:
 *
 *   0. a static memo, for the many checks inside one request;
 *   1. wp_cache, which is per-request today and becomes cross-request the moment a persistent
 *      object cache (Redis/Memcached) is dropped in;
 *   2. a usermeta snapshot, which IS cross-request today — this site has no object-cache drop-in,
 *      so tier 2 is the one actually carrying the load. It costs nothing extra to read: WordPress
 *      must already prime the current user's usermeta to read `wp_capabilities`, so the snapshot
 *      arrives in the same batch.
 *
 * Invalidation cannot go stale, because the version is part of the cache KEY rather than the
 * value. A stale entry is unreachable rather than merely wrong, so there is no delete to forget.
 * The version folds in YAZAN_CORE_VERSION too, so a deploy that changes the catalog can never
 * serve a snapshot built from the old one.
 *
 * Multiple roles union additively. There are deliberately no deny rules: a deny primitive makes
 * the union order-dependent and produces the classic "adding a role removed my access" bug.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves and caches effective permissions.
 */
class Yazan_Permissions {

	/** Object-cache group. Not registered as non-persistent — we want persistence when it exists. */
	const CACHE_GROUP = 'yazan_rbac';

	/** Usermeta key holding the cross-request snapshot. */
	const SNAPSHOT_META = '_yazan_rbac_snapshot';

	/** Autoloaded counter bumped on every RBAC write. */
	const VERSION_OPTION = 'yazan_rbac_version';

	/**
	 * Per-request memo, keyed "user_id|version".
	 *
	 * @var array<string,array>
	 */
	private static $memo = array();

	/**
	 * Cache-busting token. Any RBAC write bumps it; a deploy changes it implicitly.
	 *
	 * @return string
	 */
	public static function version() {
		return YAZAN_CORE_VERSION . ':' . (int) get_option( self::VERSION_OPTION, 0 );
	}

	/**
	 * Invalidate every cached permission set.
	 *
	 * Global rather than per-user on purpose: with a staff of ten, recomputing is two indexed
	 * queries per active user, which is far cheaper than reasoning about partial invalidation and
	 * getting it subtly wrong.
	 *
	 * @return void
	 */
	public static function bump() {
		$next = (int) get_option( self::VERSION_OPTION, 0 ) + 1;
		// Autoloaded: read on every permission check, so it must live in alloptions.
		update_option( self::VERSION_OPTION, $next, true );
		self::$memo = array();
	}

	/**
	 * Drop one user's snapshot, forcing a rebuild on their next request.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function flush_user( $user_id ) {
		$user_id = absint( $user_id );

		/*
		 * Drop the snapshot for the ACTIVE store as well as the un-suffixed store-1 key.
		 *
		 * With one store these are the same row, so this is a no-op today. It is written this way
		 * because a flush that silently missed a store would leave a stale permission set readable
		 * — the one failure this whole subsystem is built to make impossible.
		 */
		delete_user_meta( $user_id, self::SNAPSHOT_META );

		$store = self::store();
		if ( 1 !== $store ) {
			delete_user_meta( $user_id, self::snapshot_meta_key( $store ) );
		}

		self::$memo = array();
	}

	/* --------------------------------------------------------------------- */
	/* Resolution                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * The active store, or the caller's explicit choice.
	 *
	 * Resolution is delegated to the mu-plugin so this class never has to know how a store is
	 * determined. The literal fallback matters: this method sits on the `user_has_cap` path, which
	 * runs on requests where an mu-plugin may legitimately be absent (a partial deploy, a rescue
	 * copy of the site), and a fatal there takes down wp-login.php with it.
	 *
	 * @param int|null $store_id Explicit store, or null for the active one.
	 * @return int
	 */
	private static function store( $store_id = null ) {
		if ( null !== $store_id ) {
			return absint( $store_id );
		}

		return class_exists( 'Yazan_Store_Context' ) ? Yazan_Store_Context::current() : 1;
	}

	/**
	 * The user-meta key holding a user's cached permission snapshot for a store.
	 *
	 * Store 1 keeps the original, un-suffixed key. That is what makes this change a no-op today —
	 * every existing `_yazan_rbac_snapshot` row stays valid and nothing needs migrating — while a
	 * second store gets its own row rather than a shared array. A shared array would be worse than
	 * verbose: it would turn every snapshot write into a read-modify-write on a hot path, with two
	 * requests for different stores able to clobber each other.
	 *
	 * @param int $store_id Store.
	 * @return string
	 */
	private static function snapshot_meta_key( $store_id ) {
		return 1 === (int) $store_id
			? self::SNAPSHOT_META
			: self::SNAPSHOT_META . '_s' . (int) $store_id;
	}

	/**
	 * The effective permission set for a user, in a store.
	 *
	 * @param int|null $user_id  User id, or null for the current user.
	 * @param int|null $store_id Store id, or null for the active store.
	 * @return array{super:bool,perms:array<string,true>,roles:int[]}
	 */
	public static function for_user( $user_id = null, $store_id = null ) {
		$user_id = ( null === $user_id ) ? get_current_user_id() : absint( $user_id );

		if ( $user_id <= 0 ) {
			return self::blank();
		}

		$store = self::store( $store_id );

		/*
		 * THE STORE GOES IN THE KEY, AT ALL THREE TIERS.
		 *
		 * This class already documents why the version is part of the cache KEY rather than its
		 * value: "a stale entry is unreachable rather than merely wrong, so there is no delete to
		 * forget". The store dimension is added the same way and inherits the same property — no
		 * new invalidation path, nothing to remember to flush when the active store changes.
		 *
		 * Getting this wrong is the highest-consequence bug available in a store_id design: a memo
		 * populated under store A and read under store B is a privilege set crossing tenants inside
		 * a single request, with no error and no log.
		 *
		 * Today every store resolves to 1, so every key below is byte-identical to what it was.
		 */
		$version  = self::version();
		$memo_key = $user_id . '|' . $store . '|' . $version;

		if ( isset( self::$memo[ $memo_key ] ) ) {
			return self::$memo[ $memo_key ];
		}

		$cache_group = self::cache_group( $store );
		$cache_key   = "perm:{$user_id}:{$store}:{$version}";
		$cached      = wp_cache_get( $cache_key, $cache_group );
		if ( is_array( $cached ) && isset( $cached['perms'] ) ) {
			self::$memo[ $memo_key ] = $cached;
			return $cached;
		}

		$snapshot_key = self::snapshot_meta_key( $store );
		$snapshot     = get_user_meta( $user_id, $snapshot_key, true );
		if ( is_array( $snapshot ) && isset( $snapshot['v'] ) && $snapshot['v'] === $version ) {
			$set = array(
				'super' => ! empty( $snapshot['super'] ),
				'perms' => is_array( $snapshot['perms'] ) ? $snapshot['perms'] : array(),
				'roles' => is_array( $snapshot['roles'] ) ? $snapshot['roles'] : array(),
			);

			self::$memo[ $memo_key ] = $set;
			wp_cache_set( $cache_key, $set, $cache_group, HOUR_IN_SECONDS );
			return $set;
		}

		$set = self::resolve( $user_id );

		self::$memo[ $memo_key ] = $set;
		wp_cache_set( $cache_key, $set, $cache_group, HOUR_IN_SECONDS );
		update_user_meta(
			$user_id,
			$snapshot_key,
			array(
				'v'     => $version,
				'super' => $set['super'],
				'perms' => $set['perms'],
				'roles' => $set['roles'],
			)
		);

		return $set;
	}

	/**
	 * Object-cache group for a store.
	 *
	 * Store 1 keeps the original group name, for the same no-op reason as the snapshot key.
	 *
	 * @param int $store_id Store.
	 * @return string
	 */
	private static function cache_group( $store_id ) {
		return 1 === (int) $store_id
			? self::CACHE_GROUP
			: self::CACHE_GROUP . '_s' . (int) $store_id;
	}

	/**
	 * Read the roles and grants for a user. Exactly two queries on a cold cache.
	 *
	 * @param int $user_id User id.
	 * @return array{super:bool,perms:array<string,true>,roles:int[]}
	 */
	private static function resolve( $user_id ) {
		$set = self::blank();

		/*
		 * A real WordPress administrator is always treated as a Yazan super admin. This is the
		 * lockout backstop: it holds before the tables exist, before the backfill runs, and even if
		 * every Yazan role row is deleted.
		 *
		 * The check reads WP_User::$roles DIRECTLY rather than calling user_can(). This method runs
		 * inside the `user_has_cap` filter, and any capability API call there re-enters the filter
		 * and recurses until the process dies.
		 */
		$user = get_userdata( $user_id );
		if ( $user && in_array( 'administrator', (array) $user->roles, true ) ) {
			$set['super'] = true;
		}

		if ( ! Yazan_RBAC_Schema::is_installed() ) {
			if ( $set['super'] ) {
				$set['perms'] = array_fill_keys( Yazan_Permission_Registry::active_slugs(), true );
			}
			return $set;
		}

		global $wpdb;
		$pivot = Yazan_RBAC_Schema::user_roles_table();
		$roles = Yazan_RBAC_Schema::roles_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT r.id, r.is_super FROM {$pivot} ur JOIN {$roles} r ON r.id = ur.role_id WHERE ur.user_id = %d", $user_id )
		);

		foreach ( (array) $rows as $row ) {
			$set['roles'][] = (int) $row->id;
			if ( (int) $row->is_super ) {
				$set['super'] = true;
			}
		}

		if ( $set['super'] ) {
			// Materialise the full active catalog so the SPA gets a complete map. can() still
			// short-circuits on `super` and never reads this.
			$set['perms'] = array_fill_keys( Yazan_Permission_Registry::active_slugs(), true );
			return $set;
		}

		if ( ! $set['roles'] ) {
			return $set;
		}

		$grants       = Yazan_RBAC_Schema::role_permissions_table();
		$placeholders = implode( ',', array_fill( 0, count( $set['roles'] ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$slugs = $wpdb->get_col(
			$wpdb->prepare( "SELECT DISTINCT permission_slug FROM {$grants} WHERE role_id IN ({$placeholders})", $set['roles'] )
		);

		$set['perms'] = array_fill_keys( (array) $slugs, true );

		return $set;
	}

	/**
	 * An empty permission set.
	 *
	 * @return array{super:bool,perms:array,roles:array}
	 */
	private static function blank() {
		return array(
			'super' => false,
			'perms' => array(),
			'roles' => array(),
		);
	}

	/* --------------------------------------------------------------------- */
	/* Checks                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * Does this user hold a permission?
	 *
	 * @param string   $slug    Permission slug, e.g. 'orders.refund'.
	 * @param int|null $user_id User id, or null for the current user.
	 * @return bool
	 */
	public static function can( $slug, $user_id = null ) {
		$set = self::for_user( $user_id );

		if ( $set['super'] ) {
			return true;
		}

		return isset( $set['perms'][ $slug ] );
	}

	/**
	 * Does this user hold any of these permissions?
	 *
	 * @param string[] $slugs   Permission slugs.
	 * @param int|null $user_id User id.
	 * @return bool
	 */
	public static function can_any( array $slugs, $user_id = null ) {
		$set = self::for_user( $user_id );

		if ( $set['super'] ) {
			return true;
		}

		foreach ( $slugs as $slug ) {
			if ( isset( $set['perms'][ $slug ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Is this user a super admin (Yazan role flagged is_super, or a WordPress administrator)?
	 *
	 * @param int|null $user_id User id.
	 * @return bool
	 */
	public static function is_super( $user_id = null ) {
		$set = self::for_user( $user_id );
		return (bool) $set['super'];
	}

	/**
	 * The permission slugs a user may grant to a role.
	 *
	 * You can never hand out access you do not hold yourself — this is the frontend half of the
	 * privilege-escalation guard, returned with the catalog so the Role Editor can disable the
	 * rest without a second round trip. {@see Yazan_RBAC_Guard} enforces the same rule server-side.
	 *
	 * @param int|null $user_id User id.
	 * @return string[]
	 */
	public static function grantable_by( $user_id = null ) {
		$set = self::for_user( $user_id );

		if ( $set['super'] ) {
			return Yazan_Permission_Registry::slugs();
		}

		return array_keys( $set['perms'] );
	}

	/**
	 * The permission map the SPA branches on: `slug => true`.
	 *
	 * @param int|null $user_id User id.
	 * @return array<string,bool>
	 */
	public static function map_for_client( $user_id = null ) {
		$set = self::for_user( $user_id );
		return $set['perms'];
	}
}
