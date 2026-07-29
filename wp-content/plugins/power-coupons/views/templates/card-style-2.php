<?php
/**
 * Card Style 2 template for Power Coupons.
 *
 * @package Power_Coupons
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>

<svg width="314" height="104" viewBox="0 0 314 104" fill="none" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true" focusable="false">
	<title><?php esc_html_e( 'Coupon Card', 'power-coupons' ); ?></title>
		<!-- Card background + border -->
	<rect
		x="0.5"
		y="0.5"
		width="313"
		height="103"
		rx="8"
		fill="#F0FDF4"
		stroke="#B7DEC3"
	/>
	<text x="20.5" y="34" font-family="inherit" font-size="14" fill="#111827" style="font-weight: 600;font-size: 22px;line-height: 28px;" aria-hidden="true">{power_coupon.discount}</text>
	<text x="20.5" y="57" font-family="inherit" font-size="14" fill="#111827" style="font-weight: 600;font-size: 14px;line-height: 18px;" aria-hidden="true">{power_coupon.description}</text>
	<rect x="20.5" y="67.5" width="95" height="27" rx="4.5" fill="white"></rect>
	<rect x="20.5" y="67.5" width="95" height="27" rx="4.5" stroke="#B7DEC3"></rect>
	<foreignObject x="20.5" y="68" width="95" height="27" style="text-align:center;">
		<div class="power-coupons-coupon-code" style="text-transform: uppercase;font-size: 12px;text-overflow: ellipsis;overflow: hidden;white-space: nowrap;font-weight: 600;">{power_coupon.code}</div>
	</foreignObject>
	<text class="power-coupons-coupon-status" x="180" y="90" fill="#7F9887"  style="font-weight: 400;font-size: 12px;line-height: 14px;" aria-hidden="true">{power_coupon.status}</text>
</svg>
