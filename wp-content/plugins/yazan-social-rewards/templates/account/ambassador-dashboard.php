<?php
/**
 * My Account → Ambassador dashboard template.
 *
 * Overridable via `yazan_rewards/ambassador_dashboard_template`. Read-only member
 * overview. All dynamic values are escaped at output.
 *
 * @var array $data Supplied by {@see \Yazan\Rewards\Frontend\AmbassadorDashboard::render()}.
 * @package Yazan\Rewards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$level      = $data['level']['current'] ?? array();
$next       = $data['level']['next'] ?? null;
$ranking    = $data['ranking'] ?? array( 'rank' => 0, 'total' => 0 );
$level_name = $level['name'] ?? '';
// Empty string (levels off / no tier) must also fall back, not just a missing key.
$level_color = ! empty( $level['color'] ) ? $level['color'] : '#141210';
$campaigns  = $data['campaigns'] ?? array( 'available' => array(), 'completed' => array() );
$ambassador = $data['ambassador'] ?? null;
?>
<div class="yzrw-dash">

	<?php if ( ! empty( $data['rewards_url'] ) ) : ?>
		<p class="yzrw-dash__crosslink">
			<a href="<?php echo esc_url( $data['rewards_url'] ); ?>"><?php echo esc_html__( '← Rewards &amp; redemption', 'yazan-rewards' ); ?></a>
		</p>
	<?php endif; ?>

	<!-- Header: level + points + ranking -->
	<div class="yzrw-dash__header">
		<div class="yzrw-dash__level">
			<span class="yzrw-level-badge" style="--yzrw-level:<?php echo esc_attr( $level_color ); ?>">
				<?php echo esc_html( '' !== $level_name ? $level_name : __( 'Member', 'yazan-rewards' ) ); ?>
			</span>
			<?php if ( ! empty( $level['multiplier'] ) && (float) $level['multiplier'] > 1 ) : ?>
				<span class="yzrw-level-mult">
					<?php
					/* translators: %s: points multiplier, e.g. "1.25". */
					echo esc_html( sprintf( __( '%s× points', 'yazan-rewards' ), rtrim( rtrim( number_format( (float) $level['multiplier'], 2 ), '0' ), '.' ) ) );
					?>
				</span>
			<?php endif; ?>
			<?php if ( ! empty( $level['discount_percent'] ) && (float) $level['discount_percent'] > 0 ) : ?>
				<span class="yzrw-level-disc">
					<?php
					/* translators: %s: discount percentage, e.g. "10". */
					echo esc_html( sprintf( __( '%s%% member discount', 'yazan-rewards' ), rtrim( rtrim( number_format( (float) $level['discount_percent'], 2 ), '0' ), '.' ) ) );
					?>
				</span>
			<?php endif; ?>
		</div>
		<div class="yzrw-dash__stats">
			<div class="yzrw-stat"><span class="yzrw-stat__n"><?php echo esc_html( number_format_i18n( (int) $data['points_balance'] ) ); ?></span><span class="yzrw-stat__l"><?php echo esc_html__( 'Current points', 'yazan-rewards' ); ?></span></div>
			<div class="yzrw-stat"><span class="yzrw-stat__n"><?php echo esc_html( number_format_i18n( (int) $data['lifetime'] ) ); ?></span><span class="yzrw-stat__l"><?php echo esc_html__( 'Lifetime points', 'yazan-rewards' ); ?></span></div>
			<?php if ( ! empty( $ranking['rank'] ) ) : ?>
				<div class="yzrw-stat"><span class="yzrw-stat__n">#<?php echo esc_html( number_format_i18n( (int) $ranking['rank'] ) ); ?></span><span class="yzrw-stat__l">
					<?php
					/* translators: %s: total number of ranked members. */
					echo esc_html( sprintf( __( 'of %s members', 'yazan-rewards' ), number_format_i18n( (int) $ranking['total'] ) ) );
					?>
				</span></div>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( $next ) : ?>
		<div class="yzrw-progress">
			<div class="yzrw-progress__bar"><span style="width:<?php echo esc_attr( (string) round( (float) $next['progress'] * 100 ) ); ?>%"></span></div>
			<span class="yzrw-progress__label">
				<?php echo esc_html( sprintf( /* translators: 1: points, 2: next level. */ __( '%1$s points to %2$s', 'yazan-rewards' ), number_format_i18n( (int) $next['remaining'] ), (string) $next['name'] ) ); ?>
			</span>
		</div>
	<?php endif; ?>

	<!-- Membership levels ladder -->
	<?php if ( ! empty( $data['ladder'] ) ) : ?>
		<h3 class="yzrw-dash__h"><?php echo esc_html__( 'Membership levels', 'yazan-rewards' ); ?></h3>
		<ul class="yzrw-levels">
			<?php foreach ( $data['ladder'] as $lvl ) : ?>
				<li class="yzrw-lvl<?php echo ! empty( $lvl['is_current'] ) ? ' is-current' : ''; ?>" style="--yzrw-level:<?php echo esc_attr( $lvl['color'] ); ?>">
					<span class="yzrw-lvl__name"><?php echo esc_html( $lvl['name'] ); ?></span>
					<span class="yzrw-lvl__req">
						<?php
						/* translators: %s: points required to reach this level. */
						echo esc_html( sprintf( __( '%s pts', 'yazan-rewards' ), number_format_i18n( (int) $lvl['threshold_points'] ) ) );
						?>
					</span>
					<span class="yzrw-lvl__perk">
						<?php echo esc_html( rtrim( rtrim( number_format( (float) $lvl['multiplier'], 2 ), '0' ), '.' ) . '× · ' . rtrim( rtrim( number_format( (float) $lvl['discount_percent'], 2 ), '0' ), '.' ) . '%' ); ?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<!-- Campaigns -->
	<?php if ( ! empty( $campaigns['available'] ) || ! empty( $campaigns['completed'] ) ) : ?>
		<h3 class="yzrw-dash__h"><?php echo esc_html__( 'Campaigns', 'yazan-rewards' ); ?></h3>
		<?php if ( ! empty( $campaigns['available'] ) ) : ?>
			<p class="yzrw-sub"><?php echo esc_html__( 'Available', 'yazan-rewards' ); ?></p>
			<ul class="yzrw-list">
				<?php foreach ( $campaigns['available'] as $cmp ) : ?>
					<li>
						<span><?php echo esc_html( $cmp['title'] ); ?></span>
						<span class="yzrw-muted">
							<?php
							/* translators: %d: number of tasks in the campaign. */
							echo esc_html( sprintf( _n( '%d task', '%d tasks', (int) $cmp['tasks'], 'yazan-rewards' ), (int) $cmp['tasks'] ) );
							?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( ! empty( $data['rewards_url'] ) ) : ?>
				<p><a class="button" href="<?php echo esc_url( $data['rewards_url'] ); ?>"><?php echo esc_html__( 'Participate in Rewards', 'yazan-rewards' ); ?></a></p>
			<?php endif; ?>
		<?php endif; ?>
		<?php if ( ! empty( $campaigns['completed'] ) ) : ?>
			<p class="yzrw-sub"><?php echo esc_html__( 'Completed', 'yazan-rewards' ); ?></p>
			<ul class="yzrw-list">
				<?php foreach ( $campaigns['completed'] as $cmp ) : ?>
					<li><span><?php echo esc_html( $cmp['title'] ); ?></span><span class="yzrw-muted">
						<?php
						/* translators: %s: points earned from the completed campaign. */
						echo esc_html( sprintf( __( '+%s pts', 'yazan-rewards' ), number_format_i18n( (int) $cmp['points'] ) ) );
						?>
					</span></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	<?php endif; ?>

	<!-- Achievements -->
	<?php if ( ! empty( $data['achievements'] ) ) : ?>
		<h3 class="yzrw-dash__h"><?php echo esc_html__( 'Achievements', 'yazan-rewards' ); ?></h3>
		<ul class="yzrw-badges">
			<?php foreach ( $data['achievements'] as $a ) : ?>
				<li class="yzrw-badge<?php echo empty( $a['unlocked'] ) ? ' is-locked' : ''; ?>" title="<?php echo esc_attr( $a['description'] ); ?>">
					<span class="yzrw-badge__name"><?php echo esc_html( $a['name'] ); ?></span>
					<span class="yzrw-badge__state"><?php echo esc_html( empty( $a['unlocked'] ) ? __( 'Locked', 'yazan-rewards' ) : __( 'Unlocked', 'yazan-rewards' ) ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<!-- Rewards preview -->
	<?php if ( ! empty( $data['rewards'] ) ) : ?>
		<h3 class="yzrw-dash__h"><?php echo esc_html__( 'Rewards', 'yazan-rewards' ); ?></h3>
		<ul class="yzrw-list">
			<?php foreach ( $data['rewards'] as $r ) : ?>
				<li><span><?php echo esc_html( $r['title'] ); ?></span><span class="yzrw-muted">
					<?php
					/* translators: %s: points cost of the reward. */
					echo esc_html( sprintf( __( '%s pts', 'yazan-rewards' ), number_format_i18n( (int) $r['cost_points'] ) ) );
					?>
				</span></li>
			<?php endforeach; ?>
		</ul>
		<?php if ( ! empty( $data['rewards_url'] ) ) : ?>
			<p><a class="button" href="<?php echo esc_url( $data['rewards_url'] ); ?>"><?php echo esc_html__( 'Browse & redeem rewards', 'yazan-rewards' ); ?></a></p>
		<?php endif; ?>
	<?php endif; ?>

	<!-- Referral -->
	<?php if ( ! empty( $data['referral'] ) ) : $ref = $data['referral']; ?>
		<h3 class="yzrw-dash__h"><?php echo esc_html__( 'Referrals', 'yazan-rewards' ); ?></h3>
		<div class="yzrw-referral">
			<div class="yzrw-referral__link">
				<input type="text" readonly value="<?php echo esc_attr( $ref['link'] ); ?>" data-yzrw-reflink />
				<button type="button" class="button" data-yzrw-copy><?php echo esc_html__( 'Copy link', 'yazan-rewards' ); ?></button>
			</div>
			<div class="yzrw-referral__stats">
				<?php /* translators: %s: number of people who signed up via the referral link. */ ?>
				<span><?php echo esc_html( sprintf( __( 'Signups: %s', 'yazan-rewards' ), number_format_i18n( (int) ( $ref['stats']['signups'] ?? 0 ) ) ) ); ?></span>
				<?php /* translators: %s: number of referrals that converted to a purchase. */ ?>
				<span><?php echo esc_html( sprintf( __( 'Converted: %s', 'yazan-rewards' ), number_format_i18n( (int) ( $ref['stats']['conversions'] ?? 0 ) ) ) ); ?></span>
				<?php /* translators: %s: total points earned from referrals. */ ?>
				<span><?php echo esc_html( sprintf( __( 'Points earned: %s', 'yazan-rewards' ), number_format_i18n( (int) ( $ref['stats']['points_earned'] ?? 0 ) ) ) ); ?></span>
				<?php if ( isset( $ref['stats']['revenue'] ) && function_exists( 'wc_price' ) ) : ?>
					<?php /* translators: %s: revenue generated by referred customers, formatted as a price. */ ?>
					<span><?php echo esc_html( sprintf( __( 'Revenue generated: %s', 'yazan-rewards' ), wp_strip_all_tags( wc_price( (float) $ref['stats']['revenue'] ) ) ) ); ?></span>
				<?php endif; ?>
				<?php if ( ! empty( $ref['tree'] ) ) : ?>
					<?php
					$l1 = (int) ( $ref['tree'][1] ?? 0 );
					$l2 = (int) ( $ref['tree'][2] ?? 0 );
					/* translators: 1: number of direct referrals, 2: number of second-level referrals. */
					echo '<span>' . esc_html( sprintf( __( 'Network: %1$s direct · %2$s indirect', 'yazan-rewards' ), number_format_i18n( $l1 ), number_format_i18n( $l2 ) ) ) . '</span>';
					?>
				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>

	<!-- Ambassador panel -->
	<?php if ( null !== $ambassador ) : ?>
		<h3 class="yzrw-dash__h"><?php echo esc_html__( 'Ambassador', 'yazan-rewards' ); ?></h3>
		<?php if ( empty( $ambassador['is_ambassador'] ) ) : ?>
			<div class="yzrw-ambassador">
				<p><?php echo esc_html__( 'Become a Yazan ambassador and earn commission on customers you refer.', 'yazan-rewards' ); ?></p>
				<button type="button" class="button button-primary" data-yzrw-apply><?php echo esc_html__( 'Apply to become an ambassador', 'yazan-rewards' ); ?></button>
				<span class="yzrw-apply-msg" data-yzrw-apply-msg></span>
			</div>
		<?php
		else :
			$status_labels = array(
				'pending'   => __( 'Pending', 'yazan-rewards' ),
				'active'    => __( 'Active', 'yazan-rewards' ),
				'suspended' => __( 'Suspended', 'yazan-rewards' ),
			);
			$status_label  = $status_labels[ $ambassador['status'] ] ?? ucfirst( (string) $ambassador['status'] );
			?>
			<div class="yzrw-ambassador">
				<p class="yzrw-ambassador__status">
					<?php
					/* translators: %s: ambassador application status (Pending/Active/Suspended). */
					echo esc_html( sprintf( __( 'Status: %s', 'yazan-rewards' ), $status_label ) );
					?>
				</p>
				<?php if ( 'active' === $ambassador['status'] ) : ?>
					<div class="yzrw-referral__link">
						<input type="text" readonly value="<?php echo esc_attr( (string) $ambassador['link'] ); ?>" data-yzrw-reflink />
						<button type="button" class="button" data-yzrw-copy><?php echo esc_html__( 'Copy link', 'yazan-rewards' ); ?></button>
					</div>
					<div class="yzrw-referral__stats">
						<?php /* translators: %s: the ambassador's commission rate percentage. */ ?>
						<span><?php echo esc_html( sprintf( __( 'Commission rate: %s%%', 'yazan-rewards' ), rtrim( rtrim( number_format( (float) $ambassador['commission_rate'], 2 ), '0' ), '.' ) ) ); ?></span>
						<?php /* translators: %s: number of attributed orders. */ ?>
						<span><?php echo esc_html( sprintf( __( 'Orders: %s', 'yazan-rewards' ), number_format_i18n( (int) ( $ambassador['stats']['orders'] ?? 0 ) ) ) ); ?></span>
						<?php /* translators: %s: total commission earned, formatted as a price. */ ?>
						<span><?php echo esc_html( sprintf( __( 'Commission earned: %s', 'yazan-rewards' ), function_exists( 'wc_price' ) ? wp_strip_all_tags( wc_price( (float) ( $ambassador['stats']['commission_total'] ?? 0 ) ) ) : (string) ( $ambassador['stats']['commission_total'] ?? 0 ) ) ); ?></span>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<!-- Activity -->
	<?php if ( ! empty( $data['activity'] ) ) : ?>
		<h3 class="yzrw-dash__h"><?php echo esc_html__( 'Recent activity', 'yazan-rewards' ); ?></h3>
		<table class="yzrw-history">
			<tbody>
				<?php foreach ( $data['activity'] as $row ) : ?>
					<tr>
						<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), (string) $row->created_at ) ); ?></td>
						<td><?php echo esc_html( (string) $row->description ); ?></td>
						<td class="yzrw-history__amount"><?php echo esc_html( ( (int) $row->points > 0 ? '+' : '' ) . number_format_i18n( (int) $row->points ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

</div>
