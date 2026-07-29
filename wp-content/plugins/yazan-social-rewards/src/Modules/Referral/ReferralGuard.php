<?php
/**
 * Referral abuse guard.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Referral;

use Yazan\Rewards\Core\Events\Events;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Events\GenericEvent;
use Yazan\Rewards\Core\Support\Fingerprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Assesses whether a referral is likely self-dealing or duplicate-account abuse and
 * returns a decision the reward resolver acts on (hold / block / flag). Signals:
 *   - exact self-referral (same user id);
 *   - same normalised email between referrer and referred (alias self-referral, e.g.
 *     `a.b+promo@gmail.com` inviting `ab@gmail.com`);
 *   - a cluster of signups sharing the referred customer's IP hash.
 *
 * When suspicious it DISPATCHES {@see Events::FRAUD_FLAGGED} (a normalised payload); the
 * Anti-Fraud engine's central logger persists the single fraud case — this guard no
 * longer writes the flag table itself, so there is exactly one writer.
 */
final class ReferralGuard {

	/** This many OTHER referrals sharing one IP hash marks a cluster. */
	private const SAME_IP_CLUSTER = 2;

	/**
	 * @param ReferralRepository $repo Referral repository.
	 * @param Fingerprint        $fp   Fingerprint helper.
	 * @param EventBus           $bus  Event bus.
	 */
	public function __construct(
		private ReferralRepository $repo,
		private Fingerprint $fp,
		private EventBus $bus
	) {}

	/**
	 * Assess a referral relationship for abuse.
	 *
	 * @param int    $referrer_id Referrer user id.
	 * @param int    $referred_id Referred user id.
	 * @param object $referral    The referral row (carries ip_hash + email_hash).
	 * @return array{suspicious:bool,reasons:string[]}
	 */
	public function assess( int $referrer_id, int $referred_id, object $referral ): array {
		$reasons    = array();
		$ip_hash    = (string) ( $referral->ip_hash ?? '' );
		$email_hash = (string) ( $referral->email_hash ?? '' );

		if ( $referrer_id > 0 && $referrer_id === $referred_id ) {
			$reasons[] = 'self_referral';
		}

		// Alias self-referral: the referrer's normalised email equals the referred's.
		if ( '' !== $email_hash && $referrer_id > 0 ) {
			$referrer = get_userdata( $referrer_id );
			if ( $referrer && ! empty( $referrer->user_email ) ) {
				$referrer_hash = $this->fp->email_hash( (string) $referrer->user_email );
				if ( '' !== $referrer_hash && hash_equals( $referrer_hash, $email_hash ) ) {
					$reasons[] = 'same_email';
				}
			}
		}

		// Duplicate-account cluster: other accounts THIS referrer brought in from the
		// same IP (scoped to the referrer so shared NAT doesn't false-positive).
		if ( '' !== $ip_hash && $referrer_id > 0 ) {
			$others = $this->repo->count_by_ip_hash( $ip_hash, $referrer_id, $referred_id );
			if ( $others >= self::SAME_IP_CLUSTER ) {
				$reasons[] = 'same_ip_cluster';
			}
		}

		$suspicious = ! empty( $reasons );
		if ( $suspicious ) {
			$this->raise( $referred_id, $referrer_id, $reasons, $ip_hash );
		}

		return array(
			'suspicious' => $suspicious,
			'reasons'    => $reasons,
		);
	}

	/**
	 * Record + broadcast a referral-abuse flag.
	 *
	 * @param int      $referred_id Referred user id.
	 * @param int      $referrer_id Referrer user id.
	 * @param string[] $reasons     Reason codes.
	 * @param string   $ip_hash     IP hash.
	 * @return void
	 */
	private function raise( int $referred_id, int $referrer_id, array $reasons, string $ip_hash ): void {
		$this->bus->dispatch(
			new GenericEvent(
				Events::FRAUD_FLAGGED,
				array(
					'user_id'     => $referred_id,
					'type'        => 'referral_abuse',
					'severity'    => in_array( 'same_email', $reasons, true ) ? 'high' : 'medium',
					'context'     => array( 'referrer_id' => $referrer_id, 'reasons' => $reasons ),
					'reasons'     => $reasons,
					'referrer_id' => $referrer_id,
					'ip_hash'     => $ip_hash,
				)
			)
		);
	}
}
