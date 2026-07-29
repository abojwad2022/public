<?php
/**
 * Referral reward-rule configuration.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Referral;

use Yazan\Rewards\Core\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the admin-configurable referral reward rules from settings and exposes them
 * as validated specs. A spec is `{ kind: points|credit, mode: fixed|percent, value }`.
 * Falls back to the legacy flat `referrer_reward` / `referred_reward` point values
 * when a structured spec is absent, so older installs keep working.
 */
final class ReferralRewardRules {

	/**
	 * @param Settings $settings Settings.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * Reward depth: 1 = direct referrer only, 2 = + grand-referrer.
	 *
	 * @return int
	 */
	public function max_levels(): int {
		return max( 1, min( 2, (int) $this->settings->get( 'referral.max_levels', 2 ) ) );
	}

	/**
	 * When rewards are paid. v1 pays on the referred customer's FIRST order only
	 * (revenue from later orders is still tracked); recurring `every_order` payout is
	 * not implemented, so this is always `first_order` rather than silently accepting
	 * a setting the payout path never honours.
	 *
	 * @return string
	 */
	public function trigger(): string {
		return 'first_order';
	}

	/**
	 * Minimum order total that qualifies for a reward.
	 *
	 * @return float
	 */
	public function min_order_total(): float {
		return max( 0.0, (float) $this->settings->get( 'referral.min_order_total', 0 ) );
	}

	/**
	 * What to do with a suspicious referral: hold | block | flag.
	 *
	 * @return string
	 */
	public function abuse_action(): string {
		$a = (string) $this->settings->get( 'referral.abuse_action', 'hold' );
		return in_array( $a, array( 'hold', 'block', 'flag' ), true ) ? $a : 'hold';
	}

	/**
	 * The new customer's welcome reward spec.
	 *
	 * @return array{kind:string,mode:string,value:float}
	 */
	public function referred_spec(): array {
		return $this->spec( 'referred', 'referred_reward', 100 );
	}

	/**
	 * The spec for a referrer at a given tree level (1 = direct, 2 = grand-referrer).
	 *
	 * @param int $level Level.
	 * @return array{kind:string,mode:string,value:float}
	 */
	public function spec_for_level( int $level ): array {
		return 1 === $level
			? $this->spec( 'referrer', 'referrer_reward', 200 )
			: $this->spec( 'level2', null, 50 );
	}

	/**
	 * Resolve one reward spec from settings, validated, with a legacy fallback.
	 *
	 * @param string      $key            Settings key under `referral.`.
	 * @param string|null $legacy_key     Legacy flat-points key, if any.
	 * @param int         $legacy_default Default when nothing is set.
	 * @return array{kind:string,mode:string,value:float}
	 */
	private function spec( string $key, ?string $legacy_key, int $legacy_default ): array {
		$raw = $this->settings->get( 'referral.' . $key, null );
		if ( is_array( $raw ) ) {
			$kind = (string) ( $raw['kind'] ?? 'points' );
			$mode = (string) ( $raw['mode'] ?? 'fixed' );
			return array(
				'kind'  => in_array( $kind, array( 'points', 'credit' ), true ) ? $kind : 'points',
				'mode'  => in_array( $mode, array( 'fixed', 'percent' ), true ) ? $mode : 'fixed',
				'value' => max( 0.0, (float) ( $raw['value'] ?? 0 ) ),
			);
		}

		$value = null !== $legacy_key
			? (float) $this->settings->get( 'referral.' . $legacy_key, $legacy_default )
			: (float) $legacy_default;

		return array( 'kind' => 'points', 'mode' => 'fixed', 'value' => max( 0.0, $value ) );
	}
}
