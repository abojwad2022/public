<?php
/**
 * Questions band.
 *
 * @var array $content Section content (sanitised).
 * @var array $design  Layout payload.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$yz_items = array_values(
	array_filter(
		(array) ( $content['items'] ?? array() ),
		static function ( $item ) {
			return '' !== trim( (string) ( $item['question'] ?? '' ) );
		}
	)
);

if ( ! $yz_items ) {
	return;
}

$yz_tone = 'ink' === ( $content['tone'] ?? 'ivory' ) ? 'ink' : 'ivory';

/*
 * The answers were sanitised on the way IN with the inline policy — links and emphasis only. They
 * are printed with wp_kses_post here as well: output escaping is not a substitute for input
 * sanitisation, and a payload could have been written before a policy tightened.
 */
?>
<section class="yz-home-section yz-section--<?php echo esc_attr( $yz_tone ); ?> yzhp-faq">
	<div class="yz-container">
		<?php if ( ! empty( $content['heading'] ) ) : ?>
			<header class="yz-home-head yz-reveal"><h2><?php echo esc_html( $content['heading'] ); ?></h2></header>
		<?php endif; ?>

		<div class="yzhp-faq__list">
			<?php foreach ( $yz_items as $yz_item ) : ?>
				<details class="yzhp-faq__item yz-reveal">
					<summary class="yzhp-faq__q"><?php echo esc_html( $yz_item['question'] ); ?></summary>
					<div class="yzhp-faq__a"><?php echo wp_kses_post( (string) ( $yz_item['answer'] ?? '' ) ); ?></div>
				</details>
			<?php endforeach; ?>
		</div>
	</div>
</section>
