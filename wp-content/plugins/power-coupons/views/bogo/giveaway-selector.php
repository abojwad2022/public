<?php
/**
 * BOGO Giveaway Product Selector Template
 * Allows users to select variations for variable products
 *
 * @package Power_Coupons
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $giveaway_products ) ) {
	return;
}
?>

<div class="power-coupons-bogo-giveaway-selector" role="region" aria-label="<?php esc_attr_e( 'Select Your Free Gift', 'power-coupons' ); ?>">
	<?php foreach ( $giveaway_products as $giveaway ) : ?>
		<div class="giveaway-offer" data-coupon-id="<?php echo esc_attr( $giveaway['coupon_id'] ); ?>">
			<h3 class="giveaway-heading">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: offer name */
						__( 'Select Your Free Gift - %s', 'power-coupons' ),
						$giveaway['offer_name']
					)
				);
				?>
			</h3>
			<p class="giveaway-description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: eligible quantity */
						_n(
							'You can select %d free item from the options below:',
							'You can select %d free items from the options below:',
							$giveaway['eligible_qty'],
							'power-coupons'
						),
						$giveaway['eligible_qty']
					)
				);
				?>
			</p>

			<div class="giveaway-products-grid">
				<?php foreach ( $giveaway['products'] as $product ) : ?>
					<div class="giveaway-product-card" data-product-id="<?php echo esc_attr( $product['id'] ); ?>">
						<div class="product-image">
							<?php echo wp_kses_post( $product['image'] ); ?>
						</div>

						<div class="product-details">
							<h4 class="product-name"><?php echo esc_html( $product['name'] ); ?></h4>

							<?php if ( $product['is_variable'] && ! empty( $product['attributes'] ) ) : ?>
								<!-- Variation selector -->
								<form class="variations-form" data-product-id="<?php echo esc_attr( $product['id'] ); ?>">
									<?php foreach ( $product['attributes'] as $attribute_name => $options ) : ?>
										<div class="variation-attribute">
											<label for="<?php echo esc_attr( sanitize_title( $attribute_name ) . '_' . $product['id'] ); ?>">
												<?php echo esc_html( wc_attribute_label( $attribute_name ) ); ?>
											</label>
											<select
												name="<?php echo esc_attr( $attribute_name ); ?>"
												id="<?php echo esc_attr( sanitize_title( $attribute_name ) . '_' . $product['id'] ); ?>"
												required
												class="variation-select"
											>
												<option value=""><?php esc_html_e( 'Choose an option', 'power-coupons' ); ?></option>
												<?php if ( is_array( $options ) ) : ?>
													<?php foreach ( $options as $option ) : ?>
														<option value="<?php echo esc_attr( $option ); ?>">
															<?php echo esc_html( $option ); ?>
														</option>
													<?php endforeach; ?>
												<?php endif; ?>
											</select>
										</div>
									<?php endforeach; ?>

									<button
										type="submit"
										class="button power-coupons-add-giveaway-btn"
										data-coupon-code="<?php echo esc_attr( $giveaway['coupon_code'] ); ?>"
										data-product-id="<?php echo esc_attr( $product['id'] ); ?>"
										disabled
									>
										<?php esc_html_e( 'Add Free Gift', 'power-coupons' ); ?>
									</button>
								</form>
							<?php else : ?>
								<!-- Simple product - already added -->
								<p class="product-status added">
									<?php esc_html_e( 'Added to cart!', 'power-coupons' ); ?>
								</p>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	<?php endforeach; ?>
</div>
