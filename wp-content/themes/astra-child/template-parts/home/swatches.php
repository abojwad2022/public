<?php
/**
 * Homepage — Collection Swatches row + Big Image band.
 *
 * Reverse-engineers the commafootball.com top strip: a horizontal row of small portrait
 * "collection swatches" (image + label) sitting on the dark bar, followed immediately by a
 * full-bleed BIG IMAGE band. Here the swatches are the store's Yemeni-agate (aqeeq) stone
 * categories, and the big image is a hero agate-ring photograph from the demo image folder.
 *
 * Keeps the reference BEM class `collection-swatches__grid` (as requested) alongside the
 * theme's `yz-` classes so it slots into the existing homepage design system.
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$shop_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );

/*
 * Build the swatch list from real agate categories:
 *   1. children of the `the-stones` parent (the stone-type tree), else
 *   2. top product categories by count.
 * Each swatch uses its category thumbnail; missing thumbnails fall back to a demo image so
 * every swatch always shows a picture (the reference never has an empty tile).
 */
$swatches = array();

$parent = get_term_by( 'slug', 'the-stones', 'product_cat' );
$args   = array(
	'taxonomy'   => 'product_cat',
	'hide_empty' => false,
	'number'     => 8,
	'orderby'    => 'count',
	'order'      => 'DESC',
	'exclude'    => array( (int) get_option( 'default_product_cat' ) ),
);
if ( $parent && ! is_wp_error( $parent ) ) {
	$args['parent'] = $parent->term_id;
}

$terms = get_terms( $args );

// If the-stones has no children, fall back to top-level categories.
if ( ( empty( $terms ) || is_wp_error( $terms ) ) && isset( $args['parent'] ) ) {
	unset( $args['parent'] );
	$terms = get_terms( $args );
}

// Demo fallback images (the "fake images" folder) — cycled for thumbnail-less categories.
$demo_pool = array( 'rare.jpg', 'col-1.jpg', 'col-2.jpg', 'silver.jpg', 'story.jpg', 'col-3.jpg', 'col-4.jpg', 'hero.jpg' );
$demo_i    = 0;

if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
	foreach ( $terms as $term ) {
		$thumb_id = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );
		$image    = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'medium_large' ) : '';
		if ( ! $image ) {
			$image  = YAZAN_URI . '/assets/img/demo/' . $demo_pool[ $demo_i % count( $demo_pool ) ];
			$demo_i++;
		}
		$swatches[] = array(
			'name'  => $term->name,
			'url'   => get_term_link( $term ),
			'image' => $image,
		);
	}
}

/** Allow content/integrations to override the swatch row. */
$swatches = apply_filters( 'yazan_home_swatches', $swatches );

// Big image band — a hero agate-ring photo from the demo folder + filterable content.
$big_image   = yazan_home_image( 'swatches_big_image' );
if ( ! $big_image ) {
	$big_image = YAZAN_URI . '/assets/img/demo/hero.jpg';
}
$big_eyebrow = yazan_home_text( 'swatches_big_eyebrow', __( 'Yemeni Aqeeq · Sterling Silver 925', 'yazan' ) );
$big_title   = yazan_home_text( 'swatches_big_title', __( 'One stone. One ring. One owner.', 'yazan' ) );
$big_cta     = yazan_home_text( 'swatches_big_cta', __( 'Shop the Collection', 'yazan' ) );

if ( empty( $swatches ) ) {
	return;
}
?>
<section class="yz-home-section yz-swatches yz-section--ink">
	<div class="yz-container">
		<div class="collection-swatches">
			<div class="collection-swatches__grid">
				<?php foreach ( $swatches as $swatch ) : ?>
					<a class="collection-swatch" href="<?php echo esc_url( $swatch['url'] ); ?>">
						<span class="collection-swatch__media">
							<img src="<?php echo esc_url( $swatch['image'] ); ?>" alt="<?php echo esc_attr( $swatch['name'] ); ?>" loading="lazy" decoding="async" width="240" height="300">
						</span>
						<span class="collection-swatch__label"><?php echo esc_html( $swatch['name'] ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
	</div>

	<a class="yz-bigimage" href="<?php echo esc_url( $shop_url ); ?>">
		<span class="yz-bigimage__media" style="background-image:url(<?php echo esc_url( $big_image ); ?>)" role="img" aria-label="<?php echo esc_attr( $big_title ); ?>"></span>
		<span class="yz-bigimage__scrim" aria-hidden="true"></span>
		<span class="yz-bigimage__content yz-container">
			<?php if ( $big_eyebrow ) : ?>
				<span class="yz-bigimage__eyebrow yz-label"><?php echo esc_html( $big_eyebrow ); ?></span>
			<?php endif; ?>
			<span class="yz-bigimage__title"><?php echo esc_html( $big_title ); ?></span>
			<span class="yz-bigimage__cta yz-btn-ghost"><?php echo esc_html( $big_cta ); ?></span>
		</span>
	</a>
</section>
