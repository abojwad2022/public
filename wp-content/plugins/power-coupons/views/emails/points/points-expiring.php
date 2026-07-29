<?php
/**
 * Points expiring warning email template.
 *
 * @package Power_Coupons
 * @var string $user_name User display name.
 * @var int    $points    Points about to expire.
 * @var string $label     Points label.
 * @var int    $balance   Current balance.
 * @var int    $days      Days until expiry.
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
		/* translators: 1: points amount, 2: points label, 3: days until expiry */
		esc_html__( '%1$s %2$s will expire in %3$s days.', 'power-coupons' ),
		'<strong>' . esc_html( number_format_i18n( $points ) ) . '</strong>',
		esc_html( $label ),
		esc_html( (string) $days )
	);
	?>
</p>

<p><?php esc_html_e( 'Visit our store and redeem your credits before they expire!', 'power-coupons' ); ?></p>

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
