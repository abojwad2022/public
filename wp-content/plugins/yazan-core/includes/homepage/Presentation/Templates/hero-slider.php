<?php
/**
 * Hero slider.
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

$yz_now = time();

/*
 * Slides outside their window are removed HERE, on the server. Rendering them hidden would ship
 * their images to every visitor and let anyone reading the source see next week's campaign.
 */
$yz_slides = array();

foreach ( (array) ( $content['slides'] ?? array() ) as $yz_slide ) {
	$yz_slide = (array) $yz_slide;
	$yz_from  = (int) ( $yz_slide['window']['from'] ?? 0 );
	$yz_to    = (int) ( $yz_slide['window']['to'] ?? 0 );

	if ( $yz_from && $yz_now < $yz_from ) {
		continue;
	}
	if ( $yz_to && $yz_now >= $yz_to ) {
		continue;
	}
	if ( '' === trim( (string) ( $yz_slide['title'] ?? '' ) ) && ! (int) ( $yz_slide['image']['desktop'] ?? 0 ) ) {
		continue;
	}

	$yz_slides[] = $yz_slide;
}

if ( ! $yz_slides ) {
	return;
}

$yz_count    = count( $yz_slides );
$yz_autoplay = $yz_count > 1 && ! empty( $content['autoplay'] );
$yz_speed    = max( 3, min( 20, (int) ( $content['speed'] ?? 6 ) ) ) * 1000;

/**
 * One slide's picture, art-directed per breakpoint.
 *
 * @param array $image      Responsive media value.
 * @param bool  $is_first   Whether this is the first slide.
 * @return string
 */
$yz_picture = static function ( $image, $is_first ) {
	$desktop = (int) ( $image['desktop'] ?? 0 );

	if ( ! $desktop ) {
		return '';
	}

	$tablet = (int) ( $image['tablet'] ?? 0 );
	$mobile = (int) ( $image['mobile'] ?? 0 );

	$sources = '';

	// Narrowest first: the browser takes the first <source> that matches.
	if ( $mobile ) {
		$sources .= sprintf(
			'<source media="(max-width: 600px)" srcset="%s">',
			esc_attr( (string) wp_get_attachment_image_srcset( $mobile, 'large' ) ?: wp_get_attachment_image_url( $mobile, 'large' ) )
		);
	}

	if ( $tablet ) {
		$sources .= sprintf(
			'<source media="(max-width: 1024px)" srcset="%s">',
			esc_attr( (string) wp_get_attachment_image_srcset( $tablet, 'large' ) ?: wp_get_attachment_image_url( $tablet, 'large' ) )
		);
	}

	/*
	 * The first slide is the page's largest contentful paint. It loads eagerly at high priority;
	 * every other slide is lazy, because a visitor who never reaches slide four should never pay
	 * for its photograph.
	 */
	$img = wp_get_attachment_image(
		$desktop,
		'full',
		false,
		array(
			'class'         => 'yzhp-hero__img',
			'loading'       => $is_first ? 'eager' : 'lazy',
			'decoding'      => $is_first ? 'sync' : 'async',
			'fetchpriority' => $is_first ? 'high' : 'auto',
		)
	);

	return '<picture class="yzhp-hero__media">' . $sources . $img . '</picture>';
};

/**
 * A slide button, or nothing when it is incomplete.
 *
 * @param array  $button Button payload.
 * @param string $class  CSS class.
 * @return void
 */
$yz_button = static function ( $button, $class ) {
	$button = (array) $button;
	$url    = (string) ( $button['url'] ?? '' );
	$label  = (string) ( $button['label'] ?? '' );

	if ( '' === $url || '' === $label ) {
		return;
	}

	printf(
		'<a class="%1$s" href="%2$s"%3$s%4$s>%5$s</a>',
		esc_attr( 'ghost' === ( $button['style'] ?? '' ) ? 'yz-btn-ghost' : $class ),
		esc_url( $url ),
		! empty( $button['new_tab'] ) ? ' target="_blank"' : '',
		! empty( $button['rel'] ) ? ' rel="' . esc_attr( $button['rel'] ) . '"' : '',
		esc_html( $label )
	);
};
?>
<section
	class="yz-home-section yzhp-hero"
	data-yzhp-slider
	data-autoplay="<?php echo $yz_autoplay ? '1' : '0'; ?>"
	data-speed="<?php echo esc_attr( (string) $yz_speed ); ?>"
	aria-roledescription="carousel"
	aria-label="<?php esc_attr_e( 'Featured', 'yazan' ); ?>"
>
	<div class="yzhp-hero__track">
		<?php foreach ( $yz_slides as $yz_index => $yz_slide ) : ?>
			<?php
			$yz_align   = in_array( $yz_slide['align'] ?? 'start', array( 'start', 'center', 'end' ), true ) ? $yz_slide['align'] : 'start';
			$yz_overlay = max( 0, min( 90, (int) ( $yz_slide['overlay'] ?? 45 ) ) );
			?>
			<article
				class="yzhp-hero__slide is-align-<?php echo esc_attr( $yz_align ); ?><?php echo 0 === $yz_index ? ' is-active' : ''; ?>"
				style="--yzhp-hero-overlay:<?php echo esc_attr( (string) ( $yz_overlay / 100 ) ); ?>"
				role="group"
				aria-roledescription="slide"
				aria-label="<?php echo esc_attr( sprintf( /* translators: 1: slide number, 2: total. */ __( '%1$d of %2$d', 'yazan' ), $yz_index + 1, $yz_count ) ); ?>"
				<?php echo 0 === $yz_index ? '' : 'aria-hidden="true"'; ?>
			>
				<?php echo $yz_picture( (array) ( $yz_slide['image'] ?? array() ), 0 === $yz_index ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside the closure. ?>
				<span class="yzhp-hero__scrim" aria-hidden="true"></span>

				<div class="yzhp-hero__inner yz-container">
					<?php if ( ! empty( $yz_slide['eyebrow'] ) ) : ?>
						<p class="yz-label"><?php echo esc_html( $yz_slide['eyebrow'] ); ?></p>
					<?php endif; ?>

					<?php if ( ! empty( $yz_slide['title'] ) ) : ?>
						<h2 class="yzhp-hero__title"><?php echo esc_html( $yz_slide['title'] ); ?></h2>
					<?php endif; ?>

					<?php if ( ! empty( $yz_slide['subtitle'] ) ) : ?>
						<p class="yzhp-hero__subtitle"><?php echo esc_html( $yz_slide['subtitle'] ); ?></p>
					<?php endif; ?>

					<?php if ( ! empty( $yz_slide['description'] ) ) : ?>
						<p class="yzhp-hero__desc"><?php echo esc_html( $yz_slide['description'] ); ?></p>
					<?php endif; ?>

					<div class="yzhp-hero__actions">
						<?php
						$yz_button( $yz_slide['primary'] ?? array(), 'button' );
						$yz_button( $yz_slide['secondary'] ?? array(), 'yz-btn-ghost' );
						?>
					</div>
				</div>
			</article>
		<?php endforeach; ?>
	</div>

	<?php if ( $yz_count > 1 ) : ?>
		<button class="yzhp-hero__nav is-prev" type="button" data-slider-prev aria-label="<?php esc_attr_e( 'Previous slide', 'yazan' ); ?>">&#8249;</button>
		<button class="yzhp-hero__nav is-next" type="button" data-slider-next aria-label="<?php esc_attr_e( 'Next slide', 'yazan' ); ?>">&#8250;</button>

		<div class="yzhp-hero__dots" role="tablist" aria-label="<?php esc_attr_e( 'Choose a slide', 'yazan' ); ?>">
			<?php foreach ( $yz_slides as $yz_index => $yz_unused ) : ?>
				<button
					class="yzhp-hero__dot<?php echo 0 === $yz_index ? ' is-active' : ''; ?>"
					type="button"
					role="tab"
					data-slider-to="<?php echo esc_attr( (string) $yz_index ); ?>"
					aria-selected="<?php echo 0 === $yz_index ? 'true' : 'false'; ?>"
					aria-label="<?php echo esc_attr( sprintf( /* translators: %d: slide number. */ __( 'Slide %d', 'yazan' ), $yz_index + 1 ) ); ?>"
				></button>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</section>
