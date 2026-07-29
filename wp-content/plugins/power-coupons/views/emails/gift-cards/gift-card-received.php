<?php
/**
 * Gift card received email template.
 *
 * @package Power_Coupons
 * @var string $coupon_code Gift card coupon code.
 * @var float  $amount      Gift card amount.
 * @var string $sender_name Sender's name.
 * @var string $message     Personal message from sender.
 * @var string $expiry_date Formatted expiry date or empty string.
 * @var string $shop_url    Shop page URL.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<p style="font-size: 16px; margin: 0 0 16px;">
	<?php esc_html_e( "You've received a gift card!", 'power-coupons' ); ?>
</p>

<p>
	<?php
	printf(
		/* translators: 1: sender name, 2: formatted amount */
		esc_html__( '%1$s has sent you a %2$s gift card.', 'power-coupons' ),
		'<strong>' . esc_html( $sender_name ) . '</strong>',
		'<strong>' . wp_kses_post( wc_price( $amount ) ) . '</strong>'
	);
	?>
</p>

<?php if ( ! empty( $message ) ) : ?>
<blockquote style="margin: 16px 0; padding: 12px 16px; border-left: 4px solid #15803d; background: #f0fdf4; color: #374151; font-style: italic;">
	<?php echo esc_html( $message ); ?>
</blockquote>
<?php endif; ?>

<div style="margin: 24px 0; padding: 20px; background: #f9fafb; border: 2px dashed #d1d5db; border-radius: 8px; text-align: center;">
	<p style="margin: 0 0 8px; font-size: 13px; color: #6b7280;">
		<?php esc_html_e( 'Your gift card code:', 'power-coupons' ); ?>
	</p>
	<p style="margin: 0; font-size: 24px; font-weight: 700; font-family: monospace; color: #111827; letter-spacing: 2px;">
		<?php echo esc_html( strtoupper( $coupon_code ) ); ?>
	</p>
	<p style="margin: 8px 0 0; font-size: 14px; color: #374151;">
		<?php
		printf(
			/* translators: %s: formatted amount */
			esc_html__( 'Value: %s', 'power-coupons' ),
			wp_kses_post( wc_price( $amount ) )
		);
		?>
	</p>
</div>

<?php if ( ! empty( $expiry_date ) ) : ?>
<p style="font-size: 13px; color: #6b7280;">
	<?php
	printf(
		/* translators: %s: expiry date */
		esc_html__( 'Valid until: %s', 'power-coupons' ),
		esc_html( $expiry_date )
	);
	?>
</p>
<?php else : ?>
<p style="font-size: 13px; color: #6b7280;">
	<?php esc_html_e( 'This gift card does not expire.', 'power-coupons' ); ?>
</p>
<?php endif; ?>

<table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin: 24px auto 0;">
	<tr>
		<td align="center" bgcolor="#15803d" style="border-radius: 6px;">
			<a href="<?php echo esc_url( $shop_url ); ?>" style="display: inline-block; padding: 12px 32px; color: #ffffff; text-decoration: none; font-weight: 600; font-size: 14px; mso-padding-alt: 0;">
				<?php esc_html_e( 'Shop Now', 'power-coupons' ); ?>
			</a>
		</td>
	</tr>
</table>

<p style="margin: 16px 0 0; font-size: 13px; color: #9ca3af; text-align: center;">
	<?php esc_html_e( 'To redeem, enter the code at checkout.', 'power-coupons' ); ?>
</p>
