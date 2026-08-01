<?php
/**
 * Turning a verified provider identity into a WordPress/WooCommerce customer.
 *
 * This is the whole "sign in, link, or create — never duplicate" decision, in one place and
 * deliberately independent of the web redirect flow, so a future native-app endpoint that verifies
 * an ID token posted by a mobile SDK can call resolve() and get identical behaviour.
 *
 * THE ONE SECURITY RULE THAT MATTERS HERE
 * ---------------------------------------
 * Matching an incoming identity to an existing account by email address is what makes the flow feel
 * effortless — and it is also the single place this module could hand an attacker someone else's
 * order history. It is therefore allowed only when the provider asserts `email_verified`. Both
 * Google and Apple always do for genuine accounts, so in practice nothing legitimate is turned
 * away; what is refused is the case where a provider hands us an address its owner never proved.
 * An unverified email is never linked and never silently used to create an account.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * User lookup, linking and creation for social sign-in.
 */
class Yazan_Social_Auth_Users {

	/**
	 * User meta key holding a provider's stable subject identifier.
	 *
	 * @param string $provider Provider key.
	 * @return string
	 */
	public static function sub_meta_key( $provider ) {
		return '_yazan_social_' . sanitize_key( $provider ) . '_sub';
	}

	/**
	 * Find, link, or create the customer this identity belongs to.
	 *
	 * @param array $identity Normalised identity from a provider.
	 * @return array{user:WP_User,created:bool,linked:bool}|WP_Error
	 */
	public static function resolve( array $identity ) {
		$provider = sanitize_key( $identity['provider'] );
		$sub      = (string) $identity['sub'];
		$email    = isset( $identity['email'] ) ? sanitize_email( (string) $identity['email'] ) : '';
		$verified = ! empty( $identity['email_verified'] );

		if ( '' === $provider || '' === $sub ) {
			return new WP_Error( 'yazan_sa_identity', __( 'The sign-in provider returned an incomplete profile.', 'yazan' ) );
		}

		// 1. Already linked. The subject id — not the email — is the durable identity: Apple relay
		//    addresses and Google Workspace addresses can both change, the subject never does.
		$user = self::find_by_sub( $provider, $sub );
		if ( $user instanceof WP_User ) {
			self::sync_profile( $user, $identity );
			return array(
				'user'    => $user,
				'created' => false,
				'linked'  => false,
			);
		}

		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error(
				'yazan_sa_no_email',
				__( 'Your provider did not share an email address, so we could not complete sign-in.', 'yazan' )
			);
		}

		if ( ! $verified ) {
			return new WP_Error(
				'yazan_sa_unverified_email',
				__( 'Your provider has not verified that email address. Please verify it with them, then try again.', 'yazan' )
			);
		}

		// 2. An account already exists on this verified address — link rather than duplicate, so
		//    orders, favourites and reward balances stay attached to the account that earned them.
		$existing = get_user_by( 'email', $email );
		if ( $existing instanceof WP_User ) {
			$allow = apply_filters( 'yazan_social_auth_allow_link', true, $existing, $identity );

			if ( ! $allow ) {
				return new WP_Error(
					'yazan_sa_link_blocked',
					__( 'An account already uses this email address. Please sign in with your password.', 'yazan' )
				);
			}

			self::link( $existing, $identity );
			self::sync_profile( $existing, $identity );

			return array(
				'user'    => $existing,
				'created' => false,
				'linked'  => true,
			);
		}

		// 3. Nobody here yet — create the customer and sign them straight in.
		return self::create( $identity );
	}

	/* --------------------------------------------------------------------- */
	/* Lookup                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * Look up a user by a provider's subject identifier.
	 *
	 * @param string $provider Provider key.
	 * @param string $sub      Subject identifier.
	 * @return WP_User|null
	 */
	private static function find_by_sub( $provider, $sub ) {
		$query = new WP_User_Query(
			array(
				'meta_key'    => self::sub_meta_key( $provider ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Indexed lookup on an exact value; this is the intended access path.
				'meta_value'  => $sub, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'      => 1,
				'count_total' => false,
				'fields'      => 'all',
			)
		);

		$results = $query->get_results();

		return ! empty( $results[0] ) ? $results[0] : null;
	}

	/* --------------------------------------------------------------------- */
	/* Linking and creation                                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * Attach a provider identity to an existing account.
	 *
	 * @param WP_User $user     Target user.
	 * @param array   $identity Normalised identity.
	 * @return void
	 */
	public static function link( WP_User $user, array $identity ) {
		$provider = sanitize_key( $identity['provider'] );

		update_user_meta( $user->ID, self::sub_meta_key( $provider ), (string) $identity['sub'] );
		update_user_meta( $user->ID, '_yazan_social_' . $provider . '_linked_at', time() );

		/**
		 * Fires after a provider identity is attached to an account.
		 *
		 * @param WP_User $user     The account.
		 * @param string  $provider Provider key.
		 * @param array   $identity Normalised identity.
		 */
		do_action( 'yazan_social_auth_linked', $user, $provider, $identity );
	}

	/**
	 * Create a WooCommerce customer from a verified identity and link it.
	 *
	 * Goes through wc_create_new_customer() rather than wp_insert_user() so the account is identical
	 * to one made through the normal registration form: the `customer` role, Woo's own username
	 * generator, the new-account email, and the `woocommerce_created_customer` / `user_register`
	 * hooks the rewards plugin already listens on.
	 *
	 * @param array $identity Normalised identity.
	 * @return array{user:WP_User,created:bool,linked:bool}|WP_Error
	 */
	private static function create( array $identity ) {
		if ( ! function_exists( 'wc_create_new_customer' ) ) {
			return new WP_Error( 'yazan_sa_no_woocommerce', __( 'Accounts are unavailable right now. Please try again later.', 'yazan' ) );
		}

		$email = sanitize_email( (string) $identity['email'] );
		$first = isset( $identity['first_name'] ) ? sanitize_text_field( (string) $identity['first_name'] ) : '';
		$last  = isset( $identity['last_name'] ) ? sanitize_text_field( (string) $identity['last_name'] ) : '';

		$display = trim( $first . ' ' . $last );
		if ( '' === $display ) {
			$display = self::name_from_email( $email );
		}

		/*
		 * An explicit strong password rather than '' — passing an empty string only works when
		 * `woocommerce_registration_generate_password` happens to be enabled, and the shopper never
		 * types this password anyway. They can set one later through the normal reset link.
		 */
		$password = wp_generate_password( 32, true, true );

		$user_id = wc_create_new_customer(
			$email,
			'', // Let Woo derive and de-duplicate the username from the email.
			$password,
			array(
				'first_name'   => $first,
				'last_name'    => $last,
				'display_name' => $display,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'yazan_sa_create_failed', __( 'The account could not be created. Please try again.', 'yazan' ) );
		}

		update_user_meta( $user->ID, '_yazan_social_created_via', sanitize_key( $identity['provider'] ) );

		self::link( $user, $identity );
		self::sync_profile( $user, $identity );

		return array(
			'user'    => $user,
			'created' => true,
			'linked'  => true,
		);
	}

	/* --------------------------------------------------------------------- */
	/* Profile                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Fill in profile fields that are still empty — never overwrite what the customer set.
	 *
	 * Two reasons this only ever fills gaps. A shopper who corrected the spelling of their name in
	 * My Account should not have it reverted by their provider on the next sign-in; and Apple sends
	 * the name only on the very first authorisation, so a later sign-in would otherwise blank it.
	 *
	 * @param WP_User $user     Account.
	 * @param array   $identity Normalised identity.
	 * @return void
	 */
	private static function sync_profile( WP_User $user, array $identity ) {
		$first = isset( $identity['first_name'] ) ? sanitize_text_field( (string) $identity['first_name'] ) : '';
		$last  = isset( $identity['last_name'] ) ? sanitize_text_field( (string) $identity['last_name'] ) : '';

		if ( '' !== $first && '' === (string) get_user_meta( $user->ID, 'first_name', true ) ) {
			update_user_meta( $user->ID, 'first_name', $first );
		}

		if ( '' !== $last && '' === (string) get_user_meta( $user->ID, 'last_name', true ) ) {
			update_user_meta( $user->ID, 'last_name', $last );
		}

		// Seed the billing fields so the first checkout is pre-filled — this is the closest the
		// module gets to "profile completion", and it happens silently rather than as a screen.
		$billing = array(
			'billing_email'      => sanitize_email( (string) $identity['email'] ),
			'billing_first_name' => $first,
			'billing_last_name'  => $last,
		);

		foreach ( $billing as $meta_key => $value ) {
			if ( '' === $value ) {
				continue;
			}
			if ( '' === (string) get_user_meta( $user->ID, $meta_key, true ) ) {
				update_user_meta( $user->ID, $meta_key, $value );
			}
		}
	}

	/**
	 * A presentable display name derived from an email local part.
	 *
	 * @param string $email Email address.
	 * @return string
	 */
	private static function name_from_email( $email ) {
		$local = strstr( (string) $email, '@', true );
		$local = str_replace( array( '.', '_', '-', '+' ), ' ', (string) $local );

		// Apple relay locals are opaque hashes; a generic label reads better than "a1b2c3d4e5".
		if ( preg_match( '/^[0-9a-f ]{12,}$/i', $local ) ) {
			return __( 'Yazan Customer', 'yazan' );
		}

		return ucwords( trim( $local ) );
	}
}
