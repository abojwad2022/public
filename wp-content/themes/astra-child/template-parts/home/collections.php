<?php
/**
 * Homepage — Featured Collections (4 large cards).
 * Shows real product categories when available; falls back to the four named YAZAN collections.
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$heading = yazan_home_text( 'collections_heading', __( 'Collections', 'yazan' ) );
$intro   = yazan_home_text( 'collections_intro', __( 'Curated by stone, by metal, by rarity.', 'yazan' ) );

// Preferred real categories (by slug) → fall back to named placeholders.
$cards = array();

if ( function_exists( 'wc_get_page_permalink' ) ) {
	$terms = get_terms(
		array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'number'     => 4,
			'exclude'    => array( (int) get_option( 'default_product_cat' ) ),
			'orderby'    => 'count',
			'order'      => 'DESC',
		)
	);
	if ( ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term ) {
			$thumb_id = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );
			$cards[]  = array(
				'name'  => $term->name,
				'url'   => get_term_link( $term ),
				'image' => $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'large' ) : '',
			);
		}
	}
}

if ( empty( $cards ) ) {
	$shop  = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
	$cards = array(
		array( 'name' => __( 'Yemeni Agate', 'yazan' ),   'url' => $shop, 'image' => '' ),
		array( 'name' => __( 'Silver Rings', 'yazan' ),    'url' => $shop, 'image' => '' ),
		array( 'name' => __( 'Rare Stones', 'yazan' ),     'url' => $shop, 'image' => '' ),
		array( 'name' => __( 'Luxury Handmade', 'yazan' ), 'url' => $shop, 'image' => '' ),
	);
}

/** Allow demo content / integrations to fill or replace the collection cards. */
$cards = apply_filters( 'yazan_home_collection_cards', $cards );
?>
<section id="collections" class="yz-home-section yz-collections yz-section--ivory">
	<div class="yz-container">
		<header class="yz-home-head yz-reveal">
			<h2><?php echo esc_html( $heading ); ?></h2>
			<?php if ( $intro ) : ?><p class="yz-home-head__intro"><?php echo esc_html( $intro ); ?></p><?php endif; ?>
		</header>

		<div class="yz-collections__grid yz-grid yz-grid--4">
			<?php foreach ( $cards as $card ) : ?>
				<a class="yz-collection-card yz-reveal" href="<?php echo esc_url( $card['url'] ); ?>">
					<span class="yz-collection-card__media"<?php echo $card['image'] ? ' style="background-image:url(' . esc_url( $card['image'] ) . ')"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>></span>
					<span class="yz-collection-card__body">
						<span class="yz-collection-card__name"><?php echo esc_html( $card['name'] ); ?></span>
						<span class="yz-collection-card__cta yz-label"><?php esc_html_e( 'Shop', 'yazan' ); ?></span>
					</span>
				</a>
			<?php endforeach; ?>
		</div>
	</div>
</section>
