<?php
/**
 * Yazan — Front page (homepage).
 *
 * Recreates the commafootball.com homepage RHYTHM as a luxury jewelry experience (reference Part 6):
 * hero → featured collections → best sellers → brand story → authenticity band (trust) →
 * collection stories (parallax) → reviews → newsletter. The header (section 1) and footer are global
 * (Comma-style header + site footer) and render via get_header()/get_footer().
 *
 * Order note: the authenticity band (trust) sits BEFORE the parallax collection-stories — matching the
 * reference (brand statement → authenticity → featured-collection banner) and keeping the two heavy
 * visual sections (dark story + parallax) from stacking back-to-back.
 *
 * Content is ACF-ready with graceful defaults (see inc/homepage.php). Motion uses the theme's
 * existing system: .yz-reveal / .yz-hero / [data-img-reveal] / [data-parallax].
 *
 * Delete this file to restore the previous (Elementor) homepage — page content is untouched.
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

/*
 * The default order. YAZAN Core's Homepage Manager replaces this list with the published
 * homepage document; with no document published — or with the plugin disabled — this array is
 * what renders, exactly as before.
 */
$yazan_home_sections = apply_filters(
	'yazan_home_sections',
	array(
		// commafootball top division (verified against the live site): swatch rail directly under
		// the header, then the big hero image — so `swatches` (which carries both) leads, replacing
		// the old standalone hero. Re-add 'hero' before 'swatches' to restore the previous
		// full-screen hero.
		'swatches',
		'collections',
		'bestsellers',
		'story',
		'trust',
		'collection-stories',
		'reviews',
		'newsletter',
	)
);
?>
<main id="primary" class="yz-home" role="main">
	<?php
	foreach ( array_values( (array) $yazan_home_sections ) as $yazan_index => $yazan_section ) {
		/*
		 * The index is what lets the Homepage Manager tell two blocks of the SAME type apart —
		 * two Collections rows with different categories, for instance. Without it the second one
		 * would render the first one's content.
		 */
		do_action( 'yazan_home_before_section', $yazan_section, $yazan_index );

		/*
		 * Sections this theme has no template for (video, numbers, questions…) are drawn by the
		 * Homepage Manager, which returns true here. Anything the theme DOES own falls through to
		 * its own template part below, unchanged.
		 */
		if ( ! apply_filters( 'yazan_home_render_section', false, $yazan_section, $yazan_index ) ) {
			get_template_part( 'template-parts/home/' . $yazan_section );
		}

		do_action( 'yazan_home_after_section', $yazan_section, $yazan_index );
	}
	?>
</main>
<?php
get_footer();
