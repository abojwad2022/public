<?php
/**
 * Cart page points earn message.
 *
 * @package Power_Coupons
 * @var int    $points  Points earnable for this cart.
 * @var string $label   Points label.
 * @var string $message Formatted message.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( $points <= 0 ) {
	return;
}
?>
<div class="power-coupons-points-earn-message power-coupons-points-cart-message">
	<p><?php echo esc_html( $message ); ?></p>
</div>
