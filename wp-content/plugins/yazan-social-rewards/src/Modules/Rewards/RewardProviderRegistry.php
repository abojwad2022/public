<?php
/**
 * Reward fulfillment provider registry.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Rewards;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps a reward `type` to the {@see RewardIssuerInterface} that fulfills it. The
 * built-in types (credit / coupon / free_shipping / free_product / the four service
 * types) are registered by the Rewards module; third parties add or override a type
 * via `yazan_register_reward_provider()` (which filters `yazan_rewards/rewards/providers`).
 * The {@see RedemptionService} looks up the issuer here at redemption time — an
 * unknown type resolves to null and is rejected before any points are debited.
 */
final class RewardProviderRegistry {

	/**
	 * @var array<string,RewardIssuerInterface>
	 */
	private array $providers = array();

	/**
	 * Register (or override) the issuer for a reward type.
	 *
	 * @param string               $type   Reward type key.
	 * @param RewardIssuerInterface $issuer Fulfillment provider.
	 * @return void
	 */
	public function register( string $type, RewardIssuerInterface $issuer ): void {
		$type = sanitize_key( $type );
		if ( '' !== $type ) {
			$this->providers[ $type ] = $issuer;
		}
	}

	/**
	 * The issuer for a type, or null when none is registered.
	 *
	 * @param string $type Reward type.
	 * @return RewardIssuerInterface|null
	 */
	public function for_type( string $type ): ?RewardIssuerInterface {
		return $this->providers[ sanitize_key( $type ) ] ?? null;
	}

	/**
	 * Whether a type has a provider.
	 *
	 * @param string $type Reward type.
	 * @return bool
	 */
	public function has( string $type ): bool {
		return isset( $this->providers[ sanitize_key( $type ) ] );
	}

	/**
	 * All registered type keys.
	 *
	 * @return string[]
	 */
	public function types(): array {
		return array_keys( $this->providers );
	}
}
