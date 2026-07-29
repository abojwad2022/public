<?php
/**
 * Homepage — Newsletter / Community band (sits above the site footer).
 * Renders a styled email capture; inject a real form (SureForms/Mailchimp) via the filter.
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$eyebrow = yazan_home_text( 'newsletter_eyebrow', __( 'Join the Majlis', 'yazan' ) );
$heading = yazan_home_text( 'newsletter_heading', __( 'For rare stones, first.', 'yazan' ) );
$intro   = yazan_home_text( 'newsletter_intro', __( 'New collections, one-of-one stone releases, and quiet invitations — nothing else.', 'yazan' ) );
$cta     = yazan_home_text( 'newsletter_cta', __( 'Subscribe', 'yazan' ) );

/* Provide your own form markup (e.g. a SureForms shortcode) via this filter to replace the demo. */
$custom_form = apply_filters( 'yazan_newsletter_form', '' );
?>
<section id="newsletter" class="yz-home-section yz-newsletter yz-section--ivory">
	<div class="yz-container yz-newsletter__inner yz-reveal">
		<p class="yz-label"><?php echo esc_html( $eyebrow ); ?></p>
		<h2 class="yz-newsletter__title"><?php echo esc_html( $heading ); ?></h2>
		<?php if ( $intro ) : ?><p class="yz-newsletter__intro"><?php echo esc_html( $intro ); ?></p><?php endif; ?>

		<?php if ( $custom_form ) : ?>
			<?php echo do_shortcode( $custom_form ); // phpcs:ignore WordPress.Security.EscapeOutput -- author-provided form markup/shortcode. ?>
		<?php else : ?>
			<form class="yz-newsletter__form" action="#" method="post" novalidate>
				<label class="screen-reader-text" for="yz-news-email"><?php esc_html_e( 'Email address', 'yazan' ); ?></label>
				<input id="yz-news-email" type="email" name="yz_email" placeholder="<?php esc_attr_e( 'Your email address', 'yazan' ); ?>" autocomplete="email" required />
				<button type="submit" class="button"><?php echo esc_html( $cta ); ?></button>
			</form>
			<p class="yz-newsletter__note"><?php esc_html_e( 'Connect this field to your mailing list to activate.', 'yazan' ); ?></p>
		<?php endif; ?>
	</div>
</section>
