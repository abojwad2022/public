<?php
/**
 * My Account → Rewards hub template.
 *
 * Overridable via the `yazan_rewards/account_hub_template` filter. Rendered
 * server-side so it works without JS; `assets/js/account.js` enhances the redeem
 * buttons. All dynamic values are escaped at output.
 *
 * @var array $data Supplied by {@see \Yazan\Rewards\Frontend\AccountHub::render()}.
 * @package Yazan\Rewards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$balance     = (int) ( $data['balance'] ?? 0 );
$label       = 1 === $balance ? ( $data['label_singular'] ?? 'Point' ) : ( $data['label_plural'] ?? 'Points' );
$wallet_on   = ! empty( $data['wallet_enabled'] );
$rewards     = $data['rewards'] ?? array();
$history     = $data['history']['items'] ?? array();
?>
<div class="yzrw-hub">

	<div class="yzrw-summary">
		<div class="yzrw-summary__card yzrw-summary__card--points">
			<span class="yzrw-summary__label"><?php echo esc_html__( 'Your balance', 'yazan-rewards' ); ?></span>
			<span class="yzrw-summary__value" data-yzrw-balance><?php echo esc_html( number_format_i18n( $balance ) ); ?></span>
			<span class="yzrw-summary__unit"><?php echo esc_html( $label ); ?></span>
		</div>
		<?php if ( $wallet_on ) : ?>
			<div class="yzrw-summary__card yzrw-summary__card--credit">
				<span class="yzrw-summary__label"><?php echo esc_html__( 'Store credit', 'yazan-rewards' ); ?></span>
				<span class="yzrw-summary__value">
					<?php echo wp_kses_post( (string) ( $data['wallet_html'] ?? '' ) ); ?>
				</span>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( ! empty( $data['ambassador_url'] ) ) : ?>
		<p class="yzrw-hub__crosslink">
			<a href="<?php echo esc_url( $data['ambassador_url'] ); ?>">
				<?php echo esc_html__( 'View your membership dashboard →', 'yazan-rewards' ); ?>
			</a>
		</p>
	<?php endif; ?>

	<h3 class="yzrw-heading"><?php echo esc_html__( 'Redeem your points', 'yazan-rewards' ); ?></h3>

	<?php if ( empty( $rewards ) ) : ?>
		<p class="yzrw-empty"><?php echo esc_html__( 'No rewards are available right now. Check back soon.', 'yazan-rewards' ); ?></p>
	<?php else : ?>
		<ul class="yzrw-catalog">
			<?php
			foreach ( $rewards as $reward ) :
				$cost        = (int) $reward->cost_points;
				$affordable  = $balance >= $cost;
				$in_stock    = $reward->is_available();
				$can_redeem  = $affordable && $in_stock;
				?>
				<li class="yzrw-reward<?php echo $can_redeem ? '' : ' is-disabled'; ?>">
					<div class="yzrw-reward__body">
						<span class="yzrw-reward__title"><?php echo esc_html( $reward->title ); ?></span>
						<span class="yzrw-reward__desc"><?php echo esc_html( $reward->description ); ?></span>
					</div>
					<div class="yzrw-reward__action">
						<span class="yzrw-reward__cost">
							<?php
							/* translators: %s: number of points. */
							echo esc_html( sprintf( __( '%s pts', 'yazan-rewards' ), number_format_i18n( $cost ) ) );
							?>
						</span>
						<button
							type="button"
							class="yzrw-redeem button"
							data-reward-id="<?php echo esc_attr( (string) $reward->id ); ?>"
							data-cost="<?php echo esc_attr( (string) $cost ); ?>"
							<?php disabled( ! $can_redeem ); ?>
						>
							<?php echo $can_redeem ? esc_html__( 'Redeem', 'yazan-rewards' ) : esc_html__( 'Not enough points', 'yazan-rewards' ); ?>
						</button>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<div class="yzrw-result" data-yzrw-result hidden></div>

	<?php $campaigns = $data['campaigns'] ?? array(); ?>
	<?php if ( ! empty( $campaigns ) ) : ?>
		<h3 class="yzrw-heading"><?php echo esc_html__( 'Campaigns', 'yazan-rewards' ); ?></h3>
		<ul class="yzrw-campaigns">
			<?php foreach ( $campaigns as $cmp ) : ?>
				<li class="yzrw-campaign" data-campaign="<?php echo esc_attr( (string) $cmp['id'] ); ?>">
					<div class="yzrw-campaign__head">
						<span class="yzrw-campaign__title"><?php echo esc_html( $cmp['title'] ); ?></span>
						<?php if ( ! empty( $cmp['ends_at'] ) ) : ?>
							<span class="yzrw-campaign__ends"><?php echo esc_html( sprintf( /* translators: %s: date. */ __( 'Ends %s', 'yazan-rewards' ), mysql2date( get_option( 'date_format' ), (string) $cmp['ends_at'] ) ) ); ?></span>
						<?php endif; ?>
					</div>
					<?php if ( '' !== (string) $cmp['description'] ) : ?>
						<p class="yzrw-campaign__desc"><?php echo esc_html( $cmp['description'] ); ?></p>
					<?php endif; ?>
					<ul class="yzrw-campaign__tasks">
						<?php foreach ( $cmp['tasks'] as $task ) : ?>
							<li class="yzrw-task">
								<span class="yzrw-task__name"><?php echo esc_html( $task['name'] ); ?></span>
								<span class="yzrw-task__pts"><?php echo esc_html( sprintf( __( '+%s pts', 'yazan-rewards' ), number_format_i18n( (int) $task['points'] ) ) ); ?></span>
								<?php if ( 'approved' === $task['my_status'] ) : ?>
									<span class="yzrw-task__done"><?php echo esc_html__( 'Approved', 'yazan-rewards' ); ?></span>
								<?php elseif ( 'pending' === $task['my_status'] ) : ?>
									<span class="yzrw-task__pending"><?php echo esc_html__( 'In review', 'yazan-rewards' ); ?></span>
								<?php else : ?>
									<span class="yzrw-task__submit">
										<input type="url" class="yzrw-task-url" placeholder="<?php echo esc_attr__( 'Content URL', 'yazan-rewards' ); ?>" />
										<button type="button" class="yzrw-task-go button" data-task="<?php echo esc_attr( (string) $task['id'] ); ?>"><?php echo esc_html__( 'Submit', 'yazan-rewards' ); ?></button>
									</span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<h3 class="yzrw-heading"><?php echo esc_html__( 'Notifications', 'yazan-rewards' ); ?></h3>
	<div id="yzrw-notifications" class="yzrw-notif" data-yzrw-notifications>
		<noscript><?php echo esc_html__( 'Enable JavaScript to view and manage your notifications.', 'yazan-rewards' ); ?></noscript>
	</div>

	<h3 class="yzrw-heading"><?php echo esc_html__( 'Recent activity', 'yazan-rewards' ); ?></h3>
	<?php if ( empty( $history ) ) : ?>
		<p class="yzrw-empty"><?php echo esc_html__( 'No activity yet — start earning with your next order.', 'yazan-rewards' ); ?></p>
	<?php else : ?>
		<table class="yzrw-history">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Date', 'yazan-rewards' ); ?></th>
					<th><?php echo esc_html__( 'Activity', 'yazan-rewards' ); ?></th>
					<th class="yzrw-history__amount"><?php echo esc_html__( 'Points', 'yazan-rewards' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $history as $row ) : ?>
					<tr>
						<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), (string) $row->created_at ) ); ?></td>
						<td><?php echo esc_html( '' !== $row->note ? $row->note : ucfirst( (string) $row->source ) ); ?></td>
						<td class="yzrw-history__amount <?php echo ( (int) $row->points ) >= 0 ? 'is-positive' : 'is-negative'; ?>">
							<?php echo esc_html( ( (int) $row->points > 0 ? '+' : '' ) . number_format_i18n( (int) $row->points ) ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

</div>
