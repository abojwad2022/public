<?php
/**
 * Flash sale band.
 *
 * @var array $content  Section content (sanitised).
 * @var array $design   Layout payload.
 * @var array $schedule Section schedule — the countdown target.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $content['heading'] ) || ! class_exists( 'WooCommerce' ) ) {
	return;
}

$yz_adapter = new \Yazan\Homepage\Infrastructure\Woo\ProductQueryAdapter();
$yz_query   = new WP_Query( $yz_adapter->query_args( (array) ( $content['query'] ?? array() ) ) );

if ( ! $yz_query->have_posts() ) {
	// A sale band with no products on sale is worse than no band: it advertises an offer that is
	// not there.
	wp_reset_postdata();
	return;
}

$yz_ends  = isset( $schedule['to'] ) ? (int) $schedule['to'] : 0;
$yz_now   = time();
$yz_ticks = $yz_ends > $yz_now;

$yz_button = (array) ( $content['button'] ?? array() );
?>
<section class="yz-home-section yz-section--ink yzhp-sale">
	<div class="yz-container">
		<header class="yz-home-head yz-reveal">
			<?php if ( ! empty( $content['eyebrow'] ) ) : ?>
				<p class="yz-label"><?php echo esc_html( $content['eyebrow'] ); ?></p>
			<?php endif; ?>

			<h2><?php echo esc_html( $content['heading'] ); ?></h2>

			<?php if ( ! empty( $content['intro'] ) ) : ?>
				<p class="yz-home-head__intro"><?php echo esc_html( $content['intro'] ); ?></p>
			<?php endif; ?>

			<?php if ( $yz_ticks ) : ?>
				<?php
				/*
				 * The end time is a UTC timestamp in a data attribute and the script does the rest
				 * in the visitor's own clock. Rendering the remaining hours server-side would be
				 * wrong the moment the page is cached — which, on a public homepage, is instantly.
				 */
				?>
				<div class="yzhp-sale__timer" data-yzhp-countdown="<?php echo esc_attr( (string) $yz_ends ); ?>" aria-live="polite">
					<span class="yzhp-sale__unit"><b data-unit="days">--</b><i><?php esc_html_e( 'days', 'yazan' ); ?></i></span>
					<span class="yzhp-sale__unit"><b data-unit="hours">--</b><i><?php esc_html_e( 'hrs', 'yazan' ); ?></i></span>
					<span class="yzhp-sale__unit"><b data-unit="minutes">--</b><i><?php esc_html_e( 'min', 'yazan' ); ?></i></span>
					<span class="yzhp-sale__unit"><b data-unit="seconds">--</b><i><?php esc_html_e( 'sec', 'yazan' ); ?></i></span>
				</div>
			<?php endif; ?>
		</header>

		<div class="woocommerce yzhp-sale__grid">
			<?php
			woocommerce_product_loop_start();

			while ( $yz_query->have_posts() ) :
				$yz_query->the_post();
				// The theme's own product card, with its badges and hooks — a sale row must not
				// look like it came from somewhere else.
				wc_get_template_part( 'content', 'product' );
			endwhile;

			woocommerce_product_loop_end();
			?>
		</div>

		<?php if ( ! empty( $yz_button['url'] ) && ! empty( $yz_button['label'] ) ) : ?>
			<p class="yzhp-sale__more">
				<a class="yz-btn-ghost" href="<?php echo esc_url( $yz_button['url'] ); ?>"<?php echo ! empty( $yz_button['rel'] ) ? ' rel="' . esc_attr( $yz_button['rel'] ) . '"' : ''; ?>>
					<?php echo esc_html( $yz_button['label'] ); ?>
				</a>
			</p>
		<?php endif; ?>
	</div>
</section>
<?php
wp_reset_postdata();
