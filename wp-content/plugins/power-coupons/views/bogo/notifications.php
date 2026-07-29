<?php
/**
 * BOGO Notifications Template
 * Displays active BOGO offers status on cart/checkout
 *
 * @package Power_Coupons
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $notifications ) ) {
	return;
}

?>
<div class="power-coupons-bogo-notifications" role="region" aria-label="<?php esc_attr_e( 'Special Offers', 'power-coupons' ); ?>">
	<?php foreach ( $notifications as $notification ) : ?>
		<?php
		$wrapper_class = 'power-coupons-bogo-offer-wrapper';
		if ( ! $notification['is_applied'] ) {
			$wrapper_class .= ' power-coupons-bogo-offer-unclaimed';
		}
		?>
		<?php
		$variable_products = isset( $notification['variable_get_products'] ) && is_array( $notification['variable_get_products'] ) ? $notification['variable_get_products'] : array();
		?>
	<div
		class="<?php echo esc_attr( $wrapper_class ); ?>"
		data-coupon-code="<?php echo esc_attr( $notification['coupon_code'] ); ?>"
	>
		<img src="<?php echo esc_url( $notification['product_image_url'] ); ?>" alt="<?php echo esc_attr( $notification['offer_name'] ); ?>">

		<div class="power-coupons-bogo-offer-right">
			<p class="power-coupons-bogo-offer-badge <?php echo $notification['is_applied'] ? 'power-coupons-bogo-badge-claimed' : 'power-coupons-bogo-badge-available'; ?>">
				<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
					<g clip-path="url(#clip0_4111_1698)">
					<path d="M11.6663 7V12.8333H2.33301V7" stroke="currentColor" stroke-width="0.95" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="M12.8337 4.08325H1.16699V6.99992H12.8337V4.08325Z" stroke="currentColor" stroke-width="0.95" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="M7 12.8333V4.08325" stroke="currentColor" stroke-width="0.95" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="M7.00033 4.08341H4.37533C3.98855 4.08341 3.61762 3.92977 3.34413 3.65628C3.07064 3.38279 2.91699 3.01186 2.91699 2.62508C2.91699 2.23831 3.07064 1.86737 3.34413 1.59388C3.61762 1.32039 3.98855 1.16675 4.37533 1.16675C6.41699 1.16675 7.00033 4.08341 7.00033 4.08341Z" stroke="currentColor" stroke-width="0.95" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="M7 4.08341H9.625C10.0118 4.08341 10.3827 3.92977 10.6562 3.65628C10.9297 3.38279 11.0833 3.01186 11.0833 2.62508C11.0833 2.23831 10.9297 1.86737 10.6562 1.59388C10.3827 1.32039 10.0118 1.16675 9.625 1.16675C7.58333 1.16675 7 4.08341 7 4.08341Z" stroke="currentColor" stroke-width="0.95" stroke-linecap="round" stroke-linejoin="round"/>
					</g>
					<defs>
					<clipPath id="clip0_4111_1698">
					<rect width="14" height="14" fill="white"/>
					</clipPath>
					</defs>
				</svg>

				<?php
				if ( $notification['is_applied'] ) {
					echo esc_html__( 'Offer Applied', 'power-coupons' );
				} else {
					echo esc_html__( 'Available Offer', 'power-coupons' );
				}
				?>
			</p>

			<div>
				<strong class="power-coupons-bogo-offer-title">
				<?php echo esc_html( $notification['offer_name'] ); ?>
				</strong>

				<p class="power-coupons-bogo-offer-description">
				<?php echo esc_html( $notification['message'] ); ?>
				</p>
			</div>

			<?php if ( ! $notification['is_applied'] && $notification['eligible'] ) : ?>
			<button class="power-coupons-bogo-offer-button power-coupons-apply-coupon-btn" data-coupon="<?php echo esc_attr( $notification['coupon_code'] ); ?>">
				<?php esc_html_e( 'Apply Offer', 'power-coupons' ); ?>
			</button>
			<?php elseif ( $notification['is_applied'] ) : ?>
			<span class="power-coupons-bogo-offer-applied-text">
				<?php esc_html_e( 'This offer has been applied to your cart', 'power-coupons' ); ?>
			</span>
				<?php if ( ! empty( $variable_products ) ) : ?>
				<button class="power-coupons-bogo-offer-button power-coupons-bogo-change-options" data-coupon="<?php echo esc_attr( $notification['coupon_code'] ); ?>">
					<?php esc_html_e( 'Change gift options', 'power-coupons' ); ?>
				</button>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<?php
		// Variable "get" products need a variation picker. Render the modal markup here so the
		// frontend script can clone it instead of building it in JS. It is rendered both for the
		// unapplied "apply" state and the applied "change" state (so the customer can swap their
		// auto-added gift). The modal mode + current selection are passed through for pre-fill.
		$show_variation_modal = ! empty( $variable_products )
			&& ( $notification['is_applied'] || $notification['eligible'] );
		if ( $show_variation_modal ) {
			$coupon_code        = $notification['coupon_code'];
			$modal_mode         = $notification['is_applied'] ? 'change' : 'apply';
			$current_selections = isset( $notification['current_selections'] ) && is_array( $notification['current_selections'] ) ? $notification['current_selections'] : array();
			include __DIR__ . '/variation-modal.php';
		}
		?>
	</div>

	<?php endforeach; ?>

</div>
