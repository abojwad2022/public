<?php
/**
 * Reward value object.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Rewards;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A redeemable item in the rewards catalog. `type` decides how the value is
 * delivered (store credit vs a WooCommerce coupon vs free shipping); `value`
 * carries the type-specific parameters.
 */
final class Reward {

	/** Delivered as store-credit wallet balance. value: { amount } */
	public const TYPE_CREDIT = 'credit';

	/** Delivered as a single-use WooCommerce coupon. value: { discount_type, amount } */
	public const TYPE_COUPON = 'coupon';

	/** Delivered as a free-shipping coupon. value: {} */
	public const TYPE_FREE_SHIPPING = 'free_shipping';

	/** A coupon that makes specific products free. value: { product_ids[] } */
	public const TYPE_FREE_PRODUCT = 'free_product';

	/* Service rewards — fulfilled by staff via a voucher + queue (no coupon). */
	public const TYPE_VIP_SERVICE       = 'vip_service';
	public const TYPE_RING_CLEANING     = 'ring_cleaning';
	public const TYPE_RING_POLISHING    = 'ring_polishing';
	public const TYPE_EXCLUSIVE_PRODUCT = 'exclusive_product';

	/**
	 * @param int                 $id             Row id.
	 * @param string              $type           One of the TYPE_* constants.
	 * @param string              $title          Display title.
	 * @param string              $description    Display description.
	 * @param int                 $cost_points    Points required.
	 * @param array<string,mixed> $value          Type-specific parameters.
	 * @param int                 $stock          Remaining stock (-1 = unlimited).
	 * @param int                 $tier_min       Minimum tier order to redeem (0 = any).
	 * @param bool                $active         Enabled.
	 * @param int                 $sort           Display order.
	 * @param string|null         $available_from Availability window start (or null).
	 * @param string|null         $available_to   Availability window end / expiration (or null).
	 * @param int                 $per_user_limit Max redemptions per customer (0 = unlimited).
	 */
	public function __construct(
		public int $id,
		public string $type,
		public string $title,
		public string $description,
		public int $cost_points,
		public array $value,
		public int $stock = -1,
		public int $tier_min = 0,
		public bool $active = true,
		public int $sort = 0,
		public ?string $available_from = null,
		public ?string $available_to = null,
		public int $per_user_limit = 0
	) {}

	/**
	 * Build from a DB row.
	 *
	 * @param object $row Row.
	 * @return Reward
	 */
	public static function from_row( object $row ): Reward {
		$value = json_decode( (string) ( $row->value ?? '' ), true );
		$zero  = '0000-00-00 00:00:00';
		$from  = ( isset( $row->available_from ) && $zero !== $row->available_from ) ? (string) $row->available_from : null;
		$to    = ( isset( $row->available_to ) && $zero !== $row->available_to ) ? (string) $row->available_to : null;

		return new self(
			(int) $row->id,
			(string) $row->type,
			(string) $row->title,
			(string) $row->description,
			(int) $row->cost_points,
			is_array( $value ) ? $value : array(),
			(int) ( $row->stock ?? -1 ),
			(int) ( $row->tier_min ?? 0 ),
			(bool) ( (int) ( $row->active ?? 1 ) ),
			(int) ( $row->sort ?? 0 ),
			$from,
			$to,
			(int) ( $row->per_user_limit ?? 0 )
		);
	}

	/** Service-type rewards are fulfilled manually via a voucher + queue. */
	public function is_service(): bool {
		return in_array( $this->type, array( self::TYPE_VIP_SERVICE, self::TYPE_RING_CLEANING, self::TYPE_RING_POLISHING, self::TYPE_EXCLUSIVE_PRODUCT ), true );
	}

	/** Coupon-type rewards issue a WooCommerce coupon. */
	public function is_coupon(): bool {
		return in_array( $this->type, array( self::TYPE_COUPON, self::TYPE_FREE_SHIPPING, self::TYPE_FREE_PRODUCT ), true );
	}

	/**
	 * Whether the reward can currently be redeemed: active + in stock + within its
	 * availability window.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		if ( ! $this->active ) {
			return false;
		}
		if ( ! ( $this->stock < 0 || $this->stock > 0 ) ) {
			return false;
		}
		$now = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		if ( $this->available_from && strtotime( $this->available_from ) > $now ) {
			return false;
		}
		if ( $this->available_to && strtotime( $this->available_to ) < $now ) {
			return false;
		}
		return true;
	}

	/**
	 * A public-safe array for the REST catalog / admin.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'id'             => $this->id,
			'type'           => $this->type,
			'title'          => $this->title,
			'description'    => $this->description,
			'cost_points'    => $this->cost_points,
			'value'          => $this->value,
			'stock'          => $this->stock,
			'unlimited'      => $this->stock < 0,
			'in_stock'       => $this->is_available(),
			'tier_min'       => $this->tier_min,
			'active'         => $this->active,
			'sort'           => $this->sort,
			'available_from' => $this->available_from,
			'available_to'   => $this->available_to,
			'per_user_limit' => $this->per_user_limit,
			'is_service'     => $this->is_service(),
		);
	}
}
