<?php
/**
 * Page template part — Authentication (/authentication/).
 *
 * Luxury explainer for the Yazan assurance: serial · digital certificate · public verification ·
 * warranty · custom orders. NO ownership (register/transfer) — that is intentionally out of scope.
 *
 * Rendered via the_content on the 'authentication' page (see inc/page-authentication.php); styled by
 * assets/css/authentication.css (enqueued only on this page). Reuses site-wide utilities
 * (yz-container, yz-section modifiers, yz-btn helpers) plus yz-reveal motion; direction-agnostic.
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The public serial-lookup page (now live). Filterable in case it moves.
$verify_url = apply_filters( 'yazan_auth_verify_url', home_url( '/verify-ring/' ) );
$custom_url = apply_filters( 'yazan_auth_custom_url', home_url( '/contact-us/' ) );

/* Minimal, uniform line icons (static, trusted theme markup — currentColor). */
$icons = array(
	'serial'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><path d="M9 3L7 21M17 3l-2 18M4 8h17M3 16h17"/></svg>',
	'certificate' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><rect x="4" y="3" width="16" height="14" rx="1.5"/><path d="M7 7h10M7 10h7"/><circle cx="12" cy="18.5" r="2.5"/><path d="M10.5 20.5L9.5 23l2.5-1.3L14.5 23l-1-2.5"/></svg>',
	'verify'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><path d="M12 3l7 3v5c0 4.5-3 7.8-7 9-4-1.2-7-4.5-7-9V6z"/><path d="M9 12l2.2 2.2L15.5 10"/></svg>',
	'warranty'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><path d="M12 3l7 3v5c0 4.5-3 7.8-7 9-4-1.2-7-4.5-7-9V6z"/><circle cx="12" cy="11" r="2.5"/><path d="M12 13.5V17"/></svg>',
	'custom'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><path d="M6 3h12l3 6-9 12L3 9z"/><path d="M3 9h18M9 3l-3 6 6 12 6-12-3-6"/></svg>',
);

$pillars = array(
	array(
		'id'   => 'serial',
		'i'    => 'serial',
		't'    => __( 'A serial, discreetly set', 'yazan' ),
		'd'    => __( 'Each ring carries a unique serial, recorded the day it is finished — quiet, permanent proof of a single piece.', 'yazan' ),
	),
	array(
		'id'   => 'certificate',
		'i'    => 'certificate',
		't'    => __( 'A digital certificate', 'yazan' ),
		'd'    => __( 'Your ring arrives with a digital certificate of authenticity describing its stone, its silver, and its origin.', 'yazan' ),
	),
	array(
		'id'   => 'verify',
		'i'    => 'verify',
		't'    => __( 'Public verification', 'yazan' ),
		'd'    => __( 'Anyone can confirm a serial against our records — a simple, open way to know a piece is genuinely ours.', 'yazan' ),
	),
	array(
		'id'   => 'warranty',
		'i'    => 'warranty',
		't'    => __( 'Warranty & care', 'yazan' ),
		'd'    => __( 'We stand behind our silverwork and stone-setting, and guide you in caring for natural agate over a lifetime.', 'yazan' ),
	),
	array(
		'id'   => 'custom',
		'i'    => 'custom',
		't'    => __( 'Custom orders', 'yazan' ),
		'd'    => __( 'Commission a ring around a stone of your choosing, with optional hand engraving in Arabic calligraphy.', 'yazan' ),
	),
);

$steps = array(
	array( 'n' => '01', 't' => __( 'Every ring is serialised', 'yazan' ), 'd' => __( 'A unique number is assigned and recorded as the piece is completed.', 'yazan' ) ),
	array( 'n' => '02', 't' => __( 'A certificate travels with it', 'yazan' ), 'd' => __( 'The serial, stone and silver are captured on a digital certificate of authenticity.', 'yazan' ) ),
	array( 'n' => '03', 't' => __( 'Confirm it against our records', 'yazan' ), 'd' => __( 'Check the serial any time to know the ring is genuinely a Yazan piece.', 'yazan' ) ),
);
?>
<div class="yz-auth yazan">

	<!-- Hero -->
	<section class="yz-auth-sec yz-auth-hero yz-section--ink">
		<div class="yz-container yz-auth-hero__inner">
			<p class="yz-eyebrow yz-reveal"><?php esc_html_e( 'Authenticity', 'yazan' ); ?></p>
			<h1 class="yz-auth-hero__title yz-reveal"><?php esc_html_e( 'The Yazan assurance', 'yazan' ); ?></h1>
			<p class="yz-auth-hero__lede yz-reveal">
				<?php esc_html_e( 'Every Yazan ring is a single, natural Yemeni agate — no two alike. Our assurance is simple and verifiable, and it lives with the piece, not in a register.', 'yazan' ); ?>
			</p>
			<div class="yz-auth-hero__cta yz-reveal">
				<a class="yz-btn-solid" href="<?php echo esc_url( $verify_url ); ?>"><?php esc_html_e( 'Verify a ring', 'yazan' ); ?></a>
				<a class="yz-btn-ghost" href="<?php echo esc_url( $custom_url ); ?>"><?php esc_html_e( 'Commission a ring', 'yazan' ); ?></a>
			</div>
		</div>
	</section>

	<!-- Pillars -->
	<section class="yz-auth-sec yz-section--ivory">
		<div class="yz-container">
			<header class="yz-auth-head yz-reveal">
				<p class="yz-eyebrow"><?php esc_html_e( 'What you receive', 'yazan' ); ?></p>
				<h2><?php esc_html_e( 'Proof, in five quiet forms', 'yazan' ); ?></h2>
			</header>
			<div class="yz-auth-pillars">
				<?php foreach ( $pillars as $p ) : ?>
					<article id="<?php echo esc_attr( $p['id'] ); ?>" class="yz-auth-pillar yz-reveal">
						<span class="yz-auth-pillar__icon" aria-hidden="true"><?php echo $icons[ $p['i'] ]; // phpcs:ignore WordPress.Security.EscapeOutput -- static trusted SVG. ?></span>
						<h3 class="yz-auth-pillar__title"><?php echo esc_html( $p['t'] ); ?></h3>
						<p class="yz-auth-pillar__desc"><?php echo esc_html( $p['d'] ); ?></p>
					</article>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

	<!-- How verification works -->
	<section class="yz-auth-sec yz-auth-steps yz-section--ink">
		<div class="yz-container">
			<header class="yz-auth-head yz-reveal">
				<p class="yz-eyebrow"><?php esc_html_e( 'How it works', 'yazan' ); ?></p>
				<h2><?php esc_html_e( 'Verification, from the workshop to your hand', 'yazan' ); ?></h2>
			</header>
			<ol class="yz-auth-steps__grid">
				<?php foreach ( $steps as $s ) : ?>
					<li class="yz-auth-step yz-reveal">
						<span class="yz-auth-step__n"><?php echo esc_html( $s['n'] ); ?></span>
						<h3 class="yz-auth-step__title"><?php echo esc_html( $s['t'] ); ?></h3>
						<p class="yz-auth-step__desc"><?php echo esc_html( $s['d'] ); ?></p>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>
	</section>

	<!-- Closing CTA -->
	<section class="yz-auth-sec yz-section--ivory">
		<div class="yz-container">
			<div class="yz-auth-cta yz-reveal">
				<h2 class="yz-auth-cta__title"><?php esc_html_e( 'Hold something real', 'yazan' ); ?></h2>
				<p class="yz-auth-cta__lede"><?php esc_html_e( 'Confirm a serial, or commission a ring around a stone chosen for you alone.', 'yazan' ); ?></p>
				<div class="yz-auth-cta__row">
					<a class="yz-btn-solid" href="<?php echo esc_url( $verify_url ); ?>"><?php esc_html_e( 'Verify a ring', 'yazan' ); ?></a>
					<a class="yz-btn-ghost" href="<?php echo esc_url( $custom_url ); ?>"><?php esc_html_e( 'Start a custom order', 'yazan' ); ?></a>
				</div>
			</div>
		</div>
	</section>

</div>
