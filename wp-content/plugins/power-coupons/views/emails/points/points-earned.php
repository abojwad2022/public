<?php
/**
 * Points earned email template.
 *
 * @package Power_Coupons
 * @var string $user_name User display name.
 * @var int    $points    Points earned.
 * @var string $label     Points label.
 * @var int    $balance   Current balance after earning.
 * @var int    $order_id  Order ID.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<p>
	<?php
	printf(
		/* translators: %s: customer name */
		esc_html__( 'Hi %s,', 'power-coupons' ),
		esc_html( $user_name )
	);
	?>
</p>

<p>
	<?php
	printf(
		/* translators: 1: points earned, 2: points label, 3: order ID */
		esc_html__( 'You have earned %1$s %2$s on your order #%3$s.', 'power-coupons' ),
		'<strong>' . esc_html( number_format_i18n( $points ) ) . '</strong>',
		esc_html( $label ),
		esc_html( (string) $order_id )
	);
	?>
</p>

<p>
	<?php
	printf(
		/* translators: 1: balance, 2: points label */
		esc_html__( 'Your current balance is %1$s %2$s.', 'power-coupons' ),
		'<strong>' . esc_html( number_format_i18n( $balance ) ) . '</strong>',
		esc_html( $label )
	);
	?>
</p>

<p><?php esc_html_e( 'Keep shopping to earn more rewards!', 'power-coupons' ); ?></p>
