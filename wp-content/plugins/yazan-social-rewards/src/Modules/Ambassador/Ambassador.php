<?php
/**
 * Ambassador value object.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Ambassador;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A store ambassador — an existing customer approved to earn commission on the
 * orders of customers they refer. Not a WordPress role; status lives here.
 */
final class Ambassador {

	public const STATUS_PENDING   = 'pending';
	public const STATUS_ACTIVE    = 'active';
	public const STATUS_SUSPENDED = 'suspended';

	/**
	 * @param int    $id              Row id.
	 * @param int    $user_id         WP user id.
	 * @param string $code            Referral/ambassador code.
	 * @param string $status          One of STATUS_*.
	 * @param string $tier            Ambassador tier slug.
	 * @param float  $commission_rate Percent of attributed order total.
	 * @param array  $stats           Cached stats.
	 */
	public function __construct(
		public int $id,
		public int $user_id,
		public string $code,
		public string $status,
		public string $tier,
		public float $commission_rate,
		public array $stats = array()
	) {}

	/**
	 * Build from a DB row.
	 *
	 * @param object $row Row.
	 * @return Ambassador
	 */
	public static function from_row( object $row ): Ambassador {
		$stats = json_decode( (string) ( $row->stats ?? '' ), true );
		return new self(
			(int) $row->id,
			(int) $row->user_id,
			(string) $row->code,
			(string) $row->status,
			(string) ( $row->tier ?? '' ),
			(float) ( $row->commission_rate ?? 0 ),
			is_array( $stats ) ? $stats : array()
		);
	}

	/**
	 * Whether this ambassador is active (eligible to earn).
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return self::STATUS_ACTIVE === $this->status;
	}

	/**
	 * Public-safe array for REST.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'id'              => $this->id,
			'status'          => $this->status,
			'code'            => $this->code,
			'tier'            => $this->tier,
			'commission_rate' => $this->commission_rate,
			'stats'           => $this->stats,
		);
	}
}
