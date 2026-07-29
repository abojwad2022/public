<?php
/**
 * Referral attribution + conversion.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Referral;

use Yazan\Rewards\Core\Contracts\SubscriberInterface;
use Yazan\Rewards\Core\Events\Event;
use Yazan\Rewards\Core\Events\Events;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Events\GenericEvent;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Core\Support\Fingerprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records a referral relationship at signup (capturing IP + email fingerprints for
 * later abuse checks), accumulates the referred customer's order value on every
 * order, and — on their FIRST order — delegates the multi-level reward payout to the
 * {@see ReferralRewardResolver}. Exact self-referral and re-attribution are blocked
 * up front; deeper abuse (alias/duplicate accounts) is judged at reward time.
 */
final class AttributionService implements SubscriberInterface {

	/**
	 * @param ReferralRepository      $repo     Referral repository.
	 * @param ReferralCodes           $codes    Code resolver.
	 * @param LinkTracker             $tracker  Cookie tracker.
	 * @param ReferralRewardResolver  $resolver Reward resolver.
	 * @param Fingerprint             $fp       Fingerprint helper.
	 * @param EventBus                $bus      Event bus.
	 * @param Settings                $settings Settings.
	 */
	public function __construct(
		private ReferralRepository $repo,
		private ReferralCodes $codes,
		private LinkTracker $tracker,
		private ReferralRewardResolver $resolver,
		private Fingerprint $fp,
		private EventBus $bus,
		private Settings $settings
	) {}

	/**
	 * @inheritDoc
	 */
	public function subscribed_events(): array {
		return array(
			Events::USER_REGISTERED => array( 'on_user_registered' ),
			Events::ORDER_REWARDED  => array( 'on_order_rewarded', 20 ), // After points earn (10).
		);
	}

	/**
	 * Attribute a new signup to the referrer held in the cookie.
	 *
	 * @param Event $event user_registered.
	 * @return void
	 */
	public function on_user_registered( Event $event ): void {
		$referred = (int) $event->get( 'user_id' );
		$code     = $this->tracker->current_code();
		if ( $referred <= 0 || '' === $code ) {
			return;
		}
		$this->attribute( $referred, $code );
		$this->tracker->clear();
	}

	/**
	 * Record a referral relationship. Returns the new row id, or 0 when blocked.
	 *
	 * @param int    $referred_user_id Referred (new) user id.
	 * @param string $code             Referral code.
	 * @return int
	 */
	public function attribute( int $referred_user_id, string $code ): int {
		$referrer = $this->codes->resolve( $code );

		if ( $referrer <= 0 || $referrer === $referred_user_id ) {
			return 0; // Unknown code or exact self-referral.
		}
		if ( $this->repo->find_by_referred( $referred_user_id ) ) {
			return 0; // Already attributed.
		}

		$user       = get_userdata( $referred_user_id );
		$email_hash = ( $user && ! empty( $user->user_email ) ) ? $this->fp->email_hash( (string) $user->user_email ) : '';

		$id = $this->repo->create_signup(
			array(
				'referrer_user_id' => $referrer,
				'referred_user_id' => $referred_user_id,
				'code'             => $code,
				'ip_hash'          => $this->fp->ip_hash(),
				'email_hash'       => $email_hash,
			)
		);

		if ( $id > 0 ) {
			$this->bus->dispatch(
				new GenericEvent(
					Events::REFERRAL_SIGNED_UP,
					array(
						'referral_id' => $id,
						'referrer_id' => $referrer,
						'referred_id' => $referred_user_id,
					)
				)
			);
		}
		return $id;
	}

	/**
	 * Track order value on every order, and reward the referral on the first one.
	 *
	 * @param Event $event order_rewarded.
	 * @return void
	 */
	public function on_order_rewarded( Event $event ): void {
		if ( ! $this->settings->feature_enabled( 'referral' ) ) {
			return;
		}
		$referred    = (int) $event->get( 'customer_id' );
		$order_id    = (int) $event->get( 'order_id' );
		$order_total = (float) $event->get( 'order_total' );
		if ( $referred <= 0 ) {
			return;
		}

		$referral = $this->repo->find_by_referred( $referred );
		if ( ! $referral ) {
			return; // Not a referred customer.
		}

		// Accumulate lifetime revenue for the "generated revenue" metric — every order.
		$this->repo->record_order( $referred, $order_total );

		// Reward once, on the first qualifying order.
		if ( 'signed_up' !== $referral->status ) {
			return;
		}

		$this->resolver->pay_out( $referral, $referred, $order_id, $order_total );

		$this->bus->dispatch(
			new GenericEvent(
				Events::REFERRAL_CONVERTED,
				array(
					'referral_id' => (int) $referral->id,
					'referrer_id' => (int) $referral->referrer_user_id,
					'referred_id' => $referred,
					'order_id'    => $order_id,
					'order_total' => $order_total,
				)
			)
		);
	}
}
