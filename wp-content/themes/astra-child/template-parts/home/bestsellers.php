<?php
/**
 * Homepage — Best Sellers (WooCommerce product grid using the theme's styled cards).
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WooCommerce' ) ) {
	return;
}

$heading = yazan_home_text( 'bestsellers_heading', __( 'Best Sellers', 'yazan' ) );
$intro   = yazan_home_text( 'bestsellers_intro', __( 'The pieces our collectors reach for first.', 'yazan' ) );
$view_all = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );

$args = array(
	'post_type'           => 'product',
	'post_status'         => 'publish',
	'posts_per_page'      => 4,
	'meta_key'            => 'total_sales', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	'orderby'             => 'meta_value_num',
	'order'               => 'DESC',
	'ignore_sticky_posts' => true,
	'no_found_rows'       => true,
	'tax_query'           => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		array(
			'taxonomy' => 'product_visibility',
			'field'    => 'name',
			'terms'    => 'exclude-from-catalog',
			'operator' => 'NOT IN',
		),
	),
);

/** Allow demo content / integrations to control which products appear here. */
$args  = apply_filters( 'yazan_home_bestsellers_args', $args );
$query = new WP_Query( $args );
?>
<section id="bestsellers" class="yz-home-section yz-best">
	<div class="yz-container">
		<header class="yz-home-head yz-reveal">
			<h2><?php echo esc_html( $heading ); ?></h2>
			<?php if ( $intro ) : ?><p class="yz-home-head__intro"><?php echo esc_html( $intro ); ?></p><?php endif; ?>
			<a class="yz-home-head__link yz-label" href="<?php echo esc_url( $view_all ); ?>"><?php esc_html_e( 'View all', 'yazan' ); ?></a>
		</header>

		<?php if ( $query->have_posts() ) : ?>
			<div class="woocommerce yz-best__grid">
				<?php woocommerce_product_loop_start(); ?>
				<?php
				while ( $query->have_posts() ) :
					$query->the_post();
					wc_get_template_part( 'content', 'product' ); // uses the theme's card hooks/badges.
				endwhile;
				?>
				<?php woocommerce_product_loop_end(); ?>
			</div>
			<?php wp_reset_postdata(); ?>
		<?php else : ?>
			<p class="yz-empty"><?php esc_html_e( 'New pieces are being prepared.', 'yazan' ); ?></p>
		<?php endif; ?>
	</div>
</section>
