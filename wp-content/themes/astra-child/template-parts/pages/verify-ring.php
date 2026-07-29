<?php
/**
 * Page template part — Verify a Ring (/verify-ring/).
 *
 * Public authenticity check: enter a Yazan serial, see the ring's certificate (stone, silver,
 * origin, craft). Authenticity ONLY — no owner data is shown or stored (no ownership register).
 * Lookup logic lives in the yazan-core plugin (Yazan_Core_Verify); this file only presents it and
 * degrades gracefully when the plugin is inactive.
 *
 * Rendered via the_content on the 'verify-ring' page (see inc/page-verify-ring.php); styled by
 * assets/css/verify.css. Reuses site-wide utilities plus yz-reveal motion; direction-agnostic.
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$has_engine = class_exists( 'Yazan_Core_Verify' );
$action_url = get_permalink();
$serial     = $has_engine ? Yazan_Core_Verify::current_serial() : '';
$cert       = ( $has_engine && '' !== $serial ) ? Yazan_Core_Verify::lookup( $serial ) : null;
$searched   = ( '' !== $serial );
$contact    = home_url( '/contact-us/' );

/* A certificate detail row — printed only when the value is present. */
$row = static function ( $label, $value ) {
	if ( '' === (string) $value ) {
		return;
	}
	printf(
		'<div class="yz-cert__row"><dt>%1$s</dt><dd>%2$s</dd></div>',
		esc_html( $label ),
		esc_html( $value )
	);
};
?>
<div class="yz-verify yazan">

	<!-- Hero + search -->
	<section class="yz-auth-sec yz-verify-hero yz-section--ink">
		<div class="yz-container yz-verify-hero__inner">
			<p class="yz-eyebrow yz-reveal"><?php esc_html_e( 'Authentication', 'yazan' ); ?></p>
			<h1 class="yz-verify-hero__title yz-reveal"><?php esc_html_e( 'Verify a Yazan ring', 'yazan' ); ?></h1>
			<p class="yz-verify-hero__lede yz-reveal">
				<?php esc_html_e( 'Every Yazan ring carries a unique serial. Enter it below to confirm the piece is genuinely ours and to see its certificate.', 'yazan' ); ?>
			</p>

			<form class="yz-verify-form yz-reveal" method="get" action="<?php echo esc_url( $action_url ); ?>" role="search">
				<label class="screen-reader-text" for="yz-serial"><?php esc_html_e( 'Serial number', 'yazan' ); ?></label>
				<input id="yz-serial" type="text" name="yz_serial" inputmode="latin" autocomplete="off" spellcheck="false"
					value="<?php echo esc_attr( $serial ); ?>"
					placeholder="<?php esc_attr_e( 'e.g. YZ-925-2026-00044', 'yazan' ); ?>"
					aria-label="<?php esc_attr_e( 'Serial number', 'yazan' ); ?>">
				<button type="submit" class="yz-btn-solid"><?php esc_html_e( 'Verify', 'yazan' ); ?></button>
			</form>
			<p class="yz-verify-hint"><?php esc_html_e( 'The serial is printed on your certificate and engraved discreetly inside the band.', 'yazan' ); ?></p>
		</div>
	</section>

	<!-- Result -->
	<section class="yz-auth-sec yz-section--ivory">
		<div class="yz-container">

			<?php if ( ! $has_engine ) : ?>

				<div class="yz-cert-msg yz-reveal">
					<h2><?php esc_html_e( 'Verification is temporarily unavailable', 'yazan' ); ?></h2>
					<p><?php esc_html_e( 'Please try again shortly, or contact us and we will confirm your serial by return.', 'yazan' ); ?></p>
					<a class="yz-btn-ghost" href="<?php echo esc_url( $contact ); ?>"><?php esc_html_e( 'Contact us', 'yazan' ); ?></a>
				</div>

			<?php elseif ( $cert ) : ?>

				<article class="yz-cert yz-reveal" aria-label="<?php esc_attr_e( 'Certificate of authenticity', 'yazan' ); ?>">
					<header class="yz-cert__head">
						<span class="yz-cert__seal" aria-hidden="true">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3"><path d="M12 3l7 3v5c0 4.5-3 7.8-7 9-4-1.2-7-4.5-7-9V6z"/><path d="M9 12l2.2 2.2L15.5 10"/></svg>
						</span>
						<div>
							<p class="yz-cert__status"><?php esc_html_e( 'Genuine — certified by Yazan', 'yazan' ); ?></p>
							<p class="yz-cert__serial"><?php echo esc_html( $cert['serial'] ); ?></p>
						</div>
					</header>

					<div class="yz-cert__body">
						<?php if ( $cert['image'] ) : ?>
							<a class="yz-cert__media" href="<?php echo esc_url( $cert['permalink'] ); ?>">
								<img src="<?php echo esc_url( $cert['image'] ); ?>" alt="<?php echo esc_attr( $cert['name'] ); ?>" loading="lazy">
							</a>
						<?php endif; ?>

						<div class="yz-cert__details">
							<h2 class="yz-cert__name">
								<a href="<?php echo esc_url( $cert['permalink'] ); ?>"><?php echo esc_html( $cert['name'] ); ?></a>
							</h2>

							<dl class="yz-cert__grid">
								<?php
								$row( __( 'Stone', 'yazan' ), $cert['stone'] );
								$row( __( 'Colour', 'yazan' ), $cert['color'] );
								$row( __( 'Origin', 'yazan' ), $cert['origin'] );
								$row( __( 'Silver', 'yazan' ), $cert['metal'] );
								$row( __( 'Purity', 'yazan' ), $cert['purity'] );
								$row( __( 'Shape', 'yazan' ), $cert['shape'] );
								$row( __( 'Stone weight', 'yazan' ), $cert['carat'] );
								$row( __( 'Collection', 'yazan' ), $cert['collection'] );
								$row( __( 'Certified', 'yazan' ), $cert['year'] );
								?>
							</dl>

							<ul class="yz-cert__marks">
								<li><?php esc_html_e( 'Natural Yemeni agate', 'yazan' ); ?></li>
								<li><?php esc_html_e( 'Not dyed · not synthetic', 'yazan' ); ?></li>
								<li><?php esc_html_e( 'Sterling silver · hand-set', 'yazan' ); ?></li>
								<?php if ( $cert['one_of_one'] ) : ?>
									<li><?php esc_html_e( 'One of one', 'yazan' ); ?></li>
								<?php endif; ?>
							</ul>
						</div>
					</div>

					<footer class="yz-cert__foot">
						<?php esc_html_e( 'This certificate confirms authenticity and craft only. It is not a record of ownership.', 'yazan' ); ?>
					</footer>
				</article>

			<?php elseif ( $searched ) : ?>

				<div class="yz-cert-msg yz-cert-msg--none yz-reveal">
					<h2><?php esc_html_e( 'We couldn’t verify this serial', 'yazan' ); ?></h2>
					<p>
						<?php
						printf(
							/* translators: %s: the serial the visitor entered. */
							esc_html__( 'No Yazan ring matches “%s”. Please check the serial and try again — it looks like YZ-925-2026-00044.', 'yazan' ),
							'<strong>' . esc_html( $serial ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inline.
						);
						?>
					</p>
					<a class="yz-btn-ghost" href="<?php echo esc_url( $contact ); ?>"><?php esc_html_e( 'Contact us', 'yazan' ); ?></a>
				</div>

			<?php else : ?>

				<div class="yz-cert-msg yz-cert-msg--idle yz-reveal">
					<p><?php esc_html_e( 'Enter a serial above to see a ring’s certificate of authenticity.', 'yazan' ); ?></p>
				</div>

			<?php endif; ?>

		</div>
	</section>

</div>
