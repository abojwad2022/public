<?php
/**
 * Order confirmation (Thank You) page points notification.
 *
 * @package Power_Coupons
 * @var int    $points        Points earned or pending for this order.
 * @var string $label         Points label (singular/plural).
 * @var string $points_status 'pending' or 'earned'.
 * @var string $my_points_url URL to the My Points account page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( $points <= 0 ) {
	return;
}
?>
<div class="power-coupons-points-earn-message power-coupons-points-thankyou-message">
	<?php if ( 'pending' === $points_status ) : ?>
		<p>
			<strong><?php echo esc_html( number_format_i18n( $points ) ); ?></strong>
			<?php echo esc_html( $label ); ?>
			<?php esc_html_e( 'will be added to your account once the order is fulfilled.', 'power-coupons' ); ?>
		</p>
	<?php else : ?>
		<p>
			<?php esc_html_e( "You've earned", 'power-coupons' ); ?>
			<strong><?php echo esc_html( number_format_i18n( $points ) ); ?></strong>
			<?php echo esc_html( $label ); ?>
			<?php esc_html_e( 'on this order!', 'power-coupons' ); ?>
			<a href="<?php echo esc_url( $my_points_url ); ?>"><?php esc_html_e( 'View your credits balance', 'power-coupons' ); ?></a>.
		</p>
	<?php endif; ?>
</div>
