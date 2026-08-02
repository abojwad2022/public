<?php
/**
 * Brands band.
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

$yz_items = array_values(
	array_filter(
		(array) ( $content['items'] ?? array() ),
		static function ( $item ) {
			return (int) ( $item['logo'] ?? 0 ) > 0;
		}
	)
);

if ( ! $yz_items ) {
	return;
}

$yz_tone = 'ink' === ( $content['tone'] ?? 'ivory' ) ? 'ink' : 'ivory';
?>
<section class="yz-home-section yz-section--<?php echo esc_attr( $yz_tone ); ?> yzhp-brands">
	<div class="yz-container">
		<?php if ( ! empty( $content['heading'] ) ) : ?>
			<header class="yz-home-head yz-reveal"><h2><?php echo esc_html( $content['heading'] ); ?></h2></header>
		<?php endif; ?>

		<div class="yzhp-brands__grid">
			<?php
			foreach ( $yz_items as $yz_item ) :
				$yz_name = (string) ( $yz_item['name'] ?? '' );
				$yz_url  = (string) ( $yz_item['url'] ?? '' );

				// wp_get_attachment_image handles srcset, sizes, lazy loading and dimensions —
				// hand-writing an <img> here would quietly drop all four.
				$yz_img = wp_get_attachment_image(
					(int) $yz_item['logo'],
					'medium',
					false,
					array(
						'class'   => 'yzhp-brands__logo',
						'alt'     => $yz_name,
						'loading' => 'lazy',
					)
				);

				if ( ! $yz_img ) {
					continue;
				}
				?>
				<div class="yzhp-brands__item yz-reveal">
					<?php if ( $yz_url ) : ?>
						<a href="<?php echo esc_url( $yz_url ); ?>" rel="noopener">
							<?php echo $yz_img; // phpcs:ignore WordPress.Security.EscapeOutput -- built by wp_get_attachment_image(). ?>
						</a>
					<?php else : ?>
						<?php echo $yz_img; // phpcs:ignore WordPress.Security.EscapeOutput -- built by wp_get_attachment_image(). ?>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
</section>
