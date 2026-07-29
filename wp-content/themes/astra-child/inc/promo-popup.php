<?php
/**
 * Yazan — first-order promo popup (email capture → 10% coupon).
 *
 * A self-contained, plugin-free email-capture modal modelled on commafootball's "unlock 10% off"
 * popup. Appears once per visitor a few seconds after landing, on mobile AND desktop. On submit it
 * saves the lead (a private `yz_subscriber` post, viewable in wp-admin), ensures a real WooCommerce
 * coupon `WELCOME10` (10% off, one use per customer) exists, reveals the code and emails it.
 *
 * Security: the AJAX endpoint is nonce-guarded, validates + sanitises the email, dedupes, and has a
 * honeypot. No user input ever reaches the coupon parameters (they are fixed server-side).
 *
 * Disable entirely with:  add_filter( 'yazan_promo_enabled', '__return_false' );
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ============================================================================
 * Config
 * ========================================================================== */

/**
 * Popup settings (admin-editable) merged with defaults. Stored as one option array.
 *
 * @return array{enabled:int,percent:int,code:string,delay:int}
 */
function yazan_promo_settings() {
	$defaults = array(
		'enabled' => 1,
		'percent' => 10,
		'code'    => 'WELCOME10',
		'delay'   => 5, // Seconds before the popup appears.
	);
	$opt = get_option( 'yazan_promo_settings', array() );
	return wp_parse_args( is_array( $opt ) ? $opt : array(), $defaults );
}

/**
 * Master on/off (dashboard toggle, still filterable).
 *
 * @return bool
 */
function yazan_promo_enabled() {
	$s = yazan_promo_settings();
	return (bool) apply_filters( 'yazan_promo_enabled', ! empty( $s['enabled'] ) );
}

/**
 * The coupon code the popup grants (dashboard-editable).
 *
 * @return string
 */
function yazan_promo_coupon_code() {
	$s    = yazan_promo_settings();
	$code = '' !== trim( (string) $s['code'] ) ? strtoupper( trim( (string) $s['code'] ) ) : 'WELCOME10';
	return (string) apply_filters( 'yazan_promo_coupon_code', $code );
}

/**
 * The discount percentage the coupon grants (dashboard-editable, clamped 1–100).
 *
 * @return int
 */
function yazan_promo_coupon_percent() {
	$s = yazan_promo_settings();
	$p = (int) $s['percent'];
	$p = max( 1, min( 100, $p ) );
	return (int) apply_filters( 'yazan_promo_coupon_percent', $p );
}

/**
 * Should the popup render on the current request?
 *
 * Front-end only; never in admin, the customizer preview, feeds, the static preview mode, or on the
 * cart/checkout (don't distract a shopper mid-purchase).
 *
 * @return bool
 */
function yazan_promo_should_render() {
	if ( ! yazan_promo_enabled() ) {
		return false;
	}
	if ( is_admin() || is_feed() || ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) ) {
		return false;
	}
	if ( isset( $_GET['yz-static'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- preview flag only.
		return false;
	}
	if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() ) ) {
		return false;
	}
	return (bool) apply_filters( 'yazan_promo_should_render', true );
}

/* ============================================================================
 * Subscriber storage — a private CPT so leads are viewable in wp-admin.
 * ========================================================================== */

add_action( 'init', 'yazan_promo_register_cpt' );
function yazan_promo_register_cpt() {
	register_post_type(
		'yz_subscriber',
		array(
			'labels'          => array(
				'name'          => __( 'Subscribers', 'yazan' ),
				'singular_name' => __( 'Subscriber', 'yazan' ),
				'menu_name'     => __( 'Subscribers', 'yazan' ),
			),
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => true,
			'menu_icon'       => 'dashicons-email',
			'menu_position'   => 26,
			'capability_type' => 'post',
			'map_meta_cap'    => true,
			'supports'        => array( 'title' ),
			'has_archive'     => false,
			'rewrite'         => false,
			'exclude_from_search' => true,
		)
	);
}

/**
 * True if this email is already stored, so we never duplicate leads.
 *
 * @param string $email Sanitised email.
 * @return bool
 */
function yazan_promo_email_exists( $email ) {
	$found = get_posts(
		array(
			'post_type'      => 'yz_subscriber',
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'title'          => $email,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);
	return ! empty( $found );
}

/* ============================================================================
 * Coupon — ensure the WELCOME10 (percent) coupon exists.
 * ========================================================================== */

/**
 * Ensure the welcome coupon exists and return its code (or '' if WooCommerce is absent).
 *
 * Idempotent: creates the coupon only once, then just returns the code. Parameters are fixed in code
 * — no request input is used — so a public trigger can't tamper with the discount.
 *
 * @return string
 */
function yazan_promo_ensure_coupon() {
	if ( ! class_exists( 'WC_Coupon' ) ) {
		return '';
	}
	$code    = yazan_promo_coupon_code();
	$percent = yazan_promo_coupon_percent();

	$existing_id = function_exists( 'wc_get_coupon_id_by_code' ) ? wc_get_coupon_id_by_code( $code ) : 0;
	if ( $existing_id ) {
		// Keep the live coupon in sync with the dashboard value.
		$coupon = new WC_Coupon( $existing_id );
		if ( 'percent' !== $coupon->get_discount_type() || (int) $coupon->get_amount() !== $percent ) {
			$coupon->set_discount_type( 'percent' );
			$coupon->set_amount( $percent );
			$coupon->save();
		}
		return $code;
	}

	$coupon = new WC_Coupon();
	$coupon->set_code( $code );
	$coupon->set_discount_type( 'percent' );
	$coupon->set_amount( yazan_promo_coupon_percent() );
	$coupon->set_individual_use( true );          // Can't stack with other coupons.
	$coupon->set_usage_limit_per_user( 1 );        // One "first order" per customer.
	$coupon->set_exclude_sale_items( false );
	$coupon->set_description( __( 'First-order welcome discount (email popup).', 'yazan' ) );
	$coupon->save();

	return $code;
}

/* ============================================================================
 * AJAX — subscribe.
 * ========================================================================== */

/** Submissions allowed per IP inside YAZAN_PROMO_RATE_WINDOW. */
const YAZAN_PROMO_RATE_MAX = 5;

/** Throttle window, in seconds. */
const YAZAN_PROMO_RATE_WINDOW = 600;

/**
 * Has this IP exceeded the subscribe throttle? Increments the counter.
 *
 * Keyed on REMOTE_ADDR only — never X-Forwarded-For, which any client can
 * forge, turning a rate limiter into a bypass. Note the trade-off: behind a
 * reverse proxy every visitor shares the proxy's IP and therefore one bucket
 * (see docs/wp-config-yazan-sample.php).
 *
 * @return bool True when the caller should be rejected.
 */
function yazan_promo_rate_limited() {
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$key = 'yz_promo_rl_' . md5( $ip );

	$hits = (int) get_transient( $key );

	if ( $hits >= YAZAN_PROMO_RATE_MAX ) {
		return true;
	}

	set_transient( $key, $hits + 1, YAZAN_PROMO_RATE_WINDOW );

	return false;
}

add_action( 'wp_ajax_yazan_promo_subscribe', 'yazan_promo_subscribe' );
add_action( 'wp_ajax_nopriv_yazan_promo_subscribe', 'yazan_promo_subscribe' );
/**
 * Store the lead + return the coupon code as JSON.
 */
function yazan_promo_subscribe() {
	check_ajax_referer( 'yazan_promo', 'nonce' );

	// Honeypot: a real user never fills this hidden field. Pretend success, store nothing.
	if ( ! empty( $_POST['yz_hp'] ) ) {
		wp_send_json_success( array( 'code' => yazan_promo_coupon_code() ) );
	}

	/*
	 * Per-IP throttle.
	 *
	 * The nonce here is a public one minted for logged-out visitors, so it is a
	 * bot speed-bump, not authentication — anyone can scrape a fresh one from
	 * the page. Without a rate limit this handler is an unauthenticated
	 * "insert a post AND send an email" primitive: point a loop at it with
	 * varying addresses and it will flood the subscriber list and mail-bomb
	 * arbitrary third parties from the store's domain, which is how a sending
	 * reputation gets destroyed.
	 *
	 * Same transient-counter shape already used for the AI chat endpoint.
	 */
	if ( yazan_promo_rate_limited() ) {
		wp_send_json_error(
			array( 'message' => __( 'Too many attempts. Please wait a few minutes and try again.', 'yazan' ) ),
			429
		);
	}

	$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	if ( ! $email || ! is_email( $email ) ) {
		wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'yazan' ) ), 400 );
	}

	// Store the lead (dedupe by email).
	if ( ! yazan_promo_email_exists( $email ) ) {
		wp_insert_post(
			array(
				'post_type'   => 'yz_subscriber',
				'post_status' => 'publish',
				'post_title'  => $email,
			),
			true
		);
	}

	$code = yazan_promo_ensure_coupon();

	// Email the code (Mailpit captures it locally). Non-fatal if mail is unavailable.
	if ( $code ) {
		$percent = yazan_promo_coupon_percent();
		$subject = sprintf(
			/* translators: %s: site name. */
			__( 'Your %s%% welcome code', 'yazan' ),
			$percent
		);
		$message = sprintf(
			/* translators: 1: percent, 2: coupon code. */
			__( "Welcome to Yazan.\n\nHere is your %1\$d%% off code for your first order:\n\n    %2\$s\n\nEnter it at checkout. One use per customer.\n\nWith care,\nYazan", 'yazan' ),
			$percent,
			$code
		);
		wp_mail( $email, wp_specialchars_decode( $subject ), $message );
	}

	wp_send_json_success(
		array(
			'code'    => $code,
			'message' => __( 'Your code is ready.', 'yazan' ),
		)
	);
}

/* ============================================================================
 * Admin — settings page (Subscribers → Popup Settings).
 * ========================================================================== */

add_action( 'admin_init', 'yazan_promo_register_settings' );
function yazan_promo_register_settings() {
	register_setting(
		'yazan_promo',
		'yazan_promo_settings',
		array(
			'type'              => 'array',
			'sanitize_callback' => 'yazan_promo_sanitize_settings',
			'default'           => array(),
		)
	);
}

/**
 * Sanitise the settings form. Clamps the percent, whitelists the coupon code characters, etc.
 *
 * @param mixed $input Raw form input.
 * @return array
 */
function yazan_promo_sanitize_settings( $input ) {
	$input = is_array( $input ) ? $input : array();
	$code  = isset( $input['code'] ) ? strtoupper( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $input['code'] ) ) : '';

	return array(
		'enabled' => empty( $input['enabled'] ) ? 0 : 1,
		'percent' => isset( $input['percent'] ) ? max( 1, min( 100, (int) $input['percent'] ) ) : 10,
		'code'    => '' !== $code ? $code : 'WELCOME10',
		'delay'   => isset( $input['delay'] ) ? max( 0, min( 120, (int) $input['delay'] ) ) : 5,
	);
}

// Whenever the settings change, bring the live coupon in line with the new value / code.
add_action( 'update_option_yazan_promo_settings', 'yazan_promo_ensure_coupon' );
add_action( 'add_option_yazan_promo_settings', 'yazan_promo_ensure_coupon' );

add_action( 'admin_menu', 'yazan_promo_admin_menu' );
function yazan_promo_admin_menu() {
	add_submenu_page(
		'edit.php?post_type=yz_subscriber',
		__( 'Popup Settings', 'yazan' ),
		__( 'Popup Settings', 'yazan' ),
		'manage_options',
		'yazan-promo-settings',
		'yazan_promo_settings_page'
	);
}

/**
 * Render the settings screen.
 */
function yazan_promo_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s = yazan_promo_settings();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'First-order Popup', 'yazan' ); ?></h1>
		<p><?php esc_html_e( 'Controls the "unlock a discount" email popup and the coupon it grants.', 'yazan' ); ?></p>
		<form method="post" action="options.php">
			<?php settings_fields( 'yazan_promo' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable popup', 'yazan' ); ?></th>
					<td><label><input type="checkbox" name="yazan_promo_settings[enabled]" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?>> <?php esc_html_e( 'Show the email-capture popup to visitors (mobile & desktop).', 'yazan' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="yz-promo-percent"><?php esc_html_e( 'Discount value', 'yazan' ); ?></label></th>
					<td>
						<input type="number" id="yz-promo-percent" name="yazan_promo_settings[percent]" value="<?php echo esc_attr( $s['percent'] ); ?>" min="1" max="100" step="1" class="small-text"> %
						<p class="description"><?php esc_html_e( 'Percentage off the first order. Saving updates the coupon to this value.', 'yazan' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="yz-promo-code"><?php esc_html_e( 'Coupon code', 'yazan' ); ?></label></th>
					<td><input type="text" id="yz-promo-code" name="yazan_promo_settings[code]" value="<?php echo esc_attr( $s['code'] ); ?>" class="regular-text" style="text-transform:uppercase"></td>
				</tr>
				<tr>
					<th scope="row"><label for="yz-promo-delay"><?php esc_html_e( 'Show after', 'yazan' ); ?></label></th>
					<td><input type="number" id="yz-promo-delay" name="yazan_promo_settings[delay]" value="<?php echo esc_attr( $s['delay'] ); ?>" min="0" max="120" step="1" class="small-text"> <?php esc_html_e( 'seconds', 'yazan' ); ?></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/* ============================================================================
 * Assets + markup.
 * ========================================================================== */

add_action( 'wp_enqueue_scripts', 'yazan_promo_enqueue', 20 );
function yazan_promo_enqueue() {
	if ( ! yazan_promo_should_render() ) {
		return;
	}
	wp_enqueue_style( 'yazan-promo', YAZAN_URI . '/assets/css/promo.css', array( 'yazan-main' ), yazan_asset_ver( 'assets/css/promo.css' ) );
	wp_enqueue_script( 'yazan-promo', YAZAN_URI . '/assets/js/promo.js', array(), yazan_asset_ver( 'assets/js/promo.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );
	wp_localize_script(
		'yazan-promo',
		'YazanPromo',
		array(
			'ajax'       => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'yazan_promo' ),
			'delayMs'    => (int) apply_filters( 'yazan_promo_delay_ms', max( 0, (int) yazan_promo_settings()['delay'] ) * 1000 ),
			'storageKey' => 'yzPromoSeen',
		)
	);
}

add_action( 'wp_footer', 'yazan_promo_render' );
/**
 * Output the modal markup (hidden; revealed by promo.js once per visitor).
 */
function yazan_promo_render() {
	if ( ! yazan_promo_should_render() ) {
		return;
	}

	$percent  = yazan_promo_coupon_percent();
	$media     = apply_filters( 'yazan_promo_image', YAZAN_URI . '/assets/img/demo/silver.jpg' );
	/* translators: %d: discount percentage. */
	$title_txt = sprintf( __( '%d%% OFF', 'yazan' ), $percent );
	?>
	<div class="yz-promo" data-yz-promo hidden aria-hidden="true">
		<div class="yz-promo__overlay" data-yz-promo-close></div>
		<div class="yz-promo__dialog" role="dialog" aria-modal="true" aria-labelledby="yz-promo-title">
			<button type="button" class="yz-promo__close" data-yz-promo-close aria-label="<?php esc_attr_e( 'Close', 'yazan' ); ?>">
				<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>
			</button>

			<?php if ( $media ) : ?>
				<div class="yz-promo__media" style="background-image:url('<?php echo esc_url( $media ); ?>')" aria-hidden="true"></div>
			<?php endif; ?>

			<div class="yz-promo__body">
				<div class="yz-promo__offer" data-yz-promo-offer>
					<p class="yz-promo__eyebrow"><?php esc_html_e( 'Yazan', 'yazan' ); ?></p>
					<p class="yz-promo__kicker"><?php esc_html_e( 'Want to unlock', 'yazan' ); ?></p>
					<h2 id="yz-promo-title" class="yz-promo__title"><?php echo esc_html( $title_txt ); ?></h2>
					<p class="yz-promo__sub"><?php esc_html_e( 'your first order?', 'yazan' ); ?></p>

					<form class="yz-promo__form" data-yz-promo-form novalidate>
						<label class="screen-reader-text" for="yz-promo-email"><?php esc_html_e( 'Email address', 'yazan' ); ?></label>
						<input type="email" id="yz-promo-email" name="email" required autocomplete="email" placeholder="<?php esc_attr_e( 'Email address', 'yazan' ); ?>">
						<input type="text" name="yz_hp" class="yz-promo__hp" tabindex="-1" autocomplete="off" aria-hidden="true">
						<button type="submit" class="yz-promo__submit"><?php esc_html_e( 'Continue', 'yazan' ); ?></button>
						<p class="yz-promo__error" data-yz-promo-error role="alert" hidden></p>
					</form>

				</div>

				<div class="yz-promo__success" data-yz-promo-success hidden>
					<h3 class="yz-promo__success-title"><?php esc_html_e( 'Welcome to Yazan', 'yazan' ); ?></h3>
					<p class="yz-promo__success-lede"><?php esc_html_e( 'Use this code at checkout for your first-order discount:', 'yazan' ); ?></p>
					<p class="yz-promo__code" data-yz-promo-code><?php echo esc_html( yazan_promo_coupon_code() ); ?></p>
					<button type="button" class="yz-promo__copy" data-yz-promo-copy><?php esc_html_e( 'Copy code', 'yazan' ); ?></button>
					<p class="yz-promo__note"><?php esc_html_e( 'We\'ve emailed it to you too.', 'yazan' ); ?></p>
					<button type="button" class="yz-promo__continue" data-yz-promo-close><?php esc_html_e( 'Continue shopping', 'yazan' ); ?></button>
				</div>
			</div>
		</div>
	</div>

	<button type="button" class="yz-promo-launcher" data-yz-promo-open hidden aria-hidden="true">
		<svg class="yz-promo-launcher__spark" viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M12 2l1.9 6.1L20 10l-6.1 1.9L12 18l-1.9-6.1L4 10l6.1-1.9L12 2z"/></svg>
		<span class="yz-promo-launcher__label"><?php
			/* translators: %d: discount percentage. */
			printf( esc_html__( 'Get %d%% off', 'yazan' ), (int) $percent );
		?></span>
	</button>
	<?php
}
