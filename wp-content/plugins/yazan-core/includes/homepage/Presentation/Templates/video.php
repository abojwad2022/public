<?php
/**
 * Video band.
 *
 * @var array $content Section content (sanitised).
 * @var array $design  Layout payload.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$yz_poster = wp_get_attachment_image_src( (int) ( $content['poster'] ?? 0 ), 'full' );
$yz_video  = wp_get_attachment_url( (int) ( $content['video'] ?? 0 ) );
$yz_tone   = 'ink' === ( $content['tone'] ?? 'ink' ) ? 'ink' : 'ivory';

if ( ! $yz_poster || ! $yz_video ) {
	return;
}
?>
<section class="yz-home-section yz-section--<?php echo esc_attr( $yz_tone ); ?> yzhp-video">
	<div class="yz-container">
		<header class="yz-home-head yz-reveal">
			<?php if ( ! empty( $content['eyebrow'] ) ) : ?>
				<p class="yz-label"><?php echo esc_html( $content['eyebrow'] ); ?></p>
			<?php endif; ?>
			<h2><?php echo esc_html( $content['heading'] ?? '' ); ?></h2>
			<?php if ( ! empty( $content['intro'] ) ) : ?>
				<p class="yz-home-head__intro"><?php echo esc_html( $content['intro'] ); ?></p>
			<?php endif; ?>
		</header>

		<?php
		/*
		 * preload="none" and no autoplay: the poster is an ordinary responsive image and the video
		 * is not fetched until someone presses play. On a phone that is the difference between a
		 * homepage that costs a few hundred kilobytes and one that costs several megabytes.
		 */
		?>
		<div class="yzhp-video__frame yz-reveal">
			<video
				class="yzhp-video__player"
				controls
				preload="none"
				playsinline
				poster="<?php echo esc_url( $yz_poster[0] ); ?>"
				width="<?php echo esc_attr( (string) $yz_poster[1] ); ?>"
				height="<?php echo esc_attr( (string) $yz_poster[2] ); ?>"
			>
				<source src="<?php echo esc_url( $yz_video ); ?>" type="<?php echo esc_attr( (string) get_post_mime_type( (int) $content['video'] ) ); ?>">
				<?php esc_html_e( 'Your browser cannot play this video.', 'yazan' ); ?>
			</video>
		</div>
	</div>
</section>
