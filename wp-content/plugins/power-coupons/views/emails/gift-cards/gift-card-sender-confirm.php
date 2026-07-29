<?php
/**
 * Gift card sender confirmation email template.
 *
 * @package Power_Coupons
 * @var string $recipient_name  Recipient's name.
 * @var float  $amount          Gift card amount.
 * @var string $coupon_code     Gift card coupon code.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<p style="font-size: 16px; margin: 0 0 16px;">
	<?php esc_html_e( 'Your gift card has been sent!', 'power-coupons' ); ?>
</p>

<p>
	<?php
	printf(
		/* translators: 1: formatted amount, 2: recipient name */
		esc_html__( 'A %1$s gift card has been delivered to %2$s.', 'power-coupons' ),
		'<strong>' . wp_kses_post( wc_price( $amount ) ) . '</strong>',
		'<strong>' . esc_html( $recipient_name ) . '</strong>'
	);
	?>
</p>

<p>
	<?php
	printf(
		/* translators: %s: coupon code */
		esc_html__( 'Gift card code: %s', 'power-coupons' ),
		'<code style="font-size: 14px; font-weight: 600; padding: 2px 6px; background: #f3f4f6; border-radius: 4px;">' . esc_html( strtoupper( $coupon_code ) ) . '</code>'
	);
	?>
</p>

<p><?php esc_html_e( 'Thank you for your purchase!', 'power-coupons' ); ?></p>
