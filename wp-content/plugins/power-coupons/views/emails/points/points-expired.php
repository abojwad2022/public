<?php
/**
 * Points expired email template.
 *
 * @package Power_Coupons
 * @var string $user_name User display name.
 * @var int    $points    Points that expired.
 * @var string $label     Points label.
 * @var int    $balance   Current balance after expiry.
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
		/* translators: 1: points amount, 2: points label */
		esc_html__( '%1$s %2$s have expired from your account.', 'power-coupons' ),
		'<strong>' . esc_html( number_format_i18n( $points ) ) . '</strong>',
		esc_html( $label )
	);
	?>
</p>

<p>
	<?php
	printf(
		/* translators: 1: balance, 2: points label */
		esc_html__( 'Your remaining balance is %1$s %2$s.', 'power-coupons' ),
		'<strong>' . esc_html( number_format_i18n( $balance ) ) . '</strong>',
		esc_html( $label )
	);
	?>
</p>

<p><?php esc_html_e( 'Keep earning and redeeming to make the most of your rewards!', 'power-coupons' ); ?></p>
