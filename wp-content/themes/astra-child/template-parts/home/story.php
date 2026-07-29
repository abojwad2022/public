<?php
/**
 * Homepage — Brand Story: "The Legacy of Yemeni Agate".
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$heading = yazan_home_text( 'story_heading', __( 'The Legacy of Yemeni Agate', 'yazan' ) );
$body    = yazan_home_text(
	'story_body',
	__( 'For centuries, the highlands of Yemen have yielded aqeeq prized across the region — stones formed over ages, each with its own banding and depth. Every YAZAN ring begins with a single such stone, hand-cut and read for its character, then set by hand in sterling silver. From raw stone to finished ring, the work is slow, deliberate, and entirely human.', 'yazan' )
);
$cta   = yazan_home_text( 'story_cta', __( 'Read Our Story', 'yazan' ) );
$url   = yazan_home_text( 'story_cta_url', '' );
$url   = $url ? $url : home_url( '/our-story/' );
$image = yazan_home_image( 'story_image' );
?>
<section id="story" class="yz-home-section yz-story yz-section--ink">
	<div class="yz-container yz-story__grid">
		<div class="yz-story__media" data-img-reveal>
			<div class="yz-story__img"<?php echo $image ? ' style="background-image:url(' . esc_url( $image ) . ')"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>></div>
		</div>
		<div class="yz-story__text yz-reveal">
			<p class="yz-label"><?php esc_html_e( 'Heritage', 'yazan' ); ?></p>
			<h2><?php echo esc_html( $heading ); ?></h2>
			<p class="yz-story__body"><?php echo esc_html( $body ); ?></p>
			<a class="yz-btn-ghost" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $cta ); ?></a>
		</div>
	</div>
</section>
