<?php
/**
 * Cart/checkout points credit redemption widget.
 *
 * @package Power_Coupons
 * @var int    $balance               User's current points balance.
 * @var string $label                 Points label.
 * @var bool   $can_redeem            Whether user meets minimum points threshold.
 * @var int    $redemption_ratio      Points per 1 currency unit of discount.
 * @var string $coupon_code           Virtual coupon code for this user.
 * @var bool   $credit_applied        Whether a credit is currently applied.
 * @var array{points: int, discount: float}|null $credit_data Session credit data if applied.
 * @var string $redemption_mode       'full' or 'max_limit'.
 * @var float  $full_discount         Pre-calculated discount amount in full mode.
 * @var int    $full_points           Points that will be used in full mode.
 * @var int    $max_credits_per_order Per-order cap (max_limit mode); 0 means no cap.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! is_user_logged_in() || $balance <= 0 ) {
	return;
}
?>
<div class="power-coupons-points-redeem-widget" id="power-coupons-points-redeem-widget">
	<div class="power-coupons-points-balance">
		<span class="power-coupons-points-balance-label">
			<?php
			printf(
				/* translators: 1: points balance, 2: points label */
				esc_html__( 'Your balance: %1$s %2$s', 'power-coupons' ),
				'<strong>' . esc_html( number_format_i18n( $balance ) ) . '</strong>',
				esc_html( $label )
			);
			?>
		</span>
	</div>

	<?php if ( $credit_applied && ! empty( $credit_data ) ) : ?>
		<div class="power-coupons-points-credit-applied">
			<span class="power-coupons-points-credit-info">
				<?php
				printf(
					/* translators: 1: discount amount, 2: points used, 3: points label */
					esc_html__( '%1$s discount applied (%2$s %3$s)', 'power-coupons' ),
					wp_kses_post( wc_price( $credit_data['discount'] ) ),
					esc_html( number_format_i18n( $credit_data['points'] ) ),
					esc_html( $label )
				);
				?>
			</span>
			<button
				type="button"
				class="woocommerce-Button button wp-element-button power-coupons-remove-credit-btn"
			>
				<?php esc_html_e( 'Remove', 'power-coupons' ); ?>
			</button>
		</div>
	<?php elseif ( $can_redeem ) : ?>
		<?php if ( 'full' === $redemption_mode ) : ?>
			<div class="power-coupons-points-credit-form power-coupons-points-full-mode">
				<div class="power-coupons-points-credit-toggle-row">
					<span class="power-coupons-points-credit-toggle-info">
						<?php
						printf(
							/* translators: 1: discount amount, 2: points count, 3: points label */
							esc_html__( 'Use %1$s %2$s for a %3$s discount', 'power-coupons' ),
							'<strong>' . esc_html( number_format_i18n( $full_points ) ) . '</strong>',
							esc_html( $label ),
							'<strong>' . wp_kses_post( wc_price( $full_discount ) ) . '</strong>'
						);
						?>
					</span>
					<button
						type="button"
						class="woocommerce-Button button wp-element-button power-coupons-apply-credit-btn"
						data-points="<?php echo esc_attr( (string) $full_points ); ?>"
					>
						<?php esc_html_e( 'Apply', 'power-coupons' ); ?>
					</button>
				</div>
			</div>
		<?php else : ?>
			<?php
			$input_max = $balance;
			if ( $max_credits_per_order > 0 ) {
				$input_max = min( $balance, $max_credits_per_order );
			}
			?>
			<div class="power-coupons-points-credit-form">
				<div class="power-coupons-points-credit-input-row">
					<input
						type="text"
						inputmode="numeric"
						pattern="[0-9]*"
						class="power-coupons-points-credit-input"
						id="power-coupons-points-credit-input"
						data-min="1"
						data-max="<?php echo esc_attr( (string) $input_max ); ?>"
						placeholder="<?php esc_attr_e( 'Enter credits', 'power-coupons' ); ?>"
						aria-label="<?php esc_attr_e( 'Credits to apply', 'power-coupons' ); ?>"
					/>
					<button
						type="button"
						class="woocommerce-Button button wp-element-button power-coupons-apply-credit-btn"
					>
						<?php esc_html_e( 'Apply', 'power-coupons' ); ?>
					</button>
				</div>
				<p class="power-coupons-points-credit-preview"></p>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<div class="power-coupons-points-redeem-notice" style="display:none;"></div>
</div>
