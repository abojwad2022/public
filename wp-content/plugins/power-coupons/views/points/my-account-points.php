<?php
/**
 * My Account — Loyalty Rewards tab.
 *
 * @package Power_Coupons
 * @var int    $balance       Current balance.
 * @var int    $earned_total  Lifetime earned.
 * @var int    $redeemed_total Lifetime redeemed.
 * @var int    $pending       Pending points.
 * @var string $label         Points label (plural).
 * @var list<object{id: int, user_id: int, points: int, balance_after: int, action: string, action_id: int, note: string, created_at: string, expires_at: ?string, reference: array{url: string, label: string}|null}> $history Recent ledger entries.
 * @var int    $total_entries  Total history entries.
 * @var int    $current_page   Current page number.
 * @var int    $per_page       Items per page.
 * @var bool   $expiry_enabled Whether points expiry is enabled.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$action_labels = array(
	'order_earn'      => __( 'Order Earning', 'power-coupons' ),
	'order_complete'  => __( 'Order Earning', 'power-coupons' ),
	'order_pending'   => __( 'Order Earning (Pending)', 'power-coupons' ),
	'redeem'          => __( 'Redemption', 'power-coupons' ),
	'admin_adjust'    => __( 'Admin Adjustment', 'power-coupons' ),
	'signup'          => __( 'Signup Bonus', 'power-coupons' ),
	'review'          => __( 'Product Review', 'power-coupons' ),
	'expiry'          => __( 'Expired', 'power-coupons' ),
	'cancel_reversal' => __( 'Cancelled', 'power-coupons' ),
	'order_refund'    => __( 'Refund', 'power-coupons' ),
);
?>

<div class="power-coupons-my-account-points">

	<div class="power-coupons-points-redeem-notice" style="display:none;"></div>

	<?php /* Points summary cards */ ?>
	<div class="power-coupons-points-summary">
		<div class="power-coupons-points-summary-card">
			<span class="power-coupons-points-summary-value"><?php echo esc_html( number_format_i18n( $balance ) ); ?></span>
			<span class="power-coupons-points-summary-label"><?php esc_html_e( 'Available', 'power-coupons' ); ?></span>
		</div>
		<div class="power-coupons-points-summary-card">
			<span class="power-coupons-points-summary-value"><?php echo esc_html( number_format_i18n( $earned_total ) ); ?></span>
			<span class="power-coupons-points-summary-label"><?php esc_html_e( 'Total Earned', 'power-coupons' ); ?></span>
		</div>
		<div class="power-coupons-points-summary-card">
			<span class="power-coupons-points-summary-value"><?php echo esc_html( number_format_i18n( $redeemed_total ) ); ?></span>
			<span class="power-coupons-points-summary-label"><?php esc_html_e( 'Total Redeemed', 'power-coupons' ); ?></span>
		</div>
		<div class="power-coupons-points-summary-card">
			<span class="power-coupons-points-summary-value"><?php echo esc_html( number_format_i18n( $pending ) ); ?></span>
			<span class="power-coupons-points-summary-label" title="<?php esc_attr_e( 'Pending credits will be added to your balance once your order is completed.', 'power-coupons' ); ?>">
				<?php esc_html_e( 'Pending', 'power-coupons' ); ?>
				<?php if ( $pending > 0 ) : ?>
					<span class="power-coupons-points-pending-icon" aria-hidden="true">&#9432;</span>
				<?php endif; ?>
			</span>
		</div>
	</div>

	<?php /* Points history table */ ?>
	<div class="power-coupons-points-history">
		<h3><?php esc_html_e( 'Credits History', 'power-coupons' ); ?></h3>

		<?php if ( empty( $history ) ) : ?>
			<p><?php esc_html_e( 'No credits activity yet.', 'power-coupons' ); ?></p>
		<?php else : ?>
			<table class="shop_table power-coupons-points-history-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'power-coupons' ); ?></th>
						<th><?php esc_html_e( 'Action', 'power-coupons' ); ?></th>
						<th><?php esc_html_e( 'Credits', 'power-coupons' ); ?></th>
						<th><?php esc_html_e( 'Balance', 'power-coupons' ); ?></th>
						<?php if ( ! empty( $expiry_enabled ) ) : ?>
							<th><?php esc_html_e( 'Expires', 'power-coupons' ); ?></th>
						<?php endif; ?>
						<th><?php esc_html_e( 'Note', 'power-coupons' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $history as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $entry->created_at ) ) ); ?></td>
							<td><?php echo esc_html( $action_labels[ $entry->action ] ?? $entry->action ); ?></td>
							<td class="<?php echo $entry->points >= 0 ? 'power-coupons-points-positive' : 'power-coupons-points-negative'; ?>">
								<?php echo $entry->points >= 0 ? '+' : ''; ?><?php echo esc_html( number_format_i18n( $entry->points ) ); ?>
							</td>
							<td><?php echo esc_html( number_format_i18n( $entry->balance_after ) ); ?></td>
							<?php if ( ! empty( $expiry_enabled ) ) : ?>
								<td>
									<?php if ( $entry->points > 0 && ! empty( $entry->expires_at ) ) : ?>
										<?php
										$date_fmt = get_option( 'date_format' );
										echo esc_html( date_i18n( is_string( $date_fmt ) ? $date_fmt : 'Y-m-d', strtotime( $entry->expires_at ) ) );
										?>
									<?php else : ?>
										&mdash;
									<?php endif; ?>
								</td>
							<?php endif; ?>
							<td>
								<?php if ( ! empty( $entry->note ) ) : ?>
									<?php echo esc_html( html_entity_decode( $entry->note ) ); ?>
								<?php endif; ?>
								<?php if ( ! empty( $entry->reference ) ) : ?>
									<?php if ( ! empty( $entry->note ) ) : ?>
										<br>
									<?php endif; ?>
									<a href="<?php echo esc_url( $entry->reference['url'] ); ?>">
										<?php echo esc_html( $entry->reference['label'] ); ?>
									</a>
								<?php elseif ( empty( $entry->note ) ) : ?>
									&mdash;
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php /* Pagination */ ?>
			<?php
			$total_pages = (int) ceil( $total_entries / $per_page );
			if ( $total_pages > 1 ) :
				?>
				<div class="power-coupons-points-pagination">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => esc_url_raw( add_query_arg( 'points-page', '%#%' ) ),
								'format'    => '',
								'current'   => $current_page,
								'total'     => $total_pages,
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
							)
						)
					);
					?>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	</div>

</div>
