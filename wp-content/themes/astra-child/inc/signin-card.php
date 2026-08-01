<?php
/**
 * Yazan — sign-in / create-account card.
 *
 * One component, two surfaces. `yazan_signin_card()` renders the whole thing; the account page draws
 * it inline (through the form-login.php override) and this file also drops it into a dialog on
 * wp_footer, opened from the account icon in the header. A shopper can therefore sign in without
 * leaving the product they were looking at, and both surfaces are literally the same markup, so
 * they can never drift apart.
 *
 * TWO STEPS. Email first: the card asks for an address, looks it up, and then shows either the
 * password field or the three registration fields. Registration asks for email, first name and
 * password only — wc_create_new_customer() derives the account username from the address, exactly
 * as WooCommerce does with its own "generate username" option on.
 *
 * WORKS WITHOUT JAVASCRIPT. The card renders two ordinary WooCommerce forms — core field names,
 * core nonces, every core hook — that POST to /my-account/ and are handled by WC_Form_Handler. An
 * inline script beside the card then folds them into the stepped flow before first paint, the same
 * pre-paint technique inc/shop-filters.php uses. With scripting off, the shopper simply sees both
 * forms in full.
 *
 * Security: the AJAX endpoints are nonce-guarded, honeypotted and throttled per IP; a failed
 * sign-in always answers with one generic message. The password is passed through raw — never
 * sanitised, never unslashed — deliberately mirroring WC_Form_Handler::process_login(), so a
 * password set on one surface always authenticates on the other.
 *
 * Disable the dialog (leaving the account page card) with:
 *   add_filter( 'yazan_signin_modal_enabled', '__return_false' );
 * Fall back to a single-step form with:
 *   add_filter( 'yazan_signin_two_step', '__return_false' );
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ============================================================================
 * Gating
 * ========================================================================== */

/**
 * Is customer registration open? Same option WooCommerce's own template reads.
 *
 * @return bool
 */
function yazan_signin_registration_open() {
	return ( 'yes' === get_option( 'woocommerce_enable_myaccount_registration' ) );
}

/**
 * Is the email-first flow on?
 *
 * Filterable because the lookup step is the one part of this design that tells a caller whether an
 * address has an account here. Turning it off collapses the card to a single form with both fields.
 *
 * @return bool
 */
function yazan_signin_two_step() {
	return (bool) apply_filters( 'yazan_signin_two_step', true );
}

/**
 * Should the dialog render on this request?
 *
 * Not for signed-in shoppers, and not on the two surfaces that already own a login form: the
 * account page (which draws the card inline) and checkout. Checkout matters twice over — CartFlows'
 * Instant Checkout strips the theme's late wp_enqueue_scripts callbacks, so a dialog there would
 * render its markup with none of its stylesheet.
 *
 * @return bool
 */
function yazan_signin_modal_should_render() {
	if ( is_user_logged_in() || ! class_exists( 'WooCommerce' ) ) {
		return false;
	}
	if ( is_admin() || is_feed() || ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) ) {
		return false;
	}
	if ( isset( $_GET['yz-static'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- preview flag only.
		return false;
	}
	if ( function_exists( 'is_account_page' ) && is_account_page() ) {
		return false;
	}
	if ( function_exists( 'yazan_is_checkout_view' ) && yazan_is_checkout_view() ) {
		return false;
	}

	return (bool) apply_filters( 'yazan_signin_modal_enabled', true );
}

/* ============================================================================
 * AJAX — lookup, sign in, register, and a nonce top-up.
 * ========================================================================== */

/** Address lookups allowed per IP inside YAZAN_SIGNIN_RATE_WINDOW. */
const YAZAN_SIGNIN_LOOKUP_MAX = 30;

/** Sign-in / registration attempts allowed per IP inside YAZAN_SIGNIN_RATE_WINDOW. */
const YAZAN_SIGNIN_ATTEMPT_MAX = 12;

/** Throttle window, in seconds. */
const YAZAN_SIGNIN_RATE_WINDOW = 900;

/**
 * Has this IP exceeded the throttle for this kind of call? Increments the counter.
 *
 * An unauthenticated endpoint that checks passwords is a brute-force oracle, and one that answers
 * "does this address have an account" is an address harvester, so neither may go unmetered. Keyed
 * on REMOTE_ADDR only — never X-Forwarded-For, which any client can forge, turning a rate limiter
 * into a bypass. Same shape (and the same reverse-proxy trade-off, where every visitor shares the
 * proxy's bucket) as yazan_promo_rate_limited().
 *
 * @param string $bucket 'lookup' or 'attempt' — counted separately so mistyping a password a few
 *                       times never costs the shopper the ability to look their address up.
 * @return bool True when the caller should be rejected.
 */
function yazan_signin_rate_limited( $bucket ) {
	$max = ( 'lookup' === $bucket ) ? YAZAN_SIGNIN_LOOKUP_MAX : YAZAN_SIGNIN_ATTEMPT_MAX;
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$key = 'yz_signin_' . $bucket . '_' . md5( $ip );

	$hits = (int) get_transient( $key );

	if ( $hits >= $max ) {
		return true;
	}

	set_transient( $key, $hits + 1, YAZAN_SIGNIN_RATE_WINDOW );

	return false;
}

/**
 * Reject the request unless it carries a valid nonce.
 *
 * The card ships in the footer of every page, so its nonce is as old as the tab. When a tab has
 * been open past the nonce lifetime the shopper must not be told "something went wrong" — the
 * script asks for a fresh nonce and replays, which needs a code it can recognise rather than the
 * bare `-1` check_ajax_referer() would print. Same self-healing shape as assets/js/chat.js.
 *
 * @return void Sends a JSON error and exits when the nonce is not valid.
 */
function yazan_signin_require_nonce() {
	$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

	if ( ! wp_verify_nonce( $nonce, 'yazan_signin' ) ) {
		wp_send_json_error(
			array(
				'code'    => 'bad_nonce',
				'message' => __( 'Your session expired. Please try again.', 'yazan' ),
			),
			403
		);
	}
}

/**
 * Throttled + honeypotted preamble shared by all three write endpoints.
 *
 * @param string $bucket    Rate-limit bucket, 'lookup' or 'attempt'.
 * @param string $trap_note Message to answer a honeypot hit with. '' skips the honeypot check.
 * @return void
 */
function yazan_signin_guard( $bucket, $trap_note = '' ) {
	yazan_signin_require_nonce();

	// Honeypot: a real shopper never fills this hidden field. Answer like an ordinary failure.
	if ( '' !== $trap_note && ! empty( $_POST['yz_hp'] ) ) {
		wp_send_json_error( array( 'message' => $trap_note ), 400 );
	}

	if ( yazan_signin_rate_limited( $bucket ) ) {
		wp_send_json_error(
			array( 'message' => __( 'Too many attempts. Please wait a few minutes and try again.', 'yazan' ) ),
			429
		);
	}
}

add_action( 'wp_ajax_yazan_signin_nonce', 'yazan_signin_ajax_nonce' );
add_action( 'wp_ajax_nopriv_yazan_signin_nonce', 'yazan_signin_ajax_nonce' );
/**
 * Hand out a fresh nonce to a tab whose own has aged out.
 *
 * Deliberately unguarded. A nonce is not a secret: it is a CSRF token, it is already printed into
 * every page of this site for the same visitor, and minting one for the caller's own session grants
 * them nothing a page reload would not. WordPress core takes the same position with its
 * `rest-nonce` admin-ajax action.
 *
 * @return void
 */
function yazan_signin_ajax_nonce() {
	wp_send_json_success( array( 'nonce' => wp_create_nonce( 'yazan_signin' ) ) );
}

add_action( 'wp_ajax_yazan_signin_lookup', 'yazan_signin_ajax_lookup' );
add_action( 'wp_ajax_nopriv_yazan_signin_lookup', 'yazan_signin_ajax_lookup' );
/**
 * Decide which second step an address gets: the password field, or the registration fields.
 *
 * @return void
 */
function yazan_signin_ajax_lookup() {
	yazan_signin_guard( 'lookup', __( 'Please enter a valid email address.', 'yazan' ) );

	$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

	if ( '' === $email || ! is_email( $email ) ) {
		wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'yazan' ) ), 400 );
	}

	$known = (bool) email_exists( $email );

	/*
	 * With registration closed there is nowhere to send an unknown address, so everyone gets the
	 * password step and an unregistered shopper learns nothing from the answer.
	 */
	if ( ! $known && ! yazan_signin_registration_open() ) {
		wp_send_json_success( array( 'step' => 'password' ) );
	}

	wp_send_json_success( array( 'step' => $known ? 'password' : 'register' ) );
}

add_action( 'wp_ajax_yazan_signin_login', 'yazan_signin_ajax_login' );
add_action( 'wp_ajax_nopriv_yazan_signin_login', 'yazan_signin_ajax_login' );
/**
 * Sign the shopper in and tell the browser where to go next.
 *
 * @return void
 */
function yazan_signin_ajax_login() {
	yazan_signin_guard( 'attempt', __( 'Incorrect email address or password.', 'yazan' ) );

	// Already signed in — another tab got there first. Nothing to do but let this page catch up.
	if ( is_user_logged_in() ) {
		wp_send_json_success( array( 'redirect' => '' ) );
	}

	$username = isset( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '';

	if ( '' === $username || empty( $_POST['password'] ) ) {
		wp_send_json_error( array( 'message' => __( 'Please enter your email address and password.', 'yazan' ) ), 400 );
	}

	$creds = array(
		'user_login'    => $username,
		'user_password' => $_POST['password'], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Raw on purpose: WC_Form_Handler::process_login() hashes the slashed value, so touching it here would reject passwords set through the account page.
		'remember'      => ! empty( $_POST['rememberme'] ),
	);

	$user = wp_signon( apply_filters( 'woocommerce_login_credentials', $creds ), is_ssl() );

	if ( is_wp_error( $user ) ) {
		/*
		 * WordPress distinguishes "no such account" from "wrong password", which hands an attacker
		 * a list of which addresses are registered here. One message covers both.
		 */
		wp_send_json_error( array( 'message' => __( 'Incorrect email address or password.', 'yazan' ) ), 401 );
	}

	wp_send_json_success( array( 'redirect' => yazan_signin_redirect_target( 'woocommerce_login_redirect', $user ) ) );
}

add_action( 'wp_ajax_yazan_signin_register', 'yazan_signin_ajax_register' );
add_action( 'wp_ajax_nopriv_yazan_signin_register', 'yazan_signin_ajax_register' );
/**
 * Create a customer from email + first name + password, then sign them in.
 *
 * @return void
 */
function yazan_signin_ajax_register() {
	yazan_signin_guard( 'attempt', __( 'That email address cannot be used.', 'yazan' ) );

	if ( is_user_logged_in() ) {
		wp_send_json_success( array( 'redirect' => '' ) );
	}

	if ( ! yazan_signin_registration_open() ) {
		wp_send_json_error( array( 'message' => __( 'New accounts are not available right now.', 'yazan' ) ), 403 );
	}

	$email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
	$password   = isset( $_POST['password'] ) ? $_POST['password'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Raw on purpose; see the note in yazan_signin_ajax_login().

	if ( '' === $email || ! is_email( $email ) ) {
		wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'yazan' ) ), 400 );
	}
	if ( '' === $first_name ) {
		wp_send_json_error( array( 'message' => __( 'Please enter your first name.', 'yazan' ) ), 400 );
	}
	if ( '' === $password ) {
		wp_send_json_error( array( 'message' => __( 'Please choose a password.', 'yazan' ) ), 400 );
	}

	/*
	 * The same validation filter WooCommerce runs on its own registration form, so anything hooked
	 * to it — spam blocking, password policies, marketing integrations — applies here too. The
	 * username argument is empty because this form does not ask for one.
	 */
	$validation_error = apply_filters( 'woocommerce_process_registration_errors', new WP_Error(), '', $password, $email );

	if ( is_wp_error( $validation_error ) && $validation_error->get_error_code() ) {
		wp_send_json_error( array( 'message' => wp_strip_all_tags( $validation_error->get_error_message() ) ), 400 );
	}

	$customer_id = wc_create_new_customer( $email, '', $password, array( 'first_name' => $first_name ) );

	if ( is_wp_error( $customer_id ) ) {
		wp_send_json_error( array( 'message' => wp_strip_all_tags( $customer_id->get_error_message() ) ), 400 );
	}

	yazan_signin_seed_billing_name( $customer_id, $first_name );

	// The same filter WooCommerce uses to decide whether a new account is signed in immediately.
	if ( apply_filters( 'woocommerce_registration_auth_new_customer', true, $customer_id ) ) {
		wc_set_customer_auth_cookie( $customer_id );
	}

	wp_send_json_success( array( 'redirect' => yazan_signin_redirect_target( 'woocommerce_registration_redirect' ) ) );
}

/**
 * Copy the first name into the billing profile so checkout starts half filled in.
 *
 * Through the customer CRUD rather than update_user_meta(), because the CRUD is the only API that
 * stays correct across WooCommerce's storage changes.
 *
 * @param int    $customer_id Customer user ID.
 * @param string $first_name  Sanitised first name.
 * @return void
 */
function yazan_signin_seed_billing_name( $customer_id, $first_name ) {
	if ( '' === $first_name || ! class_exists( 'WC_Customer' ) ) {
		return;
	}

	try {
		$customer = new WC_Customer( $customer_id );
		if ( '' === $customer->get_billing_first_name() ) {
			$customer->set_billing_first_name( $first_name );
			$customer->save();
		}
	} catch ( Exception $e ) {
		// A missing billing name is cosmetic — never fail a completed registration over it.
		return;
	}
}

add_filter( 'woocommerce_new_customer_data', 'yazan_signin_capture_first_name' );
/**
 * Carry the card's first-name field through WooCommerce's own form handler.
 *
 * WC_Form_Handler::process_registration() reads only username, email and password, so a shopper who
 * registers with JavaScript off would lose the name they typed. This picks it up from the same
 * request — after the registration nonce has been verified, so it is not an open write.
 *
 * @param array $data Customer data on its way to wp_insert_user().
 * @return array
 */
function yazan_signin_capture_first_name( $data ) {
	if ( ! empty( $data['first_name'] ) || empty( $_POST['first_name'] ) ) {
		return $data;
	}

	$nonce = isset( $_POST['woocommerce-register-nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['woocommerce-register-nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'woocommerce-register' ) ) {
		return $data;
	}

	$data['first_name'] = sanitize_text_field( wp_unslash( $_POST['first_name'] ) );

	return $data;
}

add_action( 'woocommerce_created_customer', 'yazan_signin_seed_billing_name_on_create', 10, 2 );
/**
 * Same billing-name seed for the no-JavaScript path, where registration goes through core.
 *
 * @param int   $customer_id       New customer ID.
 * @param array $new_customer_data The data the customer was created from.
 * @return void
 */
function yazan_signin_seed_billing_name_on_create( $customer_id, $new_customer_data ) {
	if ( empty( $new_customer_data['first_name'] ) ) {
		return;
	}

	yazan_signin_seed_billing_name( $customer_id, $new_customer_data['first_name'] );
}

/**
 * Where to send the browser after a successful sign-in or registration.
 *
 * Defaults to the page the card was opened from — the whole point of signing in over the page is
 * keeping your place — but still runs WooCommerce's redirect filters, so anything that insists on
 * its own destination (a membership plugin, an onboarding flow) keeps working. Anything off-site is
 * dropped by wp_validate_redirect(); an empty result tells the script to reload in place.
 *
 * @param string       $filter Filter name: woocommerce_login_redirect or woocommerce_registration_redirect.
 * @param WP_User|null $user   The signed-in user, when the filter takes one.
 * @return string URL, or '' to reload in place.
 */
function yazan_signin_redirect_target( $filter, $user = null ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by the calling endpoint.
	$requested = isset( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( $_POST['redirect'] ) ) : '';

	$redirect = ( 'woocommerce_login_redirect' === $filter )
		? apply_filters( $filter, $requested, $user )
		: apply_filters( $filter, $requested );

	return wp_validate_redirect( $redirect, '' );
}

/* ============================================================================
 * Assets
 * ========================================================================== */

add_action( 'wp_enqueue_scripts', 'yazan_signin_enqueue', 20 );
/**
 * Load the card's stylesheet and script wherever the card can appear.
 *
 * @return void
 */
function yazan_signin_enqueue() {
	$on_account = function_exists( 'is_account_page' ) && is_account_page() && ! is_user_logged_in();

	if ( ! $on_account && ! yazan_signin_modal_should_render() ) {
		return;
	}

	wp_enqueue_style( 'yazan-signin-card', YAZAN_URI . '/assets/css/signin-card.css', array( 'yazan-main' ), yazan_asset_ver( 'assets/css/signin-card.css' ) );
	wp_enqueue_script( 'yazan-signin-card', YAZAN_URI . '/assets/js/signin-card.js', array(), yazan_asset_ver( 'assets/js/signin-card.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );

	wp_localize_script(
		'yazan-signin-card',
		'YazanSignin',
		array(
			'ajax'     => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'yazan_signin' ),
			'redirect' => yazan_signin_current_url(),
			'twoStep'  => yazan_signin_two_step() ? '1' : '0',
			'i18n'     => array(
				'generic' => __( 'Something went wrong. Please try again.', 'yazan' ),
				'network' => __( 'Network error. Please try again.', 'yazan' ),
				'email'   => __( 'Please enter a valid email address.', 'yazan' ),
				'working' => __( 'One moment…', 'yazan' ),
			),
		)
	);
}

add_filter( 'yazan_social_auth_enqueue_css', 'yazan_signin_social_css' );
/**
 * Let the social sign-in stylesheet load on a page the dialog renders on.
 *
 * Yazan_Social_Auth_UI::enqueue() loads it only on the account page and checkout, which is right
 * for buttons that live inside a WooCommerce login form — but the dialog puts the same providers on
 * every page of the store.
 *
 * @param bool $needed Whether the plugin already decided the stylesheet is needed.
 * @return bool
 */
function yazan_signin_social_css( $needed ) {
	return $needed || yazan_signin_modal_should_render();
}

/**
 * The page the shopper is on, so a successful sign-in returns them to it.
 *
 * @return string
 */
function yazan_signin_current_url() {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

	return '' !== $uri ? home_url( $uri ) : '';
}

/* ============================================================================
 * The card
 * ========================================================================== */

/**
 * Render the sign-in / create-account card.
 *
 * @param array $args {
 *     Optional.
 *
 *     @type string $instance Prefix for every id in the card, so two instances could coexist.
 *                            Default 'page'.
 *     @type string $tag      Heading level for the card title — h1 on the account page, where it is
 *                            the page's own heading; h2 inside the dialog. Default 'h1'.
 * }
 * @return void
 */
function yazan_signin_card( $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'instance' => 'page',
			'tag'      => 'h1',
		)
	);

	$id       = 'yz-signin-modal-' . sanitize_html_class( $args['instance'] );
	$tag      = in_array( $args['tag'], array( 'h1', 'h2' ), true ) ? $args['tag'] : 'h1';
	$action   = wc_get_page_permalink( 'myaccount' );
	$register = yazan_signin_registration_open();
	$two_step = yazan_signin_two_step();

	/*
	 * The plugin's own provider buttons attach to woocommerce_login_form_start /
	 * woocommerce_register_form_start, which this card fires like any WooCommerce login form. Left
	 * alone they would render a second, full-width copy of the same two links above the tiles
	 * below. Suppressed for the length of this render only, so the buttons still appear on
	 * surfaces this card does not own — CartFlows' checkout, most of all.
	 */
	$unhooked = array();
	foreach ( array( 'woocommerce_login_form_start' => 'render_login', 'woocommerce_register_form_start' => 'render_register' ) as $hook => $method ) {
		if ( remove_action( $hook, array( 'Yazan_Social_Auth_UI', $method ) ) ) {
			$unhooked[ $hook ] = $method;
		}
	}

	$social = ( class_exists( 'Yazan_Social_Auth_UI' ) && method_exists( 'Yazan_Social_Auth_UI', 'buttons_html' ) )
		? Yazan_Social_Auth_UI::buttons_html( '', 'icons' )
		: '';

	$eye = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M1.8 12S5.4 5.4 12 5.4 22.2 12 22.2 12 18.6 18.6 12 18.6 1.8 12 1.8 12Z"/><circle cx="12" cy="12" r="3.2"/></svg>';
	?>
	<div class="yz-signin" data-yz-signin-card data-step="email">

		<?php /* ------------------------------------------------------ Sign in */ ?>
		<section class="yz-signin__view" data-yz-signin-view="signin">

			<header class="yz-signin__head">
				<<?php echo esc_html( $tag ); ?> class="yz-signin__title"><?php esc_html_e( 'Sign in', 'yazan' ); ?></<?php echo esc_html( $tag ); ?>>
				<p class="yz-signin__lede"><?php esc_html_e( 'Continue to Yazan', 'yazan' ); ?></p>
			</header>

			<form class="yz-signin__form" method="post" action="<?php echo esc_url( $action ); ?>" data-yz-signin-form="login" novalidate>

				<?php do_action( 'woocommerce_login_form_start' ); ?>

				<div class="yz-signin__step" data-yz-signin-step="email">
					<p class="yz-signin__field">
						<label for="<?php echo esc_attr( $id ); ?>-username"><?php esc_html_e( 'Email', 'yazan' ); ?></label>
						<input type="email" id="<?php echo esc_attr( $id ); ?>-username" name="username" autocomplete="username" required aria-required="true" value="<?php echo ( ! empty( $_POST['username'] ) && is_string( $_POST['username'] ) ) ? esc_attr( wp_unslash( $_POST['username'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Redisplay of a failed core login, which verified its own nonce. ?>">
					</p>

					<?php if ( $two_step ) : ?>
						<button type="submit" class="yz-signin__submit" data-yz-signin-jsonly hidden><?php esc_html_e( 'Continue with email', 'yazan' ); ?></button>
					<?php endif; ?>
				</div>

				<div class="yz-signin__step" data-yz-signin-step="password"<?php echo $two_step ? ' data-yz-signin-fold' : ''; ?>>

					<p class="yz-signin__identity" data-yz-signin-identity hidden>
						<span data-yz-signin-identity-email></span>
						<button type="button" class="yz-signin__link" data-yz-signin-restart><?php esc_html_e( 'Change', 'yazan' ); ?></button>
					</p>

					<p class="yz-signin__field yz-signin__field--password">
						<label for="<?php echo esc_attr( $id ); ?>-password"><?php esc_html_e( 'Password', 'yazan' ); ?></label>
						<input type="password" id="<?php echo esc_attr( $id ); ?>-password" name="password" autocomplete="current-password" required aria-required="true">
						<button type="button" class="yz-signin__eye" data-yz-signin-eye aria-label="<?php esc_attr_e( 'Show password', 'yazan' ); ?>" aria-pressed="false"><?php echo $eye; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static inline SVG. ?></button>
					</p>

					<?php do_action( 'woocommerce_login_form' ); ?>

					<label class="yz-signin__remember">
						<input type="checkbox" name="rememberme" value="forever">
						<span><?php esc_html_e( 'Keep me signed in', 'yazan' ); ?></span>
					</label>

					<?php wp_nonce_field( 'woocommerce-login', 'woocommerce-login-nonce' ); ?>
					<button type="submit" class="yz-signin__submit" name="login" value="<?php esc_attr_e( 'Sign in', 'yazan' ); ?>"><?php esc_html_e( 'Sign in', 'yazan' ); ?></button>

					<p class="yz-signin__lost">
						<a href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( 'Forgotten your password?', 'yazan' ); ?></a>
					</p>
				</div>

				<input type="text" name="yz_hp" class="yz-signin__hp" tabindex="-1" autocomplete="off" aria-hidden="true">
				<p class="yz-signin__error" data-yz-signin-error role="alert" hidden></p>

				<?php do_action( 'woocommerce_login_form_end' ); ?>
			</form>

			<?php if ( '' !== $social ) : ?>
				<div class="yz-signin__rule"><span><?php esc_html_e( 'or', 'yazan' ); ?></span></div>
				<?php echo $social; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped in Yazan_Social_Auth_UI::buttons_html(). ?>
			<?php endif; ?>

			<?php if ( $register ) : ?>
				<p class="yz-signin__foot" data-yz-signin-jsonly hidden>
					<?php esc_html_e( 'New to Yazan?', 'yazan' ); ?>
					<button type="button" class="yz-signin__link" data-yz-signin-view-to="register"><?php esc_html_e( 'Get started', 'yazan' ); ?> &rarr;</button>
				</p>
			<?php endif; ?>

		</section>

		<?php /* ----------------------------------------------------- Register */ ?>
		<?php if ( $register ) : ?>
		<section class="yz-signin__view" data-yz-signin-view="register" data-yz-signin-fold>

			<header class="yz-signin__head">
				<h2 class="yz-signin__title"><?php esc_html_e( 'Create your account', 'yazan' ); ?></h2>
				<p class="yz-signin__lede"><?php esc_html_e( 'Keep your orders and details in one quiet place.', 'yazan' ); ?></p>
			</header>

			<form class="yz-signin__form" method="post" action="<?php echo esc_url( $action ); ?>" data-yz-signin-form="register" <?php do_action( 'woocommerce_register_form_tag' ); ?>novalidate>

				<?php do_action( 'woocommerce_register_form_start' ); ?>

				<p class="yz-signin__field">
					<label for="<?php echo esc_attr( $id ); ?>-email"><?php esc_html_e( 'Email', 'yazan' ); ?></label>
					<input type="email" id="<?php echo esc_attr( $id ); ?>-email" name="email" autocomplete="email" required aria-required="true" value="<?php echo ( ! empty( $_POST['email'] ) && is_string( $_POST['email'] ) ) ? esc_attr( wp_unslash( $_POST['email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Redisplay of a failed core registration, which verified its own nonce. ?>">
				</p>

				<p class="yz-signin__field">
					<label for="<?php echo esc_attr( $id ); ?>-first"><?php esc_html_e( 'First name', 'yazan' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $id ); ?>-first" name="first_name" autocomplete="given-name" required aria-required="true" value="<?php echo ( ! empty( $_POST['first_name'] ) && is_string( $_POST['first_name'] ) ) ? esc_attr( wp_unslash( $_POST['first_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Redisplay only. ?>">
				</p>

				<p class="yz-signin__field yz-signin__field--password">
					<label for="<?php echo esc_attr( $id ); ?>-newpass"><?php esc_html_e( 'Password', 'yazan' ); ?></label>
					<input type="password" id="<?php echo esc_attr( $id ); ?>-newpass" name="password" autocomplete="new-password" required aria-required="true">
					<button type="button" class="yz-signin__eye" data-yz-signin-eye aria-label="<?php esc_attr_e( 'Show password', 'yazan' ); ?>" aria-pressed="false"><?php echo $eye; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static inline SVG. ?></button>
				</p>

				<?php do_action( 'woocommerce_register_form' ); ?>

				<input type="text" name="yz_hp" class="yz-signin__hp" tabindex="-1" autocomplete="off" aria-hidden="true">
				<p class="yz-signin__error" data-yz-signin-error role="alert" hidden></p>

				<?php wp_nonce_field( 'woocommerce-register', 'woocommerce-register-nonce' ); ?>
				<button type="submit" class="yz-signin__submit" name="register" value="<?php esc_attr_e( 'Create account', 'yazan' ); ?>"><?php esc_html_e( 'Create account', 'yazan' ); ?></button>

				<p class="yz-signin__foot" data-yz-signin-jsonly hidden>
					<?php esc_html_e( 'Already have an account?', 'yazan' ); ?>
					<button type="button" class="yz-signin__link" data-yz-signin-view-to="signin"><?php esc_html_e( 'Sign in', 'yazan' ); ?></button>
				</p>

				<?php do_action( 'woocommerce_register_form_end' ); ?>
			</form>

		</section>
		<?php endif; ?>

		<?php
		/*
		 * Fold the two forms into the stepped flow BEFORE first paint — the same pre-paint trick
		 * inc/shop-filters.php uses for its sidebar. Everything marked data-yz-signin-fold is a piece
		 * the shopper reaches later; everything marked data-yz-signin-jsonly is a control that only
		 * means something once this ran. With scripting off neither line executes, and the card
		 * degrades to the two plain WooCommerce forms it is built from.
		 */
		?>
		<script>
		(function () {
			try {
				var card = document.currentScript.parentNode;
				card.setAttribute('data-yz-signin-js', '1');
				card.querySelectorAll('[data-yz-signin-fold]').forEach(function (el) { el.hidden = true; });
				card.querySelectorAll('[data-yz-signin-jsonly]').forEach(function (el) { el.hidden = false; });
			} catch (e) {}
		})();
		</script>
	</div>
	<?php

	// Put the plugin's own hook renderers back for anything that renders a login form after this.
	foreach ( $unhooked as $hook => $method ) {
		add_action( $hook, array( 'Yazan_Social_Auth_UI', $method ) );
	}
}

add_action( 'wp_footer', 'yazan_signin_modal_render' );
/**
 * Output the dialog (hidden; opened by signin-card.js from the header account icon).
 *
 * @return void
 */
function yazan_signin_modal_render() {
	if ( ! yazan_signin_modal_should_render() ) {
		return;
	}
	?>
	<div class="yz-signin-modal" data-yz-signin-modal hidden aria-hidden="true">
		<div class="yz-signin-modal__overlay" data-yz-signin-close></div>

		<div class="yz-signin-modal__dialog" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Sign in to Yazan', 'yazan' ); ?>">
			<button type="button" class="yz-signin-modal__close" data-yz-signin-close aria-label="<?php esc_attr_e( 'Close', 'yazan' ); ?>">
				<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>
			</button>

			<?php yazan_signin_card( array( 'instance' => 'modal', 'tag' => 'h2' ) ); ?>
		</div>
	</div>
	<?php
}
