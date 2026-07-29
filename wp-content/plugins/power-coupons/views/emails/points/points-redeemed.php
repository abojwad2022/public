<?php
/**
 * Points redeemed email template.
 *
 * @package Power_Coupons
 * @var string $user_name User display name.
 * @var int    $points    Points redeemed.
 * @var string $label     Points label.
 * @var int    $balance   Current balance after redemption.
 * @var string $discount  Discount description.
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
		/* translators: 1: points redeemed, 2: points label, 3: discount description */
		esc_html__( 'You have redeemed %1$s %2$s for a %3$s discount.', 'power-coupons' ),
		'<strong>' . esc_html( number_format_i18n( $points ) ) . '</strong>',
		esc_html( $label ),
		esc_html( $discount )
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
