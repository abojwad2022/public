<?php
/**
 * Homepage — Customer Reviews (luxury testimonials).
 * Static defaults now; wire to real WooCommerce reviews in a later phase.
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$heading = yazan_home_text( 'reviews_heading', __( 'Words from Our Collectors', 'yazan' ) );

$reviews = apply_filters(
	'yazan_home_reviews',
	array(
		array(
			'quote' => __( 'The stone is even more alive in person — deep red with a natural band running through it. It feels like it was made for me.', 'yazan' ),
			'name'  => __( 'Ahmed R.', 'yazan' ),
			'city'  => __( 'Dubai', 'yazan' ),
		),
		array(
			'quote' => __( 'Impeccable silverwork and a certificate that made it feel like a real heirloom. Packaging alone was a moment.', 'yazan' ),
			'name'  => __( 'Layla M.', 'yazan' ),
			'city'  => __( 'London', 'yazan' ),
		),
		array(
			'quote' => __( 'I have bought many rings. Nothing compares to knowing mine is one of one, hand-cut and certified.', 'yazan' ),
			'name'  => __( 'Omar K.', 'yazan' ),
			'city'  => __( 'Riyadh', 'yazan' ),
		),
	)
);
?>
<section id="reviews" class="yz-home-section yz-reviews yz-section--ink">
	<div class="yz-container">
		<header class="yz-home-head yz-reveal"><h2><?php echo esc_html( $heading ); ?></h2></header>
		<div class="yz-reviews__grid yz-grid yz-grid--3">
			<?php foreach ( $reviews as $r ) : ?>
				<figure class="yz-review yz-reveal">
					<div class="yz-review__stars" aria-label="<?php esc_attr_e( '5 out of 5 stars', 'yazan' ); ?>">★★★★★</div>
					<blockquote class="yz-review__quote"><?php echo esc_html( $r['quote'] ); ?></blockquote>
					<figcaption class="yz-review__by">
						<span class="yz-review__name"><?php echo esc_html( $r['name'] ); ?></span>
						<span class="yz-review__city"><?php echo esc_html( $r['city'] ); ?></span>
					</figcaption>
				</figure>
			<?php endforeach; ?>
		</div>
	</div>
</section>
