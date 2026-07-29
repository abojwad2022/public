<?php
/**
 * Product page points earn message.
 *
 * @package Power_Coupons
 * @var int    $points Points earnable.
 * @var string $label  Points label (singular/plural).
 * @var string $message Formatted message template.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( $points <= 0 ) {
	return;
}
?>
<div class="power-coupons-points-earn-message power-coupons-points-product-message">
	<p><?php echo esc_html( $message ); ?></p>
</div>
