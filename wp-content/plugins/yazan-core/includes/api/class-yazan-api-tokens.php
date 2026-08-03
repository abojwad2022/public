<?php
/**
 * API tokens — store-scoped bearer credentials.
 *
 * THIS REVERSES A RECORDED NON-GOAL. `DASHBOARD.md` said "Credentials are deliberately out of
 * scope. API keys are long-lived bearer secrets…". That refusal was correct for a dashboard with
 * one tenant and a cookie session. It stops being correct the moment external systems must reach a
 * specific store's data. What makes the reversal safe is everything the original objection was
 * about: the secret is hashed and never recoverable, the credential expires, it is revocable, it is
 * bounded to ONE store, and its permissions are the intersection of what it was granted and what
 * its owner still holds.
 *
 * THREE PROPERTIES THAT ARE NOT NEGOTIABLE
 * ---------------------------------------
 * 1. **The store is a property of the credential, not of the request.** No parameter, no header and
 *    no route argument can move a token to another store. The IDOR fixed in the permission phase —
 *    where a query parameter decided which store was authorised — must not be re-created here.
 *
 * 2. **A token can never exceed its owner.** Every check is
 *    `in_array($slug, $scopes) AND Yazan_Permissions::can($slug, $owner, $store)`. Downgrade the
 *    human and their tokens narrow instantly, with nothing to remember to revoke.
 *
 * 3. **A platform-scoped permission is refused, twice.** Once at creation and again at check time.
 *    The second is not belt-and-braces: `Yazan_Permissions::can()` answers a platform slug from
 *    `platform_perms`, IGNORING the store — so a token whose OWNER holds platform authority would
 *    pass a platform check even though the credential is store-bound. The refusal has to happen
 *    before `can()` is consulted.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Issues, verifies and revokes API tokens.
 */
class Yazan_API_Tokens {

	/** Bump when the DDL below changes. */
	const SCHEMA_VERSION = '1';

	/** Option holding the installed schema version. */
	const SCHEMA_OPTION = 'yazan_api_tokens_schema';

	/**
	 * Option bumped on every revocation.
	 *
	 * ⚠️ THE VERSION IS PART OF THE CACHE KEY, NOT THE VALUE — the idiom `Yazan_Permissions`
	 * documents for exactly this reason: a stale entry becomes UNREACHABLE rather than merely
	 * wrong, so there is no cache delete to forget. Without it a revoked token keeps working until
	 * the memo expires, and "revocation returns 200 and the credential still works" is the
	 * definition of a silent security failure.
	 */
	const VERSION_OPTION = 'yazan_api_token_version';

	/** Secret prefix, so a leaked string is recognisable in a log or a paste. */
	const SECRET_PREFIX = 'yz_live_';

	/** Default lifetime when the caller does not choose one. */
	const DEFAULT_TTL_DAYS = 90;

	/**
	 * Verified tokens for this request, keyed by hash and revocation version.
	 *
	 * @var array<string,array|null>
	 */
	private static $memo = array();

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table() {
		return Yazan_DB::table( 'api_tokens' );
	}

	/**
	 * Create the table.
	 *
	 * @return void
	 */
	public static function install_table() {
		if ( get_option( self::SCHEMA_OPTION ) === self::SCHEMA_VERSION ) {
			return;
		}

		global $wpdb;

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		/*
		 * ⚠️ `store_id ... DEFAULT 0`, NOT `DEFAULT 1`.
		 *
		 * The platform rule is "`DEFAULT 1` on the COLUMN, never in the QUERY" — but that rule is
		 * about backfilling rows written BEFORE the column existed. This table is born tenant-aware
		 * and has no such rows. Here `DEFAULT 1` would mean "a token that forgot to name a store
		 * silently becomes a store-1 token", which is a credential-forging default.
		 *
		 * 0 is `Yazan_Permissions::PLATFORM_STORE`. Both `issue()` and `verify()` REFUSE it, so the
		 * default is a value that cannot authenticate rather than one that authenticates as the
		 * flagship store.
		 */
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			store_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			type varchar(20) NOT NULL DEFAULT 'machine',
			name varchar(191) NOT NULL DEFAULT '',
			token_hash char(64) NOT NULL DEFAULT '',
			last4 char(4) NOT NULL DEFAULT '',
			scopes longtext NULL,
			rate_limit int(10) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'active',
			last_used_at datetime NULL DEFAULT NULL,
			expires_at datetime NULL DEFAULT NULL,
			revoked_at datetime NULL DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY store_status (store_id,status),
			KEY user_id (user_id),
			KEY expires_at (expires_at)
		) {$collate};";

		dbDelta( $sql );

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Mint a token.
	 *
	 * The plaintext secret is returned HERE AND NOWHERE ELSE, ever. Only its SHA-256 is stored, so
	 * there is nothing to leak later and nothing to recover if the holder loses it.
	 *
	 * @param array $args store_id, user_id, name, scopes, type, expires_in_days.
	 * @return array|WP_Error { id, secret, last4, expires_at }
	 */
	public static function issue( array $args ) {
		global $wpdb;

		$store_id = (int) ( $args['store_id'] ?? 0 );
		$user_id  = (int) ( $args['user_id'] ?? get_current_user_id() );
		$scopes   = array_values( array_unique( array_map( 'sanitize_key', (array) ( $args['scopes'] ?? array() ) ) ) );

		if ( $store_id < 1 ) {
			return new WP_Error( 'yazan_api_no_store', __( 'A token must name the store it belongs to.', 'yazan' ), array( 'status' => 400 ) );
		}

		if ( $user_id < 1 ) {
			return new WP_Error( 'yazan_api_no_owner', __( 'A token must have an owner.', 'yazan' ), array( 'status' => 400 ) );
		}

		if ( array() === $scopes ) {
			return new WP_Error( 'yazan_api_no_scopes', __( 'A token with no scopes could do nothing.', 'yazan' ), array( 'status' => 400 ) );
		}

		foreach ( $scopes as $slug ) {
			// Refusal ①: platform authority is never delegated to a store-bound credential.
			if ( class_exists( 'Yazan_Permission_Registry' ) && Yazan_Permission_Registry::is_platform( $slug ) ) {
				return new WP_Error(
					'yazan_api_platform_scope',
					/* translators: %s: permission slug. */
					sprintf( __( '"%s" reaches the whole platform and cannot be given to a store token.', 'yazan' ), $slug ),
					array( 'status' => 400 )
				);
			}

			/*
			 * Refusal ②: you cannot mint a token stronger than yourself. Reuses the SAME escalation
			 * guard that governs role assignment, so the two paths cannot drift apart.
			 */
			if ( class_exists( 'Yazan_Permissions' ) && ! Yazan_Permissions::can( $slug, $user_id, $store_id ) ) {
				return new WP_Error(
					'yazan_api_scope_escalation',
					/* translators: %s: permission slug. */
					sprintf( __( 'You do not hold "%s" in this store, so a token cannot be given it.', 'yazan' ), $slug ),
					array( 'status' => 403 )
				);
			}
		}

		$secret = self::SECRET_PREFIX . self::random();
		$days   = max( 0, (int) ( $args['expires_in_days'] ?? self::DEFAULT_TTL_DAYS ) );

		// 0 days means "never expires". Allowed, but never the default — an unbounded credential
		// has to be a deliberate act, and `created_by` records whose act it was.
		$expires = $days > 0 ? gmdate( 'Y-m-d H:i:s', time() + ( $days * DAY_IN_SECONDS ) ) : null;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->insert(
			self::table(),
			array(
				'store_id'   => $store_id,
				'user_id'    => $user_id,
				'created_by' => get_current_user_id(),
				'type'       => in_array( $args['type'] ?? 'machine', array( 'machine', 'session' ), true ) ? $args['type'] : 'machine',
				'name'       => substr( sanitize_text_field( (string) ( $args['name'] ?? '' ) ), 0, 191 ),
				'token_hash' => hash( 'sha256', $secret ),
				'last4'      => substr( $secret, -4 ),
				'scopes'     => (string) wp_json_encode( $scopes ),
				'rate_limit' => absint( $args['rate_limit'] ?? 0 ),
				'status'     => 'active',
				'expires_at' => $expires,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( ! $ok ) {
			return new WP_Error( 'yazan_api_token_failed', __( 'The token could not be created.', 'yazan' ), array( 'status' => 500 ) );
		}

		return array(
			'id'         => (int) $wpdb->insert_id,
			'secret'     => $secret,
			'last4'      => substr( $secret, -4 ),
			'expires_at' => $expires,
		);
	}

	/**
	 * Resolve a presented secret to its token row.
	 *
	 * @param string $secret The bearer value.
	 * @return array|null
	 */
	public static function verify( $secret ) {
		$secret = (string) $secret;

		if ( '' === $secret || 0 !== strpos( $secret, self::SECRET_PREFIX ) ) {
			return null;
		}

		$hash = hash( 'sha256', $secret );
		$key  = $hash . '|' . (int) get_option( self::VERSION_OPTION, 0 );

		if ( array_key_exists( $key, self::$memo ) ) {
			return self::$memo[ $key ];
		}

		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token_hash = %s", $hash ), ARRAY_A );

		self::$memo[ $key ] = self::usable( $row, $hash ) ? self::shape( $row ) : null;

		return self::$memo[ $key ];
	}

	/**
	 * Is this row a credential that may authenticate right now?
	 *
	 * @param array|null $row  Row.
	 * @param string     $hash The presented hash.
	 * @return bool
	 */
	private static function usable( $row, $hash ) {
		if ( ! is_array( $row ) ) {
			return false;
		}

		// Constant-time, though the lookup was by an indexed unique hash. Stated intent, not theatre.
		if ( ! hash_equals( (string) $row['token_hash'], $hash ) ) {
			return false;
		}

		if ( 'active' !== $row['status'] ) {
			return false;
		}

		// ⚠️ Store 0 is PLATFORM_STORE. A row carrying it would make a store credential answer
		// platform questions, so it is refused here as well as at creation.
		if ( (int) $row['store_id'] < 1 || (int) $row['user_id'] < 1 ) {
			return false;
		}

		if ( ! empty( $row['expires_at'] ) && strtotime( (string) $row['expires_at'] . ' UTC' ) < time() ) {
			return false;
		}

		return true;
	}

	/**
	 * Normalise a row into the shape the rest of the API uses.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function shape( array $row ) {
		$scopes = json_decode( (string) $row['scopes'], true );

		/*
		 * ⚠️ DECODE FAILURE IS DENY, NEVER A DEFAULT. A `?: array('*')` here would read as
		 * defensive coding and would be silent, total access on a corrupted row.
		 */
		return array(
			'id'         => (int) $row['id'],
			'store_id'   => (int) $row['store_id'],
			'user_id'    => (int) $row['user_id'],
			'type'       => (string) $row['type'],
			'scopes'     => is_array( $scopes ) ? array_map( 'strval', $scopes ) : array(),
			'rate_limit' => (int) $row['rate_limit'],
		);
	}

	/**
	 * May this token do this, in its own store?
	 *
	 * @param array  $token Token as returned by verify().
	 * @param string $slug  Permission slug.
	 * @return bool
	 */
	public static function can( array $token, $slug ) {
		$slug = (string) $slug;

		/*
		 * ⚠️ THE PLATFORM REFUSAL COMES FIRST, BEFORE `Yazan_Permissions::can()`.
		 *
		 * That method answers a platform-scoped slug from the user's PLATFORM grants and ignores
		 * the store entirely. So a token owned by a platform administrator would pass
		 * `can('users.delete', $owner, 2)` — the store argument would not save us. The credential's
		 * own scope has to be the thing that refuses.
		 */
		if ( class_exists( 'Yazan_Permission_Registry' ) && Yazan_Permission_Registry::is_platform( $slug ) ) {
			return false;
		}

		if ( ! self::in_scope( $token, $slug ) ) {
			return false;
		}

		// The intersection: the token was granted it AND the owner still holds it, in this store.
		return class_exists( 'Yazan_Permissions' )
			&& Yazan_Permissions::can( $slug, $token['user_id'], $token['store_id'] );
	}

	/**
	 * Does the token's scope list cover a slug?
	 *
	 * `products.*` is honoured; a bare `*` is not — a wildcard over everything is indistinguishable
	 * from a decode failure and there is no legitimate use for it on a store-bound credential.
	 *
	 * @param array  $token Token.
	 * @param string $slug  Slug.
	 * @return bool
	 */
	private static function in_scope( array $token, $slug ) {
		foreach ( $token['scopes'] as $scope ) {
			if ( $scope === $slug ) {
				return true;
			}

			if ( '.*' === substr( $scope, -2 ) && 0 === strpos( $slug, substr( $scope, 0, -1 ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Revoke a token.
	 *
	 * @param int $id Token id.
	 * @return bool
	 */
	public static function revoke( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->update(
			self::table(),
			array(
				'status'     => 'revoked',
				'revoked_at' => current_time( 'mysql', true ),
			),
			array(
				'id'       => (int) $id,
				// Scoped: an administrator of one store cannot revoke another store's credential.
				'store_id' => Yazan_DB::store_id(),
			),
			array( '%s', '%s' ),
			array( '%d', '%d' )
		);

		// Every memo keyed on the old version becomes unreachable the moment this moves.
		self::bump();

		return false !== $ok;
	}

	/**
	 * A store's tokens, without secrets.
	 *
	 * @param int|null $store_id Store, or null for the current one.
	 * @return array
	 */
	public static function all( $store_id = null ) {
		global $wpdb;

		$table = self::table();
		$store = null === $store_id ? Yazan_DB::store_id() : (int) $store_id;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE store_id = %d ORDER BY id DESC LIMIT 200", $store ),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$scopes = json_decode( (string) $row['scopes'], true );

			// Never the hash, never anything a secret could be reconstructed from. `last4` is for
			// a human to tell two tokens apart, nothing more — the same discipline as AI secrets.
			$out[] = array(
				'id'           => (int) $row['id'],
				'name'         => (string) $row['name'],
				'type'         => (string) $row['type'],
				'last4'        => (string) $row['last4'],
				'scopes'       => is_array( $scopes ) ? $scopes : array(),
				'status'       => (string) $row['status'],
				'user_id'      => (int) $row['user_id'],
				'last_used_at' => $row['last_used_at'],
				'expires_at'   => $row['expires_at'],
				'created_at'   => (string) $row['created_at'],
			);
		}

		return $out;
	}

	/**
	 * Record that a token was used.
	 *
	 * Best-effort and deliberately not on the read path's critical section: a failed write here
	 * must never refuse an otherwise valid request.
	 *
	 * @param int $id Token id.
	 * @return void
	 */
	public static function touch( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::table(),
			array( 'last_used_at' => current_time( 'mysql', true ) ),
			array( 'id' => (int) $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Invalidate every cached token decision.
	 *
	 * @return void
	 */
	public static function bump() {
		update_option( self::VERSION_OPTION, (int) get_option( self::VERSION_OPTION, 0 ) + 1, true );

		self::$memo = array();
	}

	/**
	 * A 256-bit URL-safe secret.
	 *
	 * @return string
	 */
	private static function random() {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
