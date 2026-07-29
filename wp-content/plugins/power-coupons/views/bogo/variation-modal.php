<?php
/**
 * BOGO Variation Picker Modal Template
 *
 * Rendered once per variable BOGO offer as a hidden overlay inside the offer
 * wrapper. The frontend script (public/assets/js/bogo.js) clones this markup
 * and appends it to <body> when the customer clicks "Apply Offer", so the modal
 * DOM is built in PHP rather than assembled imperatively in JavaScript.
 *
 * NOTE: a real <template> element is intentionally NOT used here. The WooCommerce
 * block cart re-processes the injected DOM on the client, which drops the inert
 * content of <template> elements. A plain hidden node survives that pass (it is
 * kept invisible while inside the wrapper via a scoped CSS rule) and is shown
 * once the clone is mounted on <body>, outside the wrapper.
 *
 * No element IDs are used: the dormant copy lives in the document alongside the
 * clone while the modal is open, so labels are associated implicitly (the
 * <select> is nested inside its <label>) to avoid duplicate-ID collisions.
 *
 * Expects the following variables in scope (set by views/bogo/notifications.php):
 *
 * @var array<int, array{id:int, name:string, image:string, attributes:array<int, array{name:string, label:string, options:array<int, string>}>}> $variable_products List of variable "get" products.
 * @var string $coupon_code Coupon code this modal applies.
 *
 * @package Power_Coupons
 * @since 1.0.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $variable_products ) || ! is_array( $variable_products ) ) {
	return;
}

$coupon_code = isset( $coupon_code ) ? $coupon_code : '';
// 'apply' (default) adds the gift + applies the coupon; 'change' swaps an already-applied gift.
$modal_mode         = isset( $modal_mode ) && 'change' === $modal_mode ? 'change' : 'apply';
$current_selections = isset( $current_selections ) && is_array( $current_selections ) ? $current_selections : array();
?>
<div class="power-coupons-bogo-modal-overlay power-coupons-bogo-modal-template" aria-hidden="true">
	<div
		class="power-coupons-bogo-modal"
		role="dialog"
		aria-modal="true"
		aria-label="<?php esc_attr_e( 'Choose your free gift options', 'power-coupons' ); ?>"
	>
		<button
			type="button"
			class="power-coupons-bogo-modal-close"
			aria-label="<?php esc_attr_e( 'Close', 'power-coupons' ); ?>"
		>&#10005;</button>

		<h2 class="power-coupons-bogo-modal-title">
			<?php esc_html_e( 'Choose your free gift options', 'power-coupons' ); ?>
		</h2>

		<p class="power-coupons-bogo-modal-subtitle">
			<?php esc_html_e( 'Pick the variation you would like to receive.', 'power-coupons' ); ?>
		</p>

		<form
			class="power-coupons-bogo-modal-form"
			data-coupon-code="<?php echo esc_attr( $coupon_code ); ?>"
			data-mode="<?php echo esc_attr( $modal_mode ); ?>"
			data-current-selections="<?php echo esc_attr( (string) wp_json_encode( $current_selections ) ); ?>"
		>
			<?php foreach ( $variable_products as $product ) : ?>
				<?php
				$product_id   = isset( $product['id'] ) ? intval( $product['id'] ) : 0;
				$product_name = isset( $product['name'] ) ? $product['name'] : '';
				$product_img  = isset( $product['image'] ) ? $product['image'] : '';
				$attributes   = isset( $product['attributes'] ) && is_array( $product['attributes'] ) ? $product['attributes'] : array();
				?>
				<div class="power-coupons-bogo-modal-product" data-product-id="<?php echo esc_attr( (string) $product_id ); ?>">
					<div class="power-coupons-bogo-modal-product-header">
						<?php if ( $product_img ) : ?>
							<img src="<?php echo esc_url( $product_img ); ?>" alt="<?php echo esc_attr( $product_name ); ?>">
						<?php endif; ?>
						<strong class="power-coupons-bogo-modal-product-name"><?php echo esc_html( $product_name ); ?></strong>
					</div>

					<?php foreach ( $attributes as $attribute ) : ?>
						<?php
						$attr_name    = isset( $attribute['name'] ) ? $attribute['name'] : '';
						$attr_label   = isset( $attribute['label'] ) && '' !== $attribute['label'] ? $attribute['label'] : $attr_name;
						$attr_options = isset( $attribute['options'] ) && is_array( $attribute['options'] ) ? $attribute['options'] : array();
						?>
						<label class="power-coupons-bogo-modal-field">
							<span class="power-coupons-bogo-modal-label"><?php echo esc_html( $attr_label ); ?></span>
							<select
								class="power-coupons-bogo-modal-select"
								data-attr-name="<?php echo esc_attr( $attr_name ); ?>"
								required
							>
								<option value=""><?php esc_html_e( 'Choose an option', 'power-coupons' ); ?></option>
								<?php foreach ( $attr_options as $option ) : ?>
									<option value="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $option ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>

			<p class="power-coupons-bogo-modal-error" role="alert" style="display:none;"></p>

			<div class="power-coupons-bogo-modal-actions">
				<button type="button" class="power-coupons-bogo-modal-cancel">
					<?php esc_html_e( 'Cancel', 'power-coupons' ); ?>
				</button>
				<button type="submit" class="power-coupons-bogo-modal-submit" disabled>
					<?php
					if ( 'change' === $modal_mode ) {
						esc_html_e( 'Update gift', 'power-coupons' );
					} else {
						esc_html_e( 'Add Free Gift', 'power-coupons' );
					}
					?>
				</button>
			</div>
		</form>
	</div>
</div>
