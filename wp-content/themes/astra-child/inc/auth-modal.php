<?php
/**
 * Yazan — storefront sign-in / create-account modal.
 *
 * The account icon in the header used to send a shopper to /my-account/, which means leaving the
 * product they were looking at to sign in and then finding their way back. This module puts the
 * same two forms in a dialog that opens over the current page and submits over AJAX, so signing in
 * costs nothing but a few seconds and the page they were on is still there afterwards.
 *
 * /my-account/ is untouched and stays the no-JavaScript fallback: the header link still points at
 * it, and the click is only intercepted when the script is present. Both surfaces share the same
 * WooCommerce primitives (wp_signon / wc_create_new_customer), so an account created here is
 * indistinguishable from one created on the account page.
 *
 * Registration asks for email, first name and password only — the account username is generated
 * from the email by wc_create_new_customer(), exactly as WooCommerce does when its own
 * "generate username" option is on.
 *
 * Security: both endpoints are nonce-guarded, honeypotted and throttled per IP. The password is
 * passed through raw and never sanitised or unslashed, deliberately mirroring
 * WC_Form_Handler::process_login() and ::process_registration() so a password set on one surface
 * always authenticates on the other.
 *
 * Disable entirely with:  add_filter( 'yazan_auth_modal_enabled', '__return_false' );
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
 * Should the modal render on the current request?
 *
 * Not for signed-in shoppers (there is nothing to sign into), and not on the two surfaces that
 * already own a login form of their own: the account page and checkout. Checkout matters twice
 * over — CartFlows' Instant Checkout strips the theme's late wp_enqueue_scripts callbacks, so a
 * modal there would render its markup with none of its stylesheet.
 *
 * @return bool
 */
function yazan_auth_modal_should_render() {
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

	return (bool) apply_filters( 'yazan_auth_modal_enabled', true );
}

/**
 * Is customer registration open? Mirrors the check in the form-login.php override.
 *
 * @return bool
 */
function yazan_auth_modal_registration_open() {
	return ( 'yes' === get_option( 'woocommerce_enable_myaccount_registration' ) );
}

/* ============================================================================
 * AJAX — sign in, register, and a nonce top-up.
 * ========================================================================== */

/** Auth attempts allowed per IP inside YAZAN_AUTH_RATE_WINDOW. */
const YAZAN_AUTH_RATE_MAX = 12;

/** Throttle window, in seconds. */
const YAZAN_AUTH_RATE_WINDOW = 900;

/**
 * Has this IP exceeded the auth throttle? Increments the counter.
 *
 * An unauthenticated endpoint that checks passwords is a brute-force oracle, so this is not
 * optional. Keyed on REMOTE_ADDR only — never X-Forwarded-For, which any client can forge, turning
 * a rate limiter into a bypass. Same shape (and the same reverse-proxy trade-off) as the promo
 * popup's limiter.
 *
 * @return bool True when the caller should be rejected.
 */
function yazan_auth_rate_limited() {
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$key = 'yz_auth_rl_' . md5( $ip );

	$hits = (int) get_transient( $key );

	if ( $hits >= YAZAN_AUTH_RATE_MAX ) {
		return true;
	}

	set_transient( $key, $hits + 1, YAZAN_AUTH_RATE_WINDOW );

	return false;
}

/**
 * Verify the request nonce, answering with a machine-readable code instead of dying.
 *
 * The modal ships in the footer of every page, so its nonce is as old as the tab. When that tab has
 * been open past the nonce lifetime the shopper must not be told "something went wrong" — the JS
 * asks for a fresh nonce and replays, which needs a code it can recognise rather than the `-1` that
 * check_ajax_referer() would print.
 *
 * @return void Sends a JSON error and exits when the nonce is not valid.
 */
function yazan_auth_require_nonce() {
	$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

	if ( ! wp_verify_nonce( $nonce, 'yazan_auth' ) ) {
		wp_send_json_error(
			array(
				'code'    => 'bad_nonce',
				'message' => __( 'Your session expired. Please try again.', 'yazan' ),
			),
			403
		);
	}
}

add_action( 'wp_ajax_yazan_auth_nonce', 'yazan_auth_ajax_nonce' );
add_action( 'wp_ajax_nopriv_yazan_auth_nonce', 'yazan_auth_ajax_nonce' );
/**
 * Hand out a fresh nonce for a tab whose own has aged out.
 *
 * Deliberately unguarded: a nonce is not a secret and is already printed into every page of the
 * site for the same visitor. It is a CSRF token, and minting one for the caller's own session
 * grants them nothing they could not get by reloading the page.
 *
 * @return void
 */
function yazan_auth_ajax_nonce() {
	wp_send_json_success( array( 'nonce' => wp_create_nonce( 'yazan_auth' ) ) );
}

add_action( 'wp_ajax_yazan_auth_login', 'yazan_auth_ajax_login' );
add_action( 'wp_ajax_nopriv_yazan_auth_login', 'yazan_auth_ajax_login' );
/**
 * Sign the shopper in and tell the browser where to go next.
 *
 * @return void
 */
function yazan_auth_ajax_login() {
	yazan_auth_require_nonce();

	// Already signed in — another tab got there first. Nothing to do but let the page catch up.
	if ( is_user_logged_in() ) {
		wp_send_json_success( array( 'redirect' => '' ) );
	}

	// Honeypot: a real shopper never fills this hidden field. Answer like a failed sign-in.
	if ( ! empty( $_POST['yz_hp'] ) ) {
		wp_send_json_error( array( 'message' => __( 'Incorrect email address or password.', 'yazan' ) ), 401 );
	}

	if ( yazan_auth_rate_limited() ) {
		wp_send_json_error(
			array( 'message' => __( 'Too many attempts. Please wait a few minutes and try again.', 'yazan' ) ),
			429
		);
	}

	$username = isset( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '';

	if ( '' === $username || ! isset( $_POST['password'] ) || '' === $_POST['password'] ) {
		wp_send_json_error( array( 'message' => __( 'Please enter your email address and password.', 'yazan' ) ), 400 );
	}

	$creds = array(
		'user_login'    => $username,
		'user_password' => $_POST['password'], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Raw on purpose: WC_Form_Handler::process_login() hashes the slashed value, so altering it here would reject passwords set through the account page.
		'remember'      => ! empty( $_POST['rememberme'] ),
	);

	$user = wp_signon( apply_filters( 'woocommerce_login_credentials', $creds ), is_ssl() );

	if ( is_wp_error( $user ) ) {
		/*
		 * WordPress distinguishes "no such account" from "wrong password", which tells an attacker
		 * which addresses are registered here. One message for both.
		 */
		wp_send_json_error( array( 'message' => __( 'Incorrect email address or password.', 'yazan' ) ), 401 );
	}

	wp_send_json_success( array( 'redirect' => yazan_auth_redirect_target( 'woocommerce_login_redirect', $user ) ) );
}

add_action( 'wp_ajax_yazan_auth_register', 'yazan_auth_ajax_register' );
add_action( 'wp_ajax_nopriv_yazan_auth_register', 'yazan_auth_ajax_register' );
/**
 * Create a customer account from email + first name + password, then sign them in.
 *
 * @return void
 */
function yazan_auth_ajax_register() {
	yazan_auth_require_nonce();

	if ( is_user_logged_in() ) {
		wp_send_json_success( array( 'redirect' => '' ) );
	}

	if ( ! yazan_auth_modal_registration_open() ) {
		wp_send_json_error( array( 'message' => __( 'New accounts are not available right now.', 'yazan' ) ), 403 );
	}

	// Honeypot: pretend the address was taken rather than admit the trap.
	if ( ! empty( $_POST['yz_hp'] ) ) {
		wp_send_json_error( array( 'message' => __( 'That email address cannot be used.', 'yazan' ) ), 400 );
	}

	if ( yazan_auth_rate_limited() ) {
		wp_send_json_error(
			array( 'message' => __( 'Too many attempts. Please wait a few minutes and try again.', 'yazan' ) ),
			429
		);
	}

	$email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
	$password   = isset( $_POST['password'] ) ? $_POST['password'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Raw on purpose; see the note in yazan_auth_ajax_login().

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
	 * to it (spam blockers, password policies, marketing integrations) applies here too. The
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

	/*
	 * Carry the first name into the billing profile as well, so checkout is already half filled in.
	 * Through the CRUD, never update_user_meta directly — the customer object is the HPOS-safe way
	 * to touch this data.
	 */
	$customer = new WC_Customer( $customer_id );
	if ( '' === $customer->get_billing_first_name() ) {
		$customer->set_billing_first_name( $first_name );
		$customer->save();
	}

	// Same filter WooCommerce uses to decide whether a new account is signed in immediately.
	if ( apply_filters( 'woocommerce_registration_auth_new_customer', true, $customer_id ) ) {
		wc_set_customer_auth_cookie( $customer_id );
	}

	wp_send_json_success( array( 'redirect' => yazan_auth_redirect_target( 'woocommerce_registration_redirect' ) ) );
}

/**
 * Where to send the browser after a successful sign-in or registration.
 *
 * Defaults to the page the modal was opened from — the whole point of a dialog is that you keep
 * your place — but still runs WooCommerce's redirect filters so anything that insists on its own
 * destination (a membership plugin, a post-signup onboarding flow) keeps working. Anything
 * off-site is dropped by wp_validate_redirect(); an empty result tells the JS to simply reload.
 *
 * @param string       $filter Filter name: woocommerce_login_redirect or woocommerce_registration_redirect.
 * @param WP_User|null $user   The signed-in user, when the filter takes one.
 * @return string URL, or '' to reload in place.
 */
function yazan_auth_redirect_target( $filter, $user = null ) {
	$requested = isset( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( $_POST['redirect'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce already verified by the caller.

	$redirect = ( 'woocommerce_login_redirect' === $filter )
		? apply_filters( $filter, $requested, $user )
		: apply_filters( $filter, $requested );

	return wp_validate_redirect( $redirect, '' );
}

/* ============================================================================
 * Assets + markup.
 * ========================================================================== */

add_action( 'wp_enqueue_scripts', 'yazan_auth_modal_enqueue', 20 );
/**
 * Load the modal's stylesheet and script, and let the social buttons' stylesheet in.
 *
 * @return void
 */
function yazan_auth_modal_enqueue() {
	if ( ! yazan_auth_modal_should_render() ) {
		return;
	}

	wp_enqueue_style( 'yazan-auth-modal', YAZAN_URI . '/assets/css/auth-modal.css', array( 'yazan-main' ), yazan_asset_ver( 'assets/css/auth-modal.css' ) );
	wp_enqueue_script( 'yazan-auth-modal', YAZAN_URI . '/assets/js/auth-modal.js', array(), yazan_asset_ver( 'assets/js/auth-modal.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );

	wp_localize_script(
		'yazan-auth-modal',
		'YazanAuth',
		array(
			'ajax'     => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'yazan_auth' ),
			'redirect' => yazan_auth_modal_current_url(),
			'i18n'     => array(
				'generic' => __( 'Something went wrong. Please try again.', 'yazan' ),
				'network' => __( 'Network error. Please try again.', 'yazan' ),
				'email'   => __( 'Please enter a valid email address.', 'yazan' ),
			),
		)
	);
}

/**
 * Let the social sign-in stylesheet load on a page the modal renders on.
 *
 * Yazan_Social_Auth_UI::enqueue() loads it only on the account page and checkout, which is right
 * for buttons that appear inside a WooCommerce login form — but the modal puts those same buttons
 * on every page of the store.
 *
 * @param bool $needed Whether the plugin already decided the stylesheet is needed.
 * @return bool
 */
add_filter( 'yazan_social_auth_enqueue_css', 'yazan_auth_modal_social_css' );
function yazan_auth_modal_social_css( $needed ) {
	return $needed || yazan_auth_modal_should_render();
}

/**
 * The page the shopper is on, so a successful sign-in returns them to it.
 *
 * @return string
 */
function yazan_auth_modal_current_url() {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

	return '' !== $uri ? home_url( $uri ) : '';
}

/**
 * The "Continue with Google / Apple" block, when the plugin has providers configured.
 *
 * Returns '' when social sign-in is not set up, which is the current state of the store — the
 * modal then shows the email fields alone with no empty divider above them.
 *
 * @return string
 */
function yazan_auth_modal_social_html() {
	if ( ! class_exists( 'Yazan_Social_Auth_UI' ) || ! method_exists( 'Yazan_Social_Auth_UI', 'buttons_html' ) ) {
		return '';
	}

	return Yazan_Social_Auth_UI::buttons_html( __( 'Sign in to Yazan in one tap.', 'yazan' ) );
}

add_action( 'wp_footer', 'yazan_auth_modal_render' );
/**
 * Output the dialog (hidden; opened by auth-modal.js from the header account icon).
 *
 * @return void
 */
function yazan_auth_modal_render() {
	if ( ! yazan_auth_modal_should_render() ) {
		return;
	}

	$register    = yazan_auth_modal_registration_open();
	$social_html = yazan_auth_modal_social_html();
	$eye         = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M1.8 12S5.4 5.4 12 5.4 22.2 12 22.2 12 18.6 18.6 12 18.6 1.8 12 1.8 12Z"/><circle cx="12" cy="12" r="3.2"/></svg>';
	?>
	<div class="yz-auth" data-yz-auth hidden aria-hidden="true">
		<div class="yz-auth__overlay" data-yz-auth-close></div>

		<div class="yz-auth__dialog" role="dialog" aria-modal="true" aria-labelledby="yz-auth-title">

			<button type="button" class="yz-auth__close" data-yz-auth-close aria-label="<?php esc_attr_e( 'Close', 'yazan' ); ?>">
				<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>
			</button>

			<?php /* ---------------------------------------------------- Sign in */ ?>
			<section class="yz-auth__panel" data-yz-auth-panel="login">

				<header class="yz-auth__head">
					<h2 class="yz-auth__title" id="yz-auth-title" data-yz-auth-title><?php esc_html_e( 'Sign in', 'yazan' ); ?></h2>
					<?php if ( $register ) : ?>
						<button type="button" class="yz-auth__switch" data-yz-auth-view="register"><?php esc_html_e( 'Register', 'yazan' ); ?></button>
					<?php endif; ?>
				</header>

				<?php
				// Static provider markup built by the plugin; already escaped at source.
				echo $social_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>

				<form class="yz-auth__form" data-yz-auth-form="login" novalidate>

					<p class="yz-auth__field">
						<label for="yz-auth-email"><?php esc_html_e( 'Email address', 'yazan' ); ?> <span class="yz-auth__req" aria-hidden="true">*</span></label>
						<input type="email" id="yz-auth-email" name="username" autocomplete="username" required aria-required="true" placeholder="<?php esc_attr_e( 'Email address', 'yazan' ); ?>">
					</p>

					<p class="yz-auth__field yz-auth__field--password">
						<label for="yz-auth-password"><?php esc_html_e( 'Password', 'yazan' ); ?> <span class="yz-auth__req" aria-hidden="true">*</span></label>
						<input type="password" id="yz-auth-password" name="password" autocomplete="current-password" required aria-required="true" placeholder="<?php esc_attr_e( 'Enter password', 'yazan' ); ?>">
						<button type="button" class="yz-auth__eye" data-yz-auth-eye aria-label="<?php esc_attr_e( 'Show password', 'yazan' ); ?>" aria-pressed="false">
							<?php echo $eye; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static inline SVG. ?>
						</button>
					</p>

					<label class="yz-auth__remember">
						<input type="checkbox" name="rememberme" value="forever">
						<span><?php esc_html_e( 'Keep me signed in', 'yazan' ); ?></span>
					</label>

					<input type="text" name="yz_hp" class="yz-auth__hp" tabindex="-1" autocomplete="off" aria-hidden="true">

					<p class="yz-auth__error" data-yz-auth-error role="alert" hidden></p>

					<button type="submit" class="yz-auth__submit"><?php esc_html_e( 'Sign in', 'yazan' ); ?></button>
				</form>

				<div class="yz-auth__rule"><span><?php esc_html_e( 'or', 'yazan' ); ?></span></div>

				<p class="yz-auth__aside"><?php esc_html_e( 'Forgotten your password?', 'yazan' ); ?></p>
				<a class="yz-auth__ghost" href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( 'Reset password', 'yazan' ); ?></a>

			</section>

			<?php /* --------------------------------------------------- Register */ ?>
			<?php if ( $register ) : ?>
			<section class="yz-auth__panel" data-yz-auth-panel="register" hidden>

				<header class="yz-auth__head">
					<h2 class="yz-auth__title"><?php esc_html_e( 'Welcome — let\'s set up your account', 'yazan' ); ?></h2>
					<button type="button" class="yz-auth__switch" data-yz-auth-view="login"><?php esc_html_e( 'Sign in', 'yazan' ); ?></button>
				</header>

				<form class="yz-auth__form" data-yz-auth-form="register" novalidate>

					<p class="yz-auth__field">
						<label for="yz-auth-reg-email"><?php esc_html_e( 'Email address', 'yazan' ); ?> <span class="yz-auth__req" aria-hidden="true">*</span></label>
						<input type="email" id="yz-auth-reg-email" name="email" autocomplete="email" required aria-required="true" placeholder="<?php esc_attr_e( 'Email address', 'yazan' ); ?>">
					</p>

					<p class="yz-auth__field">
						<label for="yz-auth-reg-first"><?php esc_html_e( 'First name', 'yazan' ); ?> <span class="yz-auth__req" aria-hidden="true">*</span></label>
						<input type="text" id="yz-auth-reg-first" name="first_name" autocomplete="given-name" required aria-required="true" placeholder="<?php esc_attr_e( 'Enter your first name', 'yazan' ); ?>">
					</p>

					<p class="yz-auth__field yz-auth__field--password">
						<label for="yz-auth-reg-password"><?php esc_html_e( 'Password', 'yazan' ); ?> <span class="yz-auth__req" aria-hidden="true">*</span></label>
						<input type="password" id="yz-auth-reg-password" name="password" autocomplete="new-password" required aria-required="true" placeholder="<?php esc_attr_e( 'Choose a password', 'yazan' ); ?>">
						<button type="button" class="yz-auth__eye" data-yz-auth-eye aria-label="<?php esc_attr_e( 'Show password', 'yazan' ); ?>" aria-pressed="false">
							<?php echo $eye; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static inline SVG. ?>
						</button>
					</p>

					<input type="text" name="yz_hp" class="yz-auth__hp" tabindex="-1" autocomplete="off" aria-hidden="true">

					<p class="yz-auth__error" data-yz-auth-error role="alert" hidden></p>

					<button type="submit" class="yz-auth__submit"><?php esc_html_e( 'Create account', 'yazan' ); ?></button>

					<p class="yz-auth__fine">
						<?php
						printf(
							/* translators: %s: link to the account page. */
							esc_html__( 'Your details stay with Yazan. You can manage them any time from %s.', 'yazan' ),
							'<a href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '">' . esc_html__( 'your account', 'yazan' ) . '</a>'
						);
						?>
					</p>
				</form>

			</section>
			<?php endif; ?>

		</div>
	</div>
	<?php
}
