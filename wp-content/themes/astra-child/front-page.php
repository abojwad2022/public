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

$yazan_home_sections = array(
	// commafootball top division (verified against the live site): swatch rail directly under the
	// header, then the big hero image — so `swatches` (which carries both) leads, replacing the old
	// standalone hero. Re-add 'hero' before 'swatches' to restore the previous full-screen hero.
	'swatches',
	'collections',
	'bestsellers',
	'story',
	'trust',
	'collection-stories',
	'reviews',
	'newsletter',
);
?>
<main id="primary" class="yz-home" role="main">
	<?php
	foreach ( $yazan_home_sections as $yazan_section ) {
		get_template_part( 'template-parts/home/' . $yazan_section );
	}
	?>
</main>
<?php
get_footer();
