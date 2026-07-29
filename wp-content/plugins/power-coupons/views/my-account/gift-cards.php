<?php
/**
 * My Account — Gift Cards tab.
 *
 * @package Power_Coupons
 * @var array<int, array{code: string, masked_code: string, initial_amount: float, balance: float, status: string, expiry_date: string}> $received Array of received gift card data.
 * @var array<int, array{recipient_name: string, recipient_email: string, amount: float, masked_code: string, status: string, date: string}> $sent Array of sent gift card data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$status_labels = array(
	'active'    => __( 'Active', 'power-coupons' ),
	'expired'   => __( 'Expired', 'power-coupons' ),
	'redeemed'  => __( 'Fully Redeemed', 'power-coupons' ),
	'delivered' => __( 'Delivered', 'power-coupons' ),
	'partial'   => __( 'Partially Used', 'power-coupons' ),
);

$status_classes = array(
	'active'    => 'power-coupon-gc-status-active',
	'expired'   => 'power-coupon-gc-status-expired',
	'redeemed'  => 'power-coupon-gc-status-redeemed',
	'delivered' => 'power-coupon-gc-status-delivered',
	'partial'   => 'power-coupon-gc-status-partial',
);
?>

<div class="power-coupon-gc-my-account">

	<?php /* ── Section 1: Received Gift Cards ── */ ?>
	<div class="power-coupon-gc-section">
		<h3><?php esc_html_e( 'My Gift Cards', 'power-coupons' ); ?></h3>

		<?php if ( empty( $received ) ) : ?>
			<p class="power-coupon-gc-empty">
				<?php esc_html_e( "You haven't received any gift cards yet.", 'power-coupons' ); ?>
			</p>
		<?php else : ?>
			<table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive power-coupon-gc-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Code', 'power-coupons' ); ?></th>
						<th><?php esc_html_e( 'Original Amount', 'power-coupons' ); ?></th>
						<th><?php esc_html_e( 'Balance', 'power-coupons' ); ?></th>
						<th><?php esc_html_e( 'Status', 'power-coupons' ); ?></th>
						<th><?php esc_html_e( 'Expiry', 'power-coupons' ); ?></th>
						<th>&nbsp;</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $received as $gc ) : ?>
						<tr>
							<td data-title="<?php esc_attr_e( 'Code', 'power-coupons' ); ?>">
								<code class="power-coupon-gc-code" data-full-code="<?php echo esc_attr( $gc['code'] ); ?>">
									<?php echo esc_html( $gc['masked_code'] ); ?>
								</code>
							</td>
							<td data-title="<?php esc_attr_e( 'Original Amount', 'power-coupons' ); ?>">
								<?php echo wp_kses_post( wc_price( $gc['initial_amount'] ) ); ?>
							</td>
							<td data-title="<?php esc_attr_e( 'Balance', 'power-coupons' ); ?>">
								<strong><?php echo wp_kses_post( wc_price( $gc['balance'] ) ); ?></strong>
							</td>
							<td data-title="<?php esc_attr_e( 'Status', 'power-coupons' ); ?>">
								<span class="power-coupon-gc-status <?php echo esc_attr( $status_classes[ $gc['status'] ] ?? '' ); ?>">
									<?php echo esc_html( $status_labels[ $gc['status'] ] ?? $gc['status'] ); ?>
								</span>
							</td>
							<td data-title="<?php esc_attr_e( 'Expiry', 'power-coupons' ); ?>">
								<?php echo $gc['expiry_date'] ? esc_html( $gc['expiry_date'] ) : esc_html__( 'No expiry', 'power-coupons' ); ?>
							</td>
							<td>
								<?php if ( 'active' === $gc['status'] ) : ?>
									<button
										type="button"
										class="button wp-element-button power-coupon-gc-copy-btn"
										data-code="<?php echo esc_attr( $gc['code'] ); ?>"
									>
										<?php esc_html_e( 'Copy Code', 'power-coupons' ); ?>
									</button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<?php /* ── Section 2: Sent Gift Cards ── */ ?>
	<div class="power-coupon-gc-section">
		<h3><?php esc_html_e( 'Sent Gift Cards', 'power-coupons' ); ?></h3>

		<?php if ( empty( $sent ) ) : ?>
			<p class="power-coupon-gc-empty">
				<?php esc_html_e( "You haven't sent any gift cards yet.", 'power-coupons' ); ?>
			</p>
		<?php else : ?>
			<table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive power-coupon-gc-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Recipient', 'power-coupons' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'power-coupons' ); ?></th>
						<th><?php esc_html_e( 'Code', 'power-coupons' ); ?></th>
						<th><?php esc_html_e( 'Status', 'power-coupons' ); ?></th>
						<th><?php esc_html_e( 'Date', 'power-coupons' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $sent as $gc ) : ?>
						<tr>
							<td data-title="<?php esc_attr_e( 'Recipient', 'power-coupons' ); ?>">
								<?php echo esc_html( $gc['recipient_name'] ); ?>
								<br>
								<small><?php echo esc_html( $gc['recipient_email'] ); ?></small>
							</td>
							<td data-title="<?php esc_attr_e( 'Amount', 'power-coupons' ); ?>">
								<?php echo wp_kses_post( wc_price( $gc['amount'] ) ); ?>
							</td>
							<td data-title="<?php esc_attr_e( 'Code', 'power-coupons' ); ?>">
								<?php if ( $gc['masked_code'] ) : ?>
									<code><?php echo esc_html( $gc['masked_code'] ); ?></code>
								<?php else : ?>
									<em><?php esc_html_e( 'Pending', 'power-coupons' ); ?></em>
								<?php endif; ?>
							</td>
							<td data-title="<?php esc_attr_e( 'Status', 'power-coupons' ); ?>">
								<span class="power-coupon-gc-status <?php echo esc_attr( $status_classes[ $gc['status'] ] ?? '' ); ?>">
									<?php echo esc_html( $status_labels[ $gc['status'] ] ?? $gc['status'] ); ?>
								</span>
							</td>
							<td data-title="<?php esc_attr_e( 'Date', 'power-coupons' ); ?>">
								<?php echo esc_html( $gc['date'] ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

</div>

<script type="text/javascript">
( function() {
	'use strict';
	document.addEventListener( 'click', function( e ) {
		var btn = e.target.closest( '.power-coupon-gc-copy-btn' );
		if ( ! btn ) return;

		var code = btn.getAttribute( 'data-code' );
		if ( ! code ) return;

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( code ).then( function() {
				var original = btn.textContent;
				btn.textContent = '<?php echo esc_js( __( 'Copied!', 'power-coupons' ) ); ?>';
				setTimeout( function() { btn.textContent = original; }, 2000 );
			} );
		} else {
			// Fallback for older browsers.
			var textarea = document.createElement( 'textarea' );
			textarea.value = code;
			textarea.style.position = 'fixed';
			textarea.style.opacity = '0';
			document.body.appendChild( textarea );
			textarea.select();
			document.execCommand( 'copy' );
			document.body.removeChild( textarea );

			var original = btn.textContent;
			btn.textContent = '<?php echo esc_js( __( 'Copied!', 'power-coupons' ) ); ?>';
			setTimeout( function() { btn.textContent = original; }, 2000 );
		}
	} );
} )();
</script>
