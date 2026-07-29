<?php
/**
 * Yazan — storefront AI concierge widget.
 *
 * A floating, dismissible chat that talks to the yazan-core AI Core over `yazan/v1/ai/chat`. Mirrors
 * the promo-popup module's shape (footer markup rendered hidden + a floating launcher, controlled by a
 * small vanilla IIFE, styled through the semantic theme tokens so it re-tints for Black/Burgundy and
 * mirrors under RTL). It is retrieval-grounded server-side, so it can only ever surface real catalogue
 * products.
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the concierge should render on this request.
 *
 * @return bool
 */
function yazan_chat_should_render() {
	if ( is_admin() || is_feed() ) {
		return false;
	}
	// Requires the AI Core (yazan-core) and the master switch on.
	if ( ! class_exists( 'Yazan_AI_Settings' ) || ! Yazan_AI_Settings::get( 'enabled', true ) ) {
		return false;
	}
	// Don't intrude on checkout / cart flows.
	if ( function_exists( 'is_checkout' ) && ( is_checkout() || is_cart() ) ) {
		return false;
	}
	return (bool) apply_filters( 'yazan_chat_should_render', true );
}

/**
 * Enqueue the concierge assets + bridge data.
 */
add_action( 'wp_enqueue_scripts', 'yazan_chat_enqueue', 20 );
function yazan_chat_enqueue() {
	if ( ! yazan_chat_should_render() ) {
		return;
	}

	wp_enqueue_style( 'yazan-chat', YAZAN_URI . '/assets/css/chat.css', array( 'yazan-main' ), yazan_asset_ver( 'assets/css/chat.css' ) );
	wp_enqueue_script( 'yazan-chat', YAZAN_URI . '/assets/js/chat.js', array(), yazan_asset_ver( 'assets/js/chat.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );

	// Entry chips: most send a prompt; some trigger a client action (ring size) or the human handoff.
	$chips = array(
		array( 'label' => __( 'Gift advisor', 'yazan' ), 'prompt' => __( 'I’m looking for a gift', 'yazan' ) ),
		array( 'label' => __( 'Red aqeeq', 'yazan' ), 'prompt' => __( 'Show me red aqeeq rings', 'yazan' ) ),
		array( 'label' => __( 'Under $300', 'yazan' ), 'prompt' => __( 'What do you have under $300?', 'yazan' ) ),
		array( 'label' => __( 'Ring size', 'yazan' ), 'action' => 'size' ),
	);
	$support = class_exists( 'Yazan_AI_Settings' ) ? (array) Yazan_AI_Settings::get( 'support', array() ) : array();
	if ( ! empty( $support['enabled'] ) ) {
		$chips[] = array( 'label' => __( 'Talk to a person', 'yazan' ), 'action' => 'handoff' );
	}

	wp_localize_script(
		'yazan-chat',
		'YazanChat',
		array(
			'rest'      => esc_url_raw( rest_url( 'yazan/v1/ai/chat' ) ),
			'handoff'   => esc_url_raw( rest_url( 'yazan/v1/ai/chat/handoff' ) ),
			'nonceRest' => esc_url_raw( rest_url( 'yazan/v1/ai/chat-nonce' ) ),
			'nonce'     => wp_create_nonce( 'yazan_ai_chat' ),
			// wp_rest nonce → lets WP honor the login cookie so a signed-in shopper is recognised
			// server-side (personalised concierge). Stale/absent simply falls back to anonymous.
			'restNonce' => wp_create_nonce( 'wp_rest' ),
			'rtl'       => is_rtl() ? 1 : 0,
			'strings'   => array(
				'title'       => __( 'YAZAN Concierge', 'yazan' ),
				'tagline'     => __( 'Here to help you find your stone', 'yazan' ),
				'status'      => __( 'Online · here to help', 'yazan' ),
				'nudge'       => __( 'Looking for a particular stone?', 'yazan' ),
				'greeting'    => __( 'Welcome to YAZAN. Are you looking for a particular colour of aqeeq, or shall I suggest a few pieces?', 'yazan' ),
				'placeholder' => __( 'Ask about a ring…', 'yazan' ),
				'send'        => __( 'Send', 'yazan' ),
				'open'        => __( 'Chat with our concierge', 'yazan' ),
				'close'       => __( 'Close', 'yazan' ),
				'view'        => __( 'View piece', 'yazan' ),
				'error'       => __( 'Something went wrong. Please try again.', 'yazan' ),
				'mic'         => __( 'Speak your question', 'yazan' ),
				'listening'   => __( 'Listening…', 'yazan' ),
				'sizeGuide'   => __( "Finding your ring size is simple:\n\n• Measure the inner diameter (in mm) of a ring you already wear, or\n• Wrap a strip of paper around your finger, mark where it meets, and measure its length (in mm).\n\nTell me the number and I’ll match it to your size — and remember, our rings can be gently resized.", 'yazan' ),
				// Quick-reply suggestions — label shows on the chip; `prompt` is sent, or `action` runs a client flow.
				'chips'       => $chips,
			),
		)
	);
}

/**
 * Render the concierge markup in the footer (hidden until the script boots).
 */
add_action( 'wp_footer', 'yazan_chat_render' );
function yazan_chat_render() {
	if ( ! yazan_chat_should_render() ) {
		return;
	}
	?>
	<div class="yz-chat" data-yz-chat hidden>
		<button type="button" class="yz-chat__launcher" data-yz-chat-toggle aria-expanded="false">
			<span class="yz-chat__launcher-icon" aria-hidden="true">
				<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6">
					<path stroke-linecap="round" stroke-linejoin="round" d="M4 5h16v11H8l-4 4z" />
				</svg>
			</span>
			<span class="yz-chat__launcher-label"><?php echo esc_html__( 'Concierge', 'yazan' ); ?></span>
		</button>

		<div class="yz-chat__nudge" data-yz-chat-nudge hidden>
			<span class="yz-chat__nudge-text" data-yz-chat-nudge-open><?php echo esc_html__( 'Looking for a particular stone?', 'yazan' ); ?></span>
			<button type="button" class="yz-chat__nudge-close" data-yz-chat-nudge-close aria-label="<?php echo esc_attr__( 'Dismiss', 'yazan' ); ?>">&times;</button>
		</div>

		<section class="yz-chat__panel" data-yz-chat-panel role="dialog" aria-modal="false"
			aria-label="<?php echo esc_attr__( 'YAZAN Concierge', 'yazan' ); ?>" hidden>
			<header class="yz-chat__head">
				<span class="yz-chat__avatar" aria-hidden="true">Y</span>
				<span class="yz-chat__head-text">
					<span class="yz-chat__title"><?php echo esc_html__( 'YAZAN Concierge', 'yazan' ); ?></span>
					<span class="yz-chat__status">
						<i class="yz-chat__dot" aria-hidden="true"></i>
						<?php echo esc_html__( 'Online · here to help', 'yazan' ); ?>
					</span>
				</span>
				<button type="button" class="yz-chat__close" data-yz-chat-toggle aria-label="<?php echo esc_attr__( 'Close', 'yazan' ); ?>">&times;</button>
			</header>

			<div class="yz-chat__log" data-yz-chat-log aria-live="polite"></div>

			<form class="yz-chat__form" data-yz-chat-form>
				<input type="text" class="yz-chat__input" data-yz-chat-input
					placeholder="<?php echo esc_attr__( 'Ask about a ring…', 'yazan' ); ?>"
					autocomplete="off" aria-label="<?php echo esc_attr__( 'Message', 'yazan' ); ?>" />
				<button type="button" class="yz-chat__mic" data-yz-chat-mic hidden aria-label="<?php echo esc_attr__( 'Speak your question', 'yazan' ); ?>">
					<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
						<rect x="9" y="3" width="6" height="11" rx="3" />
						<path stroke-linecap="round" d="M5 11a7 7 0 0 0 14 0M12 18v3" />
					</svg>
				</button>
				<button type="submit" class="yz-chat__send" aria-label="<?php echo esc_attr__( 'Send', 'yazan' ); ?>">
					<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
						<path stroke-linecap="round" stroke-linejoin="round" d="M4 12l16-7-7 16-2.5-6.5z" />
					</svg>
				</button>
			</form>
		</section>
	</div>
	<?php
}
