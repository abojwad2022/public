<?php
/**
 * Membership-level checkout discount.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Achievement;

use Yazan\Rewards\Core\Contracts\Hookable;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Core\Support\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies the signed-in member's loyalty-level discount to the cart as a negative
 * fee (e.g. Gold = 10% off the subtotal), labelled "{Level} member discount". The
 * level is driven by lifetime earned points and cached in user meta, so this stays
 * a cheap read. Coexists with the wallet store-credit fee.
 */
final class TierDiscount implements Hookable {

	/**
	 * @param TierEngine $tiers    Tier engine (cached discount + level name).
	 * @param Settings   $settings Settings.
	 * @param Money      $money    Money helper.
	 */
	public function __construct(
		private TierEngine $tiers,
		private Settings $settings,
		private Money $money
	) {}

	/**
	 * @inheritDoc
	 */
	public function hooks(): array {
		return array(
			array( 'type' => 'action', 'hook' => 'woocommerce_cart_calculate_fees', 'method' => 'apply', 'args' => 1 ),
		);
	}

	/**
	 * Add the member discount fee.
	 *
	 * @param \WC_Cart $cart Cart.
	 * @return void
	 */
	public function apply( $cart ): void {
		if ( ! $cart instanceof \WC_Cart || is_admin() ) {
			return;
		}
		if ( ! $this->settings->get( 'tier.apply_discount', true ) ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$percent = $this->tiers->discount_percent( $user_id );
		if ( $percent <= 0 ) {
			return;
		}
		$percent = min( 100.0, $percent );

		$subtotal = (float) $cart->get_subtotal();
		$amount   = (float) $this->money->format( $subtotal * ( $percent / 100 ) );
		if ( $amount <= 0 ) {
			return;
		}

		$name  = (string) $this->tiers->current( $user_id )['name'];
		$label = $name
			? sprintf( /* translators: %s: level name. */ __( '%s member discount', 'yazan-rewards' ), $name )
			: __( 'Member discount', 'yazan-rewards' );

		$cart->add_fee( $label, -$amount, false );
	}
}
