<?php
/**
 * The template a bound page renders through.
 *
 * Deliberately the same shape as astra-child/front-page.php — the same filter for the section
 * list, the same before/after hooks with the same index, and the same chance for the plugin to
 * draw a section the theme has no template for. One contract, two entry points; a second, subtly
 * different loop here is how the homepage and a landing page start behaving differently for
 * reasons nobody can find later.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$yazan_page_sections = apply_filters( 'yazan_home_sections', array() );
?>
<main id="primary" class="yz-home yzhp-page" role="main">
	<?php
	foreach ( array_values( (array) $yazan_page_sections ) as $yazan_index => $yazan_section ) {
		do_action( 'yazan_home_before_section', $yazan_section, $yazan_index );

		if ( ! apply_filters( 'yazan_home_render_section', false, $yazan_section, $yazan_index ) ) {
			get_template_part( 'template-parts/home/' . $yazan_section );
		}

		do_action( 'yazan_home_after_section', $yazan_section, $yazan_index );
	}
	?>
</main>
<?php
get_footer();
