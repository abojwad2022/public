<?php
/**
 * Homepage — Trust & Quality (icon row).
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$heading = yazan_home_text( 'trust_heading', __( 'Why YAZAN', 'yazan' ) );

/* Minimal, uniform line icons (static, trusted theme markup). */
$icon = array(
	'gem'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25"><path d="M6 3h12l3 6-9 12L3 9z"/><path d="M3 9h18M9 3l-3 6 6 12 6-12-3-6"/></svg>',
	'silver' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25"><circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4"/></svg>',
	'hand'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25"><path d="M7 11V5.5a1.5 1.5 0 013 0V11m0-1V4.5a1.5 1.5 0 013 0V11m0-.5V6a1.5 1.5 0 013 0v7a7 7 0 01-7 7h-1a6 6 0 01-6-6v-1.5a1.5 1.5 0 013 0V13"/></svg>',
	'lock'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25"><rect x="4.5" y="10.5" width="15" height="10" rx="1.5"/><path d="M8 10.5V7a4 4 0 018 0v3.5"/></svg>',
	'ship'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25"><path d="M2 12l20-8-8 20-2.5-9L2 12z"/></svg>',
	'support' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25"><path d="M4 13a8 8 0 0116 0v3a2 2 0 01-2 2h-1v-6h3M4 13v3a2 2 0 002 2h1v-6H4"/></svg>',
);

$items = array(
	array( 'i' => 'gem',    't' => __( 'Authentic Yemeni Agate', 'yazan' ), 'd' => __( 'Genuine aqeeq, sourced with care.', 'yazan' ) ),
	array( 'i' => 'silver', 't' => __( '925 Sterling Silver', 'yazan' ),    'd' => __( 'Solid, hallmarked silver.', 'yazan' ) ),
	array( 'i' => 'hand',   't' => __( 'Handmade Craftsmanship', 'yazan' ), 'd' => __( 'Raised and set by hand.', 'yazan' ) ),
	array( 'i' => 'lock',   't' => __( 'Secure Shopping', 'yazan' ),        'd' => __( 'Encrypted, trusted checkout.', 'yazan' ) ),
	array( 'i' => 'ship',   't' => __( 'International Shipping', 'yazan' ),  'd' => __( 'Insured worldwide delivery.', 'yazan' ) ),
	array( 'i' => 'support', 't' => __( 'Customer Support', 'yazan' ),      'd' => __( 'Personal help, every step.', 'yazan' ) ),
);
?>
<section id="trust" class="yz-home-section yz-trust yz-section--ivory">
	<div class="yz-container">
		<header class="yz-home-head yz-reveal"><h2><?php echo esc_html( $heading ); ?></h2></header>
		<div class="yz-trust__grid yz-grid">
			<?php foreach ( $items as $item ) : ?>
				<div class="yz-trust__item yz-reveal">
					<span class="yz-trust__icon" aria-hidden="true"><?php echo $icon[ $item['i'] ]; // phpcs:ignore WordPress.Security.EscapeOutput -- static trusted SVG. ?></span>
					<h3 class="yz-trust__title"><?php echo esc_html( $item['t'] ); ?></h3>
					<p class="yz-trust__desc"><?php echo esc_html( $item['d'] ); ?></p>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
</section>
