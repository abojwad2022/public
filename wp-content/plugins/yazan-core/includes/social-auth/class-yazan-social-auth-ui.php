<?php
/**
 * The "Continue with …" buttons.
 *
 * Rendered through `woocommerce_login_form_start` and `woocommerce_register_form_start` — two of
 * the core hooks the child theme's form-login.php override was careful to preserve. Nothing in the
 * existing template is touched, which also means the buttons appear automatically wherever a
 * WooCommerce login form does, including CartFlows' checkout.
 *
 * The buttons are anchors, not form controls, for two reasons: the flow is a plain GET navigation
 * to the provider, and these render *inside* an existing <form>, where a nested form would be
 * invalid markup.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Storefront button rendering for social sign-in.
 */
class Yazan_Social_Auth_UI {

	/**
	 * Render the buttons at most once per page.
	 *
	 * The theme now presents sign-in and registration as ONE card with registration folded behind a
	 * toggle, so both hooks below fire on the same screen. Rendering on each would put two identical
	 * pairs of provider buttons a few hundred pixels apart. Whichever form comes first wins — which
	 * is the login form when both are present, and the register form on a page that only has that.
	 *
	 * @var bool
	 */
	private static $rendered = false;

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );

		// Top of the sign-in form and top of the register form — a shopper who has decided to make
		// an account is exactly who should be offered the one-tap route instead.
		add_action( 'woocommerce_login_form_start', array( __CLASS__, 'render_login' ) );
		add_action( 'woocommerce_register_form_start', array( __CLASS__, 'render_register' ) );
	}

	/**
	 * Load the stylesheet only where a login form can appear. Hook: wp_enqueue_scripts.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! Yazan_Social_Auth::is_enabled() || is_user_logged_in() ) {
			return;
		}

		$needed = function_exists( 'is_account_page' ) && ( is_account_page() || is_checkout() );

		/**
		 * Whether the social sign-in stylesheet is needed on this request.
		 *
		 * The default is "wherever a WooCommerce login form renders". A surface that shows the
		 * buttons somewhere else — the theme's sign-in card, which opens over any page of the
		 * store — filters this to true so the buttons are not left unstyled.
		 *
		 * @param bool $needed Whether the stylesheet is needed.
		 */
		if ( ! apply_filters( 'yazan_social_auth_enqueue_css', $needed ) ) {
			return;
		}

		$relative = 'assets/css/social-auth.css';
		$path     = YAZAN_CORE_DIR . $relative;

		wp_enqueue_style(
			'yazan-social-auth',
			YAZAN_CORE_URL . $relative,
			array(),
			file_exists( $path ) ? YAZAN_CORE_VERSION . '.' . filemtime( $path ) : YAZAN_CORE_VERSION
		);
	}

	/* --------------------------------------------------------------------- */
	/* Rendering                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * Buttons above the sign-in fields. Hook: woocommerce_login_form_start.
	 *
	 * @return void
	 */
	public static function render_login() {
		self::render( __( 'Sign in to Yazan in one tap.', 'yazan' ) );
	}

	/**
	 * Buttons above the registration fields. Hook: woocommerce_register_form_start.
	 *
	 * @return void
	 */
	public static function render_register() {
		self::render( __( 'Create your account in one tap — no form to fill in.', 'yazan' ) );
	}

	/**
	 * Render the button block through a WooCommerce hook, at most once per page.
	 *
	 * @param string $intro Short line above the buttons.
	 * @return void
	 */
	private static function render( $intro ) {
		if ( self::$rendered || is_user_logged_in() ) {
			return;
		}

		$html = self::buttons_html( $intro );
		if ( '' === $html ) {
			return;
		}

		self::$rendered = true;

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped in buttons_html().
	}

	/**
	 * The provider buttons as markup, for a surface that is not a WooCommerce login form.
	 *
	 * Public and guard-free on purpose: the once-per-page guard belongs to the hook path above, and
	 * a caller that asks for this markup explicitly (the theme's sign-in card) knows where it is
	 * putting it. Returns '' when no provider is configured, so the caller can leave out the
	 * divider and heading that would otherwise sit above nothing.
	 *
	 * The markup must be built per request and never cached: start_url() embeds a one-shot
	 * `yazan_social_auth` nonce, and a stale one lands the shopper on "That sign-in link has
	 * expired."
	 *
	 * @param string $intro  Short line above the buttons. Pass '' to omit it.
	 * @param string $layout 'list' for full-width labelled buttons, 'icons' for a row of square tiles.
	 * @return string
	 */
	public static function buttons_html( $intro = '', $layout = 'list' ) {
		if ( is_user_logged_in() ) {
			return '';
		}

		$providers = Yazan_Social_Auth::providers();
		if ( empty( $providers ) ) {
			return '';
		}

		$icons    = ( 'icons' === $layout );
		$redirect = self::current_url();

		$html = '<div class="yz-social-auth' . ( $icons ? ' yz-social-auth--icons' : '' ) . '">';

		if ( '' !== $intro ) {
			$html .= '<p class="yz-social-auth__intro">' . esc_html( $intro ) . '</p>';
		}

		$html .= $icons ? '<div class="yz-social-auth__tiles">' : '';

		foreach ( $providers as $key => $provider ) {
			/* translators: %s: provider name, e.g. Google or Apple. */
			$label = sprintf( __( 'Continue with %s', 'yazan' ), $provider->label() );
			$url   = Yazan_Social_Auth::start_url( $key, $redirect );

			if ( $icons ) {
				// Icon-only tile: the accessible name carries the label the eye gets from the mark.
				$html .= sprintf(
					'<a class="yz-social-auth__tile yz-social-auth__tile--%1$s" href="%2$s" rel="nofollow" aria-label="%3$s" title="%3$s">%4$s</a>',
					esc_attr( $key ),
					esc_url( $url ),
					esc_attr( $label ),
					self::icon( $key )
				);
				continue;
			}

			$html .= sprintf(
				'<a class="yz-social-auth__btn yz-social-auth__btn--%1$s" href="%2$s" rel="nofollow">%3$s<span class="yz-social-auth__label">%4$s</span></a>',
				esc_attr( $key ),
				esc_url( $url ),
				self::icon( $key ),
				esc_html( $label )
			);
		}

		$html .= $icons ? '</div>' : '';

		if ( ! $icons ) {
			$html .= '<div class="yz-social-auth__divider"><span>' . esc_html__( 'or use your email', 'yazan' ) . '</span></div>';
		}

		return $html . '</div>';
	}

	/**
	 * The page the shopper is on, so they are returned to it after signing in.
	 *
	 * @return string
	 */
	private static function current_url() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		return '' !== $uri ? home_url( $uri ) : '';
	}

	/**
	 * Provider marks, inline so the buttons cost no extra request and no third-party asset.
	 *
	 * Both are the providers' official sign-in marks and are used in the arrangement their brand
	 * guidelines require: Google's four-colour G on a light button, Apple's logo on a solid button
	 * alongside "Continue with Apple".
	 *
	 * @param string $key Provider key.
	 * @return string Static SVG markup.
	 */
	private static function icon( $key ) {
		if ( 'google' === $key ) {
			return '<span class="yz-social-auth__icon" aria-hidden="true">'
				. '<svg viewBox="0 0 18 18" width="18" height="18" focusable="false">'
				. '<path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.7-1.57 2.68-3.88 2.68-6.62z"/>'
				. '<path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.81.54-1.84.86-3.04.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.33A9 9 0 0 0 9 18z"/>'
				. '<path fill="#FBBC05" d="M3.97 10.72a5.41 5.41 0 0 1 0-3.44V4.95H.96a9 9 0 0 0 0 8.1l3.01-2.33z"/>'
				. '<path fill="#EA4335" d="M9 3.58c1.32 0 2.5.45 3.44 1.35l2.58-2.58C13.46.89 11.43 0 9 0A9 9 0 0 0 .96 4.95l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58z"/>'
				. '</svg></span>';
		}

		if ( 'apple' === $key ) {
			return '<span class="yz-social-auth__icon" aria-hidden="true">'
				. '<svg viewBox="0 0 18 18" width="18" height="18" focusable="false">'
				. '<path fill="currentColor" d="M13.06 9.53c-.02-2.02 1.65-2.99 1.72-3.04-.94-1.37-2.4-1.56-2.92-1.58-1.24-.13-2.42.73-3.05.73-.63 0-1.6-.71-2.63-.69-1.35.02-2.6.78-3.29 1.99-1.4 2.43-.36 6.03 1.01 8 .67.96 1.47 2.04 2.51 2 1.01-.04 1.39-.65 2.61-.65 1.22 0 1.56.65 2.63.63 1.09-.02 1.78-.98 2.44-1.95.77-1.12 1.09-2.2 1.11-2.26-.02-.01-2.13-.82-2.14-3.18zM11.06 3.6c.56-.68.94-1.62.83-2.56-.81.03-1.79.54-2.36 1.21-.51.6-.96 1.56-.84 2.48.9.07 1.82-.46 2.37-1.13z"/>'
				. '</svg></span>';
		}

		return '';
	}
}
