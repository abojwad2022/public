<?php
/**
 * Closing call to action.
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

if ( empty( $content['heading'] ) ) {
	return;
}

$yz_tone  = 'ivory' === ( $content['tone'] ?? 'ink' ) ? 'ivory' : 'ink';
$yz_image = wp_get_attachment_image_url( (int) ( $content['image'] ?? 0 ), 'full' );

$yz_button = static function ( $button, $fallback_class ) {
	$url   = (string) ( $button['url'] ?? '' );
	$label = (string) ( $button['label'] ?? '' );

	if ( '' === $url || '' === $label ) {
		return;
	}

	$class = 'ghost' === ( $button['style'] ?? '' ) ? 'yz-btn-ghost' : $fallback_class;

	printf(
		'<a class="%1$s" href="%2$s"%3$s%4$s>%5$s</a>',
		esc_attr( $class ),
		esc_url( $url ),
		! empty( $button['new_tab'] ) ? ' target="_blank"' : '',
		// rel was set by the sanitiser when the new tab was chosen; printing it from the payload
		// keeps one source of truth instead of two places deciding what "safe link" means.
		! empty( $button['rel'] ) ? ' rel="' . esc_attr( $button['rel'] ) . '"' : '',
		esc_html( $label )
	);
};
?>
<section
	class="yz-home-section yz-section--<?php echo esc_attr( $yz_tone ); ?> yzhp-cta<?php echo $yz_image ? ' has-image' : ''; ?>"
	<?php echo $yz_image ? ' style="background-image:url(' . esc_url( $yz_image ) . ')"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inline. ?>
>
	<?php if ( $yz_image ) : ?>
		<span class="yzhp-cta__scrim" aria-hidden="true"></span>
	<?php endif; ?>

	<div class="yz-container yzhp-cta__inner yz-reveal">
		<?php if ( ! empty( $content['eyebrow'] ) ) : ?>
			<p class="yz-label"><?php echo esc_html( $content['eyebrow'] ); ?></p>
		<?php endif; ?>

		<h2 class="yzhp-cta__title"><?php echo esc_html( $content['heading'] ); ?></h2>

		<?php if ( ! empty( $content['intro'] ) ) : ?>
			<p class="yzhp-cta__intro"><?php echo esc_html( $content['intro'] ); ?></p>
		<?php endif; ?>

		<div class="yzhp-cta__actions">
			<?php
			$yz_button( (array) ( $content['primary'] ?? array() ), 'button' );
			$yz_button( (array) ( $content['secondary'] ?? array() ), 'yz-btn-ghost' );
			?>
		</div>
	</div>
</section>
