<?php
/**
 * Pending review points notice — shown below the "awaiting approval" message.
 *
 * @package Power_Coupons
 * @var int    $points Points the customer will earn once approved.
 * @var string $label  Points label (singular/plural).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( $points <= 0 ) {
	return;
}
?>
<p class="power-coupons-review-pending-points">
	<em>
		<?php esc_html_e( 'Once approved,', 'power-coupons' ); ?>
		<strong><?php echo esc_html( number_format_i18n( $points ) ); ?></strong>
		<?php echo esc_html( $label ); ?>
		<?php esc_html_e( 'will be added to your account.', 'power-coupons' ); ?>
	</em>
</p>
