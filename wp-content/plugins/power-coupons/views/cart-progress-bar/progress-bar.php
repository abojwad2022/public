<?php
/**
 * Cart Progress Bar Template
 * Displays progress bars for auto-discovered sources on cart/checkout pages.
 *
 * Available variables:
 *
 * @var array<int, array<string, mixed>> $progress_data Array of progress data for each enabled source.
 * @var array<string, mixed>             $settings      Bar styling settings.
 *
 * @package Power_Coupons
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $progress_data ) ) {
	return;
}

$bar_color     = esc_attr( is_string( $settings['bar_color'] ) ? $settings['bar_color'] : '' );
$bar_bg_color  = esc_attr( is_string( $settings['bar_bg_color'] ) ? $settings['bar_bg_color'] : '' );
$success_color = esc_attr( is_string( $settings['success_color'] ) ? $settings['success_color'] : '' );
$animate       = $settings['animate'];
?>
<div class="power-coupons-progress-container"
	role="region"
	aria-label="<?php esc_attr_e( 'Cart Progress', 'power-coupons' ); ?>"
	style="--pc-bar-color:<?php echo esc_attr( $bar_color ); ?>; --pc-bar-bg:<?php echo esc_attr( $bar_bg_color ); ?>; --pc-success-color:<?php echo esc_attr( $success_color ); ?>;">

	<?php foreach ( $progress_data as $source ) : ?>
		<?php
		$is_complete  = ! empty( $source['is_complete'] );
		$percentage   = is_numeric( $source['percentage'] ) ? floatval( $source['percentage'] ) : 0.0;
		$source_class = 'power-coupons-progress-goal';
		if ( $is_complete ) {
			$source_class .= ' complete';
		}
		if ( $animate ) {
			$source_class .= ' animate';
		}
		?>
		<div class="<?php echo esc_attr( $source_class ); ?>" data-source-key="<?php echo esc_attr( is_string( $source['key'] ) ? $source['key'] : '' ); ?>">

			<?php // Message row. ?>
			<div class="power-coupons-progress-message <?php echo $is_complete ? 'success' : ''; ?>">
				<span class="power-coupons-progress-icon">
					<?php if ( $is_complete ) : ?>
						<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
							<path d="M16.6668 5L7.50016 14.1667L3.3335 10" stroke="currentColor" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
					<?php elseif ( 'truck' === $source['icon'] ) : ?>
						<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
							<path d="M13.3332 2.5H1.6665V13.3333H13.3332V2.5Z" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
							<path d="M13.3335 6.66663H16.6668L18.3335 8.33329V13.3333H13.3335V6.66663Z" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
							<path d="M4.58317 17.5C5.73377 17.5 6.6665 16.5672 6.6665 15.4166C6.6665 14.266 5.73377 13.3333 4.58317 13.3333C3.43258 13.3333 2.49984 14.266 2.49984 15.4166C2.49984 16.5672 3.43258 17.5 4.58317 17.5Z" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
							<path d="M15.4168 17.5C16.5674 17.5 17.5002 16.5672 17.5002 15.4166C17.5002 14.266 16.5674 13.3333 15.4168 13.3333C14.2662 13.3333 13.3335 14.266 13.3335 15.4166C13.3335 16.5672 14.2662 17.5 15.4168 17.5Z" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
					<?php elseif ( 'gift' === $source['icon'] ) : ?>
						<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
							<path d="M16.6668 10V18.3333H3.3335V10" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
							<path d="M18.3332 5.83337H1.6665V10H18.3332V5.83337Z" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
							<path d="M10 18.3334V5.83337" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
							<path d="M10.0002 5.83333H6.25016C5.69763 5.83333 5.16772 5.61384 4.77702 5.22314C4.38632 4.83244 4.16683 4.30253 4.16683 3.75C4.16683 3.19746 4.38632 2.66756 4.77702 2.27686C5.16772 1.88616 5.69763 1.66666 6.25016 1.66666C9.16683 1.66666 10.0002 5.83333 10.0002 5.83333Z" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
							<path d="M10 5.83333H13.75C14.3025 5.83333 14.8325 5.61384 15.2232 5.22314C15.6139 4.83244 15.8333 4.30253 15.8333 3.75C15.8333 3.19746 15.6139 2.66756 15.2232 2.27686C14.8325 1.88616 14.3025 1.66666 13.75 1.66666C10.8333 1.66666 10 5.83333 10 5.83333Z" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
					<?php elseif ( 'percent' === $source['icon'] ) : ?>
						<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
							<path d="M16.6668 3.33337L3.3335 16.6667" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
							<path d="M5.41683 7.08337C6.33731 7.08337 7.0835 6.33718 7.0835 5.41671C7.0835 4.49623 6.33731 3.75004 5.41683 3.75004C4.49635 3.75004 3.75016 4.49623 3.75016 5.41671C3.75016 6.33718 4.49635 7.08337 5.41683 7.08337Z" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
							<path d="M14.5832 16.25C15.5037 16.25 16.2498 15.5038 16.2498 14.5834C16.2498 13.6629 15.5037 12.9167 14.5832 12.9167C13.6627 12.9167 12.9165 13.6629 12.9165 14.5834C12.9165 15.5038 13.6627 16.25 14.5832 16.25Z" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
					<?php else : ?>
						<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
							<path d="M17.1585 10.9917L10.9835 17.1667C10.8279 17.3225 10.6434 17.4463 10.4407 17.5311C10.238 17.6159 10.0209 17.6598 9.80184 17.6598C9.58275 17.6598 9.36572 17.6159 9.16298 17.5311C8.96025 17.4463 8.77578 17.3225 8.62017 17.1667L1.6665 10.2084V1.66669H10.2082L17.1585 8.62086C17.4713 8.93552 17.6467 9.36229 17.6467 9.80628C17.6467 10.2503 17.4713 10.677 17.1585 10.9917Z" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
							<path d="M5.8335 5.83337H5.84183" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
					<?php endif; ?>
				</span>
				<span class="power-coupons-progress-text">
					<?php echo wp_kses_post( is_string( $source['message'] ) ? $source['message'] : '' ); ?>
				</span>
			</div>

			<?php // Bar track. ?>
			<div class="power-coupons-progress-track"
				role="progressbar"
				aria-valuenow="<?php echo esc_attr( (string) round( $percentage ) ); ?>"
				aria-valuemin="0"
				aria-valuemax="100">
				<div class="power-coupons-progress-fill <?php echo $is_complete ? 'complete' : ''; ?>"
					style="width: <?php echo esc_attr( (string) $percentage ); ?>%;"></div>
			</div>

		</div>
	<?php endforeach; ?>

</div>
