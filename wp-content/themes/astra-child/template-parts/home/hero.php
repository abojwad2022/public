<?php
/**
 * Homepage — Hero (full screen).
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$eyebrow  = yazan_home_text( 'hero_eyebrow', __( 'Yemeni Aqeeq · Sterling Silver 925', 'yazan' ) );
$line_one = yazan_home_text( 'hero_line_1', __( 'Formed by the earth.', 'yazan' ) );
$line_two = yazan_home_text( 'hero_line_2', __( 'Worn for a lifetime.', 'yazan' ) );
$intro    = yazan_home_text( 'hero_intro', __( 'Hand-cut Yemeni agate, set in sterling silver — each ring a singular stone, certified and one of a kind.', 'yazan' ) );
$cta_text = yazan_home_text( 'hero_cta', __( 'Explore Collection', 'yazan' ) );
$cta_url  = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
$image    = yazan_home_image( 'hero_image' );

$style = $image ? ' style="background-image:url(' . esc_url( $image ) . ')"' : '';
?>
<section class="yz-home-section yz-hero<?php echo $image ? ' has-image' : ''; ?>" data-yz-hero>
	<div class="yz-hero__media"<?php echo $style; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?>></div>
	<div class="yz-hero__scrim" aria-hidden="true"></div>

	<div class="yz-hero__inner yz-container">
		<?php if ( $eyebrow ) : ?>
			<p class="yz-hero__eyebrow yz-label"><?php echo esc_html( $eyebrow ); ?></p>
		<?php endif; ?>

		<h1 class="yz-hero__title">
			<span class="line"><span><?php echo esc_html( $line_one ); ?></span></span>
			<span class="line"><span><?php echo esc_html( $line_two ); ?></span></span>
		</h1>

		<?php if ( $intro ) : ?>
			<p class="yz-hero__intro"><?php echo esc_html( $intro ); ?></p>
		<?php endif; ?>

		<a class="yz-hero__cta yz-btn-ghost" href="<?php echo esc_url( $cta_url ); ?>">
			<?php echo esc_html( $cta_text ); ?>
		</a>
	</div>

	<span class="yz-hero__scroll" aria-hidden="true"></span>
</section>
