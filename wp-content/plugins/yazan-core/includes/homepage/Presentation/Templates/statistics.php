<?php
/**
 * Numbers band.
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
			return '' !== trim( (string) ( $item['value'] ?? '' ) );
		}
	)
);

if ( ! $yz_items ) {
	return;
}

$yz_tone = 'ink' === ( $content['tone'] ?? 'ivory' ) ? 'ink' : 'ivory';
?>
<section class="yz-home-section yz-section--<?php echo esc_attr( $yz_tone ); ?> yzhp-stats">
	<div class="yz-container">
		<?php if ( ! empty( $content['heading'] ) ) : ?>
			<header class="yz-home-head yz-reveal"><h2><?php echo esc_html( $content['heading'] ); ?></h2></header>
		<?php endif; ?>

		<div class="yzhp-stats__grid" style="--yzhp-stats-count:<?php echo esc_attr( (string) count( $yz_items ) ); ?>">
			<?php foreach ( $yz_items as $yz_item ) : ?>
				<div class="yzhp-stats__item yz-reveal">
					<span class="yzhp-stats__value"><?php echo esc_html( $yz_item['value'] ); ?></span>
					<span class="yzhp-stats__label yz-label"><?php echo esc_html( $yz_item['label'] ?? '' ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
</section>
