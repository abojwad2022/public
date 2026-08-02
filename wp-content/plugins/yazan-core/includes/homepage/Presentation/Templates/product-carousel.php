<?php
/**
 * Product carousel.
 *
 * @var array $content  Section content (sanitised).
 * @var array $design   Layout payload.
 * @var array $schedule Section schedule.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WooCommerce' ) ) {
	return;
}

$yz_adapter = new \Yazan\Homepage\Infrastructure\Woo\ProductQueryAdapter();
$yz_query   = new WP_Query( $yz_adapter->query_args( (array) ( $content['query'] ?? array() ) ) );

if ( ! $yz_query->have_posts() ) {
	wp_reset_postdata();
	return;
}

$yz_tone = 'ink' === ( $content['tone'] ?? 'ivory' ) ? 'ink' : 'ivory';

/*
 * The rail is CSS scroll-snap, not a transform carousel.
 *
 * That means it swipes on touch, scrolls with a trackpad, tabs through its cards and honours the
 * reading direction — all before a single line of JavaScript runs. The script only adds the two
 * arrow buttons, and the rail keeps working if it never loads.
 */
$yz_per = (array) ( $content['per_view'] ?? array() );

$yz_view = static function ( $device, $fallback ) use ( $yz_per ) {
	$value = $yz_per[ $device ] ?? null;
	return max( 1, min( 6, (int) ( null === $value ? $fallback : $value ) ) );
};

$yz_desktop = $yz_view( 'desktop', 4 );
$yz_tablet  = $yz_view( 'tablet', min( 3, $yz_desktop ) );
// A phone deliberately defaults to a fraction: the sliver of the next card is what says "more".
$yz_mobile  = $yz_view( 'mobile', 1 );

$yz_button = (array) ( $content['button'] ?? array() );
?>
<section class="yz-home-section yz-section--<?php echo esc_attr( $yz_tone ); ?> yzhp-carousel"
	style="--yzhp-per-desktop:<?php echo esc_attr( (string) $yz_desktop ); ?>;--yzhp-per-tablet:<?php echo esc_attr( (string) $yz_tablet ); ?>;--yzhp-per-mobile:<?php echo esc_attr( (string) $yz_mobile ); ?>"
	data-yzhp-carousel
>
	<div class="yz-container">
		<?php if ( ! empty( $content['heading'] ) ) : ?>
			<header class="yz-home-head yz-reveal">
				<h2><?php echo esc_html( $content['heading'] ); ?></h2>
				<?php if ( ! empty( $content['intro'] ) ) : ?>
					<p class="yz-home-head__intro"><?php echo esc_html( $content['intro'] ); ?></p>
				<?php endif; ?>
			</header>
		<?php endif; ?>

		<div class="yzhp-carousel__viewport">
			<button class="yzhp-carousel__nav is-prev" type="button" data-carousel-prev hidden aria-label="<?php esc_attr_e( 'Scroll left', 'yazan' ); ?>">&#8249;</button>

			<div class="woocommerce yzhp-carousel__track" tabindex="0" role="region" aria-label="<?php echo esc_attr( $content['heading'] ? $content['heading'] : __( 'Products', 'yazan' ) ); ?>">
				<?php
				woocommerce_product_loop_start();

				while ( $yz_query->have_posts() ) :
					$yz_query->the_post();
					// The theme's own card, with its badges and hooks.
					wc_get_template_part( 'content', 'product' );
				endwhile;

				woocommerce_product_loop_end();
				?>
			</div>

			<button class="yzhp-carousel__nav is-next" type="button" data-carousel-next hidden aria-label="<?php esc_attr_e( 'Scroll right', 'yazan' ); ?>">&#8250;</button>
		</div>

		<?php if ( ! empty( $yz_button['url'] ) && ! empty( $yz_button['label'] ) ) : ?>
			<p class="yzhp-carousel__more">
				<a class="yz-btn-ghost" href="<?php echo esc_url( $yz_button['url'] ); ?>"<?php echo ! empty( $yz_button['rel'] ) ? ' rel="' . esc_attr( $yz_button['rel'] ) . '"' : ''; ?>>
					<?php echo esc_html( $yz_button['label'] ); ?>
				</a>
			</p>
		<?php endif; ?>
	</div>
</section>
<?php
wp_reset_postdata();
