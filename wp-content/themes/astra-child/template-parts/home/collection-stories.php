<?php
/**
 * Homepage — Collection Stories (full-width, parallax): Rare Stones + Master Silver Craft.
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );

$stories = array(
	array(
		'eyebrow' => yazan_home_text( 'cstory_1_eyebrow', __( 'One of One', 'yazan' ) ),
		'title'   => yazan_home_text( 'cstory_1_title', __( 'Rare Stones', 'yazan' ) ),
		'body'    => yazan_home_text( 'cstory_1_body', __( 'Natural agate is never repeated. Each rare stone carries a pattern found nowhere else — a limited piece with the quiet weight of a collector’s object.', 'yazan' ) ),
		'cta'     => yazan_home_text( 'cstory_1_cta', __( 'Discover Rare Stones', 'yazan' ) ),
		'url'     => yazan_home_text( 'cstory_1_url', $shop ),
		'image'   => yazan_home_image( 'cstory_1_image' ),
	),
	array(
		'eyebrow' => yazan_home_text( 'cstory_2_eyebrow', __( 'The Craft', 'yazan' ) ),
		'title'   => yazan_home_text( 'cstory_2_title', __( 'Master Silver Craft', 'yazan' ) ),
		'body'    => yazan_home_text( 'cstory_2_body', __( 'Every setting is raised by hand in sterling silver using techniques passed down through generations — finished to hold a single stone for a lifetime.', 'yazan' ) ),
		'cta'     => yazan_home_text( 'cstory_2_cta', __( 'See the Craft', 'yazan' ) ),
		'url'     => yazan_home_text( 'cstory_2_url', $shop ),
		'image'   => yazan_home_image( 'cstory_2_image' ),
	),
);

foreach ( $stories as $i => $s ) :
	$flip = ( 1 === $i % 2 ) ? ' is-flipped' : '';
	?>
	<section id="cstory-<?php echo (int) $i; ?>" class="yz-home-section yz-cstory<?php echo esc_attr( $flip ); ?>">
		<div class="yz-cstory__media">
			<div class="yz-cstory__img" data-parallax<?php echo $s['image'] ? ' style="background-image:url(' . esc_url( $s['image'] ) . ')"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>></div>
			<span class="yz-cstory__scrim" aria-hidden="true"></span>
		</div>
		<div class="yz-cstory__panel yz-reveal">
			<p class="yz-label"><?php echo esc_html( $s['eyebrow'] ); ?></p>
			<h2><?php echo esc_html( $s['title'] ); ?></h2>
			<p class="yz-cstory__body"><?php echo esc_html( $s['body'] ); ?></p>
			<a class="yz-btn-ghost" href="<?php echo esc_url( $s['url'] ); ?>"><?php echo esc_html( $s['cta'] ); ?></a>
		</div>
	</section>
	<?php
endforeach;
