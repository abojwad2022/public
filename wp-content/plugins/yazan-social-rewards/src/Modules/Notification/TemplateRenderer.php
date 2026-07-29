<?php
/**
 * Notification template renderer.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Notification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a template key + payload into a subject/body. Kept simple and filterable
 * so copy can be customised without editing the plugin.
 */
final class TemplateRenderer {

	/**
	 * Render a message.
	 *
	 * @param string $template Template key.
	 * @param array  $payload  Event payload.
	 * @return array{subject:string,body:string,template:string,payload:array}
	 */
	public function render( string $template, array $payload ): array {
		$blog = get_bloginfo( 'name' );

		switch ( $template ) {
			case 'reward_redeemed':
				$spent   = (int) ( $payload['points_spent'] ?? 0 );
				$subject = __( 'Your reward is ready', 'yazan-rewards' );
				$body    = $spent > 0
					? sprintf(
						/* translators: %d: points spent. */
						__( 'You redeemed %d points for a reward. Enjoy!', 'yazan-rewards' ),
						$spent
					)
					: __( 'You have successfully redeemed a reward. Enjoy!', 'yazan-rewards' );
				break;

			case 'points_earned':
				$earned  = (int) ( $payload['points'] ?? 0 );
				$subject = __( 'You earned points', 'yazan-rewards' );
				$body    = sprintf(
					/* translators: %d: points earned. */
					_n( 'You just earned %d point. Keep it up!', 'You just earned %d points. Keep it up!', $earned, 'yazan-rewards' ),
					$earned
				);
				break;

			case 'points_expiring':
				$expiring = (int) ( $payload['points'] ?? 0 );
				$days     = (int) ( $payload['days'] ?? 0 );
				$subject  = __( 'Your points are expiring soon', 'yazan-rewards' );
				$body     = sprintf(
					/* translators: 1: points, 2: days. */
					__( 'You have %1$d points expiring within %2$d days. Redeem them before they are gone.', 'yazan-rewards' ),
					$expiring,
					$days
				);
				break;

			case 'points_expired':
				$subject = __( 'Some points have expired', 'yazan-rewards' );
				$body    = __( 'Some of your points have expired. Keep earning to unlock more rewards.', 'yazan-rewards' );
				break;

			case 'campaign_new':
				$new_title = (string) ( $payload['title'] ?? '' );
				$subject   = __( 'A new campaign has started', 'yazan-rewards' );
				$body      = '' !== $new_title
					? sprintf(
						/* translators: %s: campaign title. */
						__( 'Join our new campaign "%s" and earn rewards.', 'yazan-rewards' ),
						$new_title
					)
					: __( 'A new campaign has started — join and earn rewards.', 'yazan-rewards' );
				break;

			case 'campaign_ending':
				$end_title = (string) ( $payload['title'] ?? '' );
				$subject   = __( 'A campaign is ending soon', 'yazan-rewards' );
				$body      = '' !== $end_title
					? sprintf(
						/* translators: %s: campaign title. */
						__( 'The campaign "%s" is ending soon — take part before it closes.', 'yazan-rewards' ),
						$end_title
					)
					: __( 'A campaign is ending soon — take part before it closes.', 'yazan-rewards' );
				break;

			case 'tier_changed':
				$name    = isset( $payload['tier']['name'] ) ? (string) $payload['tier']['name'] : '';
				$subject = __( 'You have reached a new tier', 'yazan-rewards' );
				$body    = sprintf(
					/* translators: %s: tier name. */
					__( 'Congratulations — you are now a %s member.', 'yazan-rewards' ),
					$name
				);
				break;

			case 'achievement_unlocked':
				$subject = __( 'Achievement unlocked', 'yazan-rewards' );
				$body    = sprintf(
					/* translators: 1: achievement name, 2: points. */
					__( 'You unlocked "%1$s" and earned %2$d points.', 'yazan-rewards' ),
					(string) ( $payload['name'] ?? '' ),
					(int) ( $payload['points_award'] ?? 0 )
				);
				break;

			case 'commission_earned':
				$subject = __( 'You earned a commission', 'yazan-rewards' );
				$body    = sprintf(
					/* translators: %s: amount. */
					__( 'An order from someone you referred earned you %s in store credit.', 'yazan-rewards' ),
					(string) ( $payload['amount'] ?? '' )
				);
				break;

			case 'referral_converted':
				$subject = __( 'Your referral just shopped', 'yazan-rewards' );
				$body    = __( 'Someone you referred placed their first order — your reward has been added.', 'yazan-rewards' );
				break;

			case 'service_requested':
				$subject = __( 'Your service request is received', 'yazan-rewards' );
				$body    = sprintf(
					/* translators: 1: service title, 2: voucher code. */
					__( 'Thank you — your "%1$s" request is received. Your voucher code is %2$s; our team will be in touch to arrange it.', 'yazan-rewards' ),
					(string) ( $payload['title'] ?? '' ),
					(string) ( $payload['code'] ?? '' )
				);
				break;

			case 'service_fulfilled':
				$subject = __( 'Your service is complete', 'yazan-rewards' );
				$body    = __( 'Your Yazan service has been fulfilled. We hope you love the result.', 'yazan-rewards' );
				break;

			case 'campaign_submission_approved':
				$subject = __( 'Your campaign submission was approved', 'yazan-rewards' );
				$body    = sprintf(
					/* translators: %d: points. */
					__( 'Your submission was approved and you earned %d points. Keep going!', 'yazan-rewards' ),
					(int) ( $payload['points'] ?? 0 )
				);
				break;

			case 'campaign_submission_rejected':
				$subject = __( 'Your campaign submission needs attention', 'yazan-rewards' );
				$body    = __( 'Your submission was not approved this time. You can submit again.', 'yazan-rewards' );
				break;

			case 'campaign_completed':
				$subject = __( 'Campaign complete — bonus awarded', 'yazan-rewards' );
				$body    = sprintf(
					/* translators: %d: bonus points. */
					__( 'You completed the campaign and earned a %d-point completion bonus. Thank you!', 'yazan-rewards' ),
					(int) ( $payload['bonus'] ?? 0 )
				);
				break;

			case 'custom':
				// Admin-authored copy from a rule's send_notification action.
				$subject = sanitize_text_field( (string) ( $payload['subject'] ?? $blog ) );
				$body    = sanitize_textarea_field( (string) ( $payload['message'] ?? $payload['body'] ?? '' ) );
				break;

			default:
				$subject = $blog;
				$body    = __( 'You have a new rewards update.', 'yazan-rewards' );
				break;
		}

		$message = array(
			'subject'  => $subject,
			'body'     => $body,
			'template' => $template,
			'payload'  => $payload,
		);

		/**
		 * Filter a rendered notification message.
		 *
		 * @param array  $message  { subject, body, template, payload }.
		 * @param string $template Template key.
		 * @param array  $payload  Payload.
		 */
		return (array) apply_filters( 'yazan_rewards/notification/message', $message, $template, $payload );
	}
}
