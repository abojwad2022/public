<?php
/**
 * Card Style 1 template for Power Coupons.
 *
 * @package Power_Coupons
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<svg width="314" height="104" viewBox="0 0 314 104" fill="none" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true" focusable="false">
	<title><?php esc_html_e( 'Coupon Card', 'power-coupons' ); ?></title>
	<path d="M306 0C310.418 0 314 3.58172 314 8V35.582C313.696 35.5616 313.39 35.5508 313.082 35.5508C304.969 35.5508 298.392 42.7142 298.392 51.5508C298.392 60.3873 304.969 67.5508 313.082 67.5508C313.39 67.5508 313.696 67.539 314 67.5186V96C314 100.418 310.418 104 306 104H8C3.58172 104 0 100.418 0 96V67.5508C8.11295 67.5506 14.6894 60.3872 14.6895 51.5508C14.6895 42.8523 8.31676 35.7747 0.378906 35.5557L0 35.5508V8C0 3.58172 3.58172 0 8 0H306Z" fill="#FEF9C3"></path>
	<path d="M144 8.55072V93.5507" stroke="#E5DD8C" stroke-width="2" stroke-linejoin="round" stroke-dasharray="7 7"></path>
	<rect x="32.5" y="39.5" width="95" height="27" rx="4.5" fill="white"></rect>
	<rect x="32.5" y="39.5" width="95" height="27" rx="4.5" stroke="#E5DD8C"></rect>
	<foreignObject x="32.5" y="40" width="95" height="27" style="text-align:center;">
		<div class="power-coupons-coupon-code" style="text-transform: uppercase;font-size: 12px;text-overflow: ellipsis;overflow: hidden;white-space: nowrap;font-weight: 600;">{power_coupon.code}</div>
	</foreignObject>
	<text x="166" y="35" font-family="inherit" font-size="16" fill="#111827" style="font-weight: 600;font-size: 22px;line-height: 28px;" aria-hidden="true">{power_coupon.discount}</text>
	<text x="166" y="60" font-family="inherit" font-size="16" fill="#111827" style="font-weight: 600;font-size: 14px;line-height: 18px;" aria-hidden="true">{power_coupon.description}</text>
	<text class="power-coupons-coupon-status" x="166" y="85" style="font-weight: 400;font-size: 12px;line-height: 14px;" fill="#374151" aria-hidden="true">{power_coupon.status}</text>
</svg>
