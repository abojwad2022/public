<?php
/**
 * Yazan RBAC — staff user attributes.
 *
 * The dashboard needs a few things WordPress does not store: a phone number, an active/suspended
 * flag, a last-login stamp, and an in-library profile photo. All live in usermeta with an
 * underscore prefix (which keeps them out of wp-admin's Custom Fields box).
 *
 * The phone deliberately does NOT reuse `billing_phone`: a staff member who also shops here would
 * have their WooCommerce billing phone silently rewritten by the staff editor. `billing_phone` is
 * read as a display fallback only.
 *
 * Suspension is enforced in three independent places, and this class owns the first two:
 * the `authenticate` filter (so no cookie is ever issued) and session destruction (so anyone
 * already signed in is kicked immediately). The third is the per-request REST guard.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Staff attributes, session control, and the avatar bridge.
 */
class Yazan_Users {

	/** Usermeta: phone number. */
	const META_PHONE = '_yazan_phone';

	/** Usermeta: 'active' | 'suspended'. Absent means active. */
	const META_STATUS = '_yazan_status';

	/** Usermeta: last successful sign-in, MySQL datetime in site time. */
	const META_LAST_LOGIN = '_yazan_last_login';

	/** Usermeta: IP of the last successful sign-in. */
	const META_LAST_LOGIN_IP = '_yazan_last_login_ip';

	/** Usermeta: attachment id of the profile photo. */
	const META_AVATAR = '_yazan_avatar_id';

	/** Status values. */
	const STATUS_ACTIVE    = 'active';
	const STATUS_SUSPENDED = 'suspended';

	/** Registered image size for profile photos. */
	const AVATAR_SIZE = 'yazan-avatar';

	/**
	 * Hook registration. Called from {@see Yazan_RBAC_Boot::init()}.
	 *
	 * @return void
	 */
	public static function register() {
		// Priority 30: after core's username/email authenticators have produced a WP_User, and
		// before wp_signon() sets the auth cookie. Blocking here means a suspended account never
		// gets a session at all, rather than getting one we then have to compensate for.
		add_filter( 'authenticate', array( __CLASS__, 'block_suspended_login' ), 30, 1 );

		// Fires for wp-login.php AND wp_signon(), so the dashboard login is covered too.
		add_action( 'wp_login', array( __CLASS__, 'record_login' ), 10, 2 );

		add_action( 'after_setup_theme', array( __CLASS__, 'register_avatar_size' ) );
		add_filter( 'pre_get_avatar_data', array( __CLASS__, 'filter_avatar_data' ), 10, 2 );

		// Clean up role rows however the user was deleted (wp-admin, REST, WP-CLI, cron).
		add_action( 'deleted_user', array( __CLASS__, 'on_user_deleted' ), 10, 1 );
	}

	/* --------------------------------------------------------------------- */
	/* Status                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * A user's Yazan status.
	 *
	 * @param int $user_id User id.
	 * @return string 'active' | 'suspended'.
	 */
	public static function status( $user_id ) {
		$status = get_user_meta( absint( $user_id ), self::META_STATUS, true );
		return ( self::STATUS_SUSPENDED === $status ) ? self::STATUS_SUSPENDED : self::STATUS_ACTIVE;
	}

	/**
	 * Is this user suspended?
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function is_suspended( $user_id ) {
		return self::STATUS_SUSPENDED === self::status( $user_id );
	}

	/**
	 * Set a user's status. Suspending also ends every live session.
	 *
	 * @param int    $user_id User id.
	 * @param string $status  'active' | 'suspended'.
	 * @return void
	 */
	public static function set_status( $user_id, $status ) {
		$user_id = absint( $user_id );
		$status  = ( self::STATUS_SUSPENDED === $status ) ? self::STATUS_SUSPENDED : self::STATUS_ACTIVE;

		update_user_meta( $user_id, self::META_STATUS, $status );

		if ( self::STATUS_SUSPENDED === $status ) {
			// Without this the suspension only bites on their next capability check; with it they
			// are signed out everywhere immediately.
			self::destroy_sessions( $user_id );
		}

		Yazan_Permissions::flush_user( $user_id );
	}

	/**
	 * `authenticate` filter: refuse a suspended account before any cookie is issued.
	 *
	 * @param WP_User|WP_Error|null $user Result so far.
	 * @return WP_User|WP_Error|null
	 */
	public static function block_suspended_login( $user ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return $user; // Authentication already failed, or has not resolved a user yet.
		}

		if ( ! self::is_suspended( $user->ID ) ) {
			return $user;
		}

		if ( class_exists( 'Yazan_Dashboard_Audit' ) ) {
			Yazan_Dashboard_Audit::log( 'auth.login_blocked_suspended', 'user', $user->ID );
		}

		return new WP_Error(
			'yazan_suspended',
			__( 'This account is suspended. Contact an administrator.', 'yazan' )
		);
	}

	/* --------------------------------------------------------------------- */
	/* Login stamp                                                            */
	/* --------------------------------------------------------------------- */

	/**
	 * `wp_login`: record when and from where. One write per sign-in, never per request.
	 *
	 * @param string       $user_login Login name.
	 * @param WP_User|null $user       User.
	 * @return void
	 */
	public static function record_login( $user_login, $user = null ) {
		if ( ! ( $user instanceof WP_User ) ) {
			$user = get_user_by( 'login', $user_login );
		}
		if ( ! ( $user instanceof WP_User ) ) {
			return;
		}

		update_user_meta( $user->ID, self::META_LAST_LOGIN, current_time( 'mysql' ) );
		update_user_meta( $user->ID, self::META_LAST_LOGIN_IP, self::client_ip() );
	}

	/**
	 * Last successful sign-in.
	 *
	 * @param int $user_id User id.
	 * @return string MySQL datetime, or '' if never.
	 */
	public static function last_login( $user_id ) {
		return (string) get_user_meta( absint( $user_id ), self::META_LAST_LOGIN, true );
	}

	/* --------------------------------------------------------------------- */
	/* Profile fields                                                         */
	/* --------------------------------------------------------------------- */

	/**
	 * Staff phone, falling back to the WooCommerce billing phone for display only.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public static function phone( $user_id ) {
		$user_id = absint( $user_id );
		$phone   = (string) get_user_meta( $user_id, self::META_PHONE, true );

		if ( '' !== $phone ) {
			return $phone;
		}

		return (string) get_user_meta( $user_id, 'billing_phone', true );
	}

	/**
	 * Store the staff phone.
	 *
	 * @param int    $user_id User id.
	 * @param string $phone   Raw input.
	 * @return string The stored value.
	 */
	public static function set_phone( $user_id, $phone ) {
		// Keep digits, spaces and the punctuation real phone numbers use; drop everything else.
		$clean = trim( (string) preg_replace( '/[^0-9+()\-\s]/', '', (string) $phone ) );
		$clean = substr( $clean, 0, 32 );

		update_user_meta( absint( $user_id ), self::META_PHONE, $clean );

		return $clean;
	}

	/**
	 * Profile photo attachment id.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	public static function avatar_id( $user_id ) {
		return (int) get_user_meta( absint( $user_id ), self::META_AVATAR, true );
	}

	/**
	 * Set (or clear, with 0) the profile photo.
	 *
	 * @param int $user_id       User id.
	 * @param int $attachment_id Attachment id.
	 * @return void
	 */
	public static function set_avatar_id( $user_id, $attachment_id ) {
		$user_id       = absint( $user_id );
		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id ) {
			delete_user_meta( $user_id, self::META_AVATAR );
			return;
		}

		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return;
		}

		update_user_meta( $user_id, self::META_AVATAR, $attachment_id );
	}

	/**
	 * Register the square crop used for profile photos.
	 *
	 * Without a registered size, wp_get_attachment_image_url() silently falls back to the full
	 * image, so a portrait upload would render as a stretched rectangle in the staff table.
	 *
	 * @return void
	 */
	public static function register_avatar_size() {
		add_image_size( self::AVATAR_SIZE, 192, 192, true );
	}

	/**
	 * `pre_get_avatar_data`: serve the uploaded photo.
	 *
	 * This filter rather than `get_avatar_url` because it feeds BOTH get_avatar() and
	 * get_avatar_url(), and preserves the size/default arguments the caller asked for.
	 *
	 * @param array $args        Avatar data.
	 * @param mixed $id_or_email User id, email, WP_User, WP_Post or WP_Comment.
	 * @return array
	 */
	public static function filter_avatar_data( $args, $id_or_email ) {
		static $memo = array();

		$user_id = self::resolve_avatar_user( $id_or_email );
		if ( ! $user_id ) {
			return $args;
		}

		// This filter fires once per row of every user list; resolving the attachment each time
		// would be a query per row.
		if ( ! array_key_exists( $user_id, $memo ) ) {
			$attachment    = self::avatar_id( $user_id );
			$memo[ $user_id ] = $attachment ? wp_get_attachment_image_url( $attachment, self::AVATAR_SIZE ) : '';
		}

		if ( $memo[ $user_id ] ) {
			$args['url']           = $memo[ $user_id ];
			$args['found_avatar']  = true;
		}

		return $args;
	}

	/**
	 * Turn any of the five shapes WordPress passes to avatar filters into a user id.
	 *
	 * @param mixed $id_or_email User id, email, WP_User, WP_Post or WP_Comment.
	 * @return int 0 when it does not resolve to a user.
	 */
	private static function resolve_avatar_user( $id_or_email ) {
		if ( is_numeric( $id_or_email ) ) {
			return absint( $id_or_email );
		}
		if ( $id_or_email instanceof WP_User ) {
			return (int) $id_or_email->ID;
		}
		if ( $id_or_email instanceof WP_Post ) {
			return (int) $id_or_email->post_author;
		}
		if ( $id_or_email instanceof WP_Comment ) {
			return (int) $id_or_email->user_id;
		}
		if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );
			return $user ? (int) $user->ID : 0;
		}

		return 0;
	}

	/* --------------------------------------------------------------------- */
	/* Sessions                                                               */
	/* --------------------------------------------------------------------- */

	/**
	 * End sessions for a user.
	 *
	 * @param int  $user_id      User id.
	 * @param bool $keep_current Keep the session making this request (used when acting on yourself).
	 * @return void
	 */
	public static function destroy_sessions( $user_id, $keep_current = false ) {
		$user_id = absint( $user_id );

		if ( $keep_current && get_current_user_id() === $user_id ) {
			// Signing yourself out mid-request would 401 the very response carrying the result.
			wp_destroy_other_sessions();
			return;
		}

		$tokens = WP_Session_Tokens::get_instance( $user_id );
		if ( $tokens ) {
			$tokens->destroy_all();
		}
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Is this a real WordPress administrator?
	 *
	 * Reads the roles array directly — never a capability API, because callers include code that
	 * runs inside the `user_has_cap` filter.
	 *
	 * @param int|WP_User $user User id or object.
	 * @return bool
	 */
	public static function is_wp_admin( $user ) {
		if ( ! ( $user instanceof WP_User ) ) {
			$user = get_userdata( absint( $user ) );
		}

		return ( $user instanceof WP_User ) && in_array( 'administrator', (array) $user->roles, true );
	}

	/**
	 * `deleted_user`: drop the user's role rows.
	 *
	 * @param int $user_id Deleted user id.
	 * @return void
	 */
	public static function on_user_deleted( $user_id ) {
		Yazan_Roles::purge_user( (int) $user_id );
	}

	/**
	 * Direct connection IP. Proxy headers are spoofable and not trusted.
	 *
	 * @return string
	 */
	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Public shape of a staff user for the dashboard.
	 *
	 * @param WP_User $user User.
	 * @return array
	 */
	public static function payload( WP_User $user ) {
		$role_ids = Yazan_Roles::for_user( $user->ID );
		$roles    = array();

		foreach ( $role_ids as $role_id ) {
			$role = Yazan_Roles::get( $role_id );
			if ( $role ) {
				$roles[] = array(
					'id'       => $role['id'],
					'slug'     => $role['slug'],
					'name'     => $role['name'],
					'is_super' => $role['is_super'],
				);
			}
		}

		return array(
			'id'          => (int) $user->ID,
			'name'        => $user->display_name,
			'first_name'  => (string) get_user_meta( $user->ID, 'first_name', true ),
			'last_name'   => (string) get_user_meta( $user->ID, 'last_name', true ),
			'email'       => $user->user_email,
			'login'       => $user->user_login,
			'phone'       => self::phone( $user->ID ),
			'avatar'      => get_avatar_url( $user->ID, array( 'size' => 96 ) ),
			'avatar_id'   => self::avatar_id( $user->ID ),
			'status'      => self::status( $user->ID ),
			'roles'       => $roles,
			'role_ids'    => $role_ids,
			'is_super'    => Yazan_Permissions::is_super( $user->ID ),
			'is_wp_admin' => self::is_wp_admin( $user ),
			'wp_roles'    => array_values( (array) $user->roles ),
			'wp_role'     => count( (array) $user->roles ) ? (string) array_values( (array) $user->roles )[0] : '',
			'last_login'  => self::last_login( $user->ID ),
			'created_at'  => $user->user_registered ? gmdate( 'Y-m-d H:i:s', strtotime( $user->user_registered ) ) : '',
		);
	}
}
