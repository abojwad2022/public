<?php
/**
 * Journal band — the latest posts.
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

$yz_query = new WP_Query(
	array(
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'posts_per_page'      => max( 1, min( 9, (int) ( $content['count'] ?? 3 ) ) ),
		'ignore_sticky_posts' => true,
		// Nothing here paginates, and counting the whole archive to render three cards is the
		// most expensive part of this query on a site with a real blog.
		'no_found_rows'       => true,
	)
);

if ( ! $yz_query->have_posts() ) {
	// An empty journal renders nothing rather than an empty band with a heading over it.
	wp_reset_postdata();
	return;
}

$yz_tone = 'ink' === ( $content['tone'] ?? 'ivory' ) ? 'ink' : 'ivory';
?>
<section class="yz-home-section yz-section--<?php echo esc_attr( $yz_tone ); ?> yzhp-blog">
	<div class="yz-container">
		<?php if ( ! empty( $content['heading'] ) ) : ?>
			<header class="yz-home-head yz-reveal">
				<h2><?php echo esc_html( $content['heading'] ); ?></h2>
				<?php if ( ! empty( $content['intro'] ) ) : ?>
					<p class="yz-home-head__intro"><?php echo esc_html( $content['intro'] ); ?></p>
				<?php endif; ?>
			</header>
		<?php endif; ?>

		<div class="yzhp-blog__grid">
			<?php
			while ( $yz_query->have_posts() ) :
				$yz_query->the_post();
				?>
				<article class="yzhp-blog__card yz-reveal">
					<a class="yzhp-blog__link" href="<?php the_permalink(); ?>">
						<?php if ( has_post_thumbnail() ) : ?>
							<span class="yzhp-blog__media">
								<?php the_post_thumbnail( 'medium_large', array( 'loading' => 'lazy', 'decoding' => 'async' ) ); ?>
							</span>
						<?php endif; ?>
						<span class="yzhp-blog__body">
							<time class="yz-label" datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>">
								<?php echo esc_html( get_the_date() ); ?>
							</time>
							<span class="yzhp-blog__title"><?php the_title(); ?></span>
							<span class="yzhp-blog__excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 20 ) ); ?></span>
						</span>
					</a>
				</article>
				<?php
			endwhile;
			?>
		</div>
	</div>
</section>
<?php
wp_reset_postdata();
