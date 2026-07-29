<?php
/**
 * Coupon List Template
 *
 * @package Power_Coupons
 * @since 1.0.0
 */

use Power_Coupons\Includes\Power_Coupons_Utilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable Generic.Commenting.DocComment.MissingShort -- PHPStan type hints for template variables.
// Variables provided by Display_Controller::render_coupon_list().
/** @var string $context */
/** @var array<int, array<string, mixed>> $all_coupons */
/** @var array<string, mixed> $text_settings */
/** @var array<string, mixed> $general_settings */
/** @var array<string, mixed> $coupon_styling_settings */
// phpcs:enable Generic.Commenting.DocComment.MissingShort

if ( empty( $all_coupons ) ) {
	return;
}

$context = ! empty( $context ) ? $context : '';

$power_coupons_card_style_value   = ! empty( $coupon_styling_settings['coupon_style'] ) && 'style-2' === $coupon_styling_settings['coupon_style'] ? 'style-2' : 'style-1'; // TODO: Enhancement from later.
$power_coupons_expiry_text_format = isset( $text_settings['expiry_text_format'] ) && is_string( $text_settings['expiry_text_format'] ) ? $text_settings['expiry_text_format'] : __( 'Expires: {date}', 'power-coupons' );
$power_coupons_coupon_card_array  = Power_Coupons_Utilities::get_coupon_card_templates_array( false, $power_coupons_card_style_value );

// Ensure context is defined.
$context = ! empty( $context ) ? $context : 'default';

?>

<div class="power-coupons-list" data-coupon-card-style="<?php echo esc_attr( $power_coupons_card_style_value ); ?>" data-context="<?php echo esc_attr( $context ); ?>" aria-live="polite" aria-atomic="false">
	<div class="power-coupons-section" role="region" aria-label="<?php esc_attr_e( 'Available Coupons', 'power-coupons' ); ?>">
		<?php
		foreach ( $all_coupons as $power_coupon_coupon ) {

			// Extract typed variables from mixed coupon data.
			$coupon_code      = isset( $power_coupon_coupon['code'] ) && is_string( $power_coupon_coupon['code'] ) ? $power_coupon_coupon['code'] : '';
			$coupon_amount    = isset( $power_coupon_coupon['amount'] ) && is_numeric( $power_coupon_coupon['amount'] ) ? (float) $power_coupon_coupon['amount'] : 0.0;
			$coupon_type      = isset( $power_coupon_coupon['type'] ) && is_string( $power_coupon_coupon['type'] ) ? $power_coupon_coupon['type'] : '';
			$coupon_type_text = isset( $power_coupon_coupon['type_text'] ) && is_string( $power_coupon_coupon['type_text'] ) ? $power_coupon_coupon['type_text'] : '';
			$coupon_expiry    = $power_coupon_coupon['expiry_date'] ?? '';

			$power_coupon_coupon_status = 'active';

			if ( ! empty( $power_coupon_coupon['is_applied'] ) ) {
				$power_coupon_coupon_status = 'applied';
			}

			$power_coupons_coupon_card_array['tags'] = [
				'{power_coupon.code}'        => $coupon_code,
				'{power_coupon.discount}'    => 'percent' === $coupon_type ? $coupon_amount . '%' : Power_Coupons_Utilities::get_formatted_price( $coupon_amount ),
				'{power_coupon.description}' => $coupon_type_text,
			];

			if ( 'active' === $power_coupon_coupon_status ) {
				if ( empty( $general_settings['show_expiry_info'] ) ) {
					$power_coupons_coupon_card_array['tags']['{power_coupon.status}'] = '';
				} else {
					if ( is_object( $coupon_expiry ) && is_a( $coupon_expiry, 'WC_DateTime' ) ) {
						$power_coupons_coupon_card_array['tags']['{power_coupon.status}'] = str_replace( '{date}', date_i18n( 'd M Y', $coupon_expiry->getTimestamp() ), $power_coupons_expiry_text_format );
					} else {
						$expiry_str = is_string( $coupon_expiry ) ? $coupon_expiry : '';
						$power_coupons_coupon_card_array['tags']['{power_coupon.status}'] = str_replace( '{date}', $expiry_str, $power_coupons_expiry_text_format );
					}
				}
			} else {
				$power_coupons_coupon_card_array['tags']['{power_coupon.status}'] = $text_settings['coupon_applied_text'] ?? esc_html__( 'Applied', 'power-coupons' );
			}

			// Generate accessible label for the button including description and status.
			$power_coupon_discount_text = 'percent' === $coupon_type ? $coupon_amount . '%' : Power_Coupons_Utilities::get_formatted_price( $coupon_amount );
			$power_coupon_status_text   = is_string( $power_coupons_coupon_card_array['tags']['{power_coupon.status}'] ) ? $power_coupons_coupon_card_array['tags']['{power_coupon.status}'] : '';
			$power_coupon_button_label  = 'active' === $power_coupon_coupon_status
				? sprintf(
					/* translators: 1: coupon code, 2: discount amount, 3: coupon description, 4: expiry or status text */
					esc_html__( 'Apply coupon %1$s for %2$s discount. %3$s. %4$s', 'power-coupons' ),
					esc_html( $coupon_code ),
					esc_html( $power_coupon_discount_text ),
					esc_html( $coupon_type_text ),
					esc_html( $power_coupon_status_text )
				)
				: sprintf(
					/* translators: 1: coupon code, 2: coupon description */
					esc_html__( 'Coupon %1$s is already applied. %2$s', 'power-coupons' ),
					esc_html( $coupon_code ),
					esc_html( $coupon_type_text )
				);

			printf(
				'<button
					type="button"
					class="power-coupons-apply-coupon-btn"
					data-coupon="%1$s"
					data-coupon-status="%2$s"
					aria-label="%4$s"
					aria-pressed="%5$s"
					%3$s
				>',
				esc_attr( $coupon_code ),
				esc_attr( $power_coupon_coupon_status ),
				disabled( 'active' !== $power_coupon_coupon_status, true, false ),
				esc_attr( $power_coupon_button_label ),
				'active' === $power_coupon_coupon_status ? 'false' : 'true'
			);
			Power_Coupons_Utilities::render_coupon_card_template( $power_coupons_coupon_card_array );
			echo '</button>';
		}

		?>
	</div>
</div>
