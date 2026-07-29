<?php
/**
 * Liability calculator.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Analytics;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Support\Money;
use Yazan\Rewards\Modules\Points\PointsRepository;
use Yazan\Rewards\Modules\Wallet\WalletRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Computes the store's outstanding reward liability: total unspent points and
 * total unspent store credit — the amount the business still "owes" its
 * customers. Points are also valued in currency using the redemption ratio.
 */
final class LiabilityCalculator {

	/**
	 * @param Container $container Service container.
	 * @param Money     $money     Money helper.
	 */
	public function __construct(
		private Container $container,
		private Money $money
	) {}

	/**
	 * The full liability snapshot.
	 *
	 * @return array<string,mixed>
	 */
	public function snapshot(): array {
		$points_outstanding = 0;
		if ( $this->container->has( PointsRepository::class ) ) {
			$points_outstanding = $this->container->get( PointsRepository::class )->total_outstanding();
		}

		$credit_outstanding = '0';
		if ( $this->container->has( WalletRepository::class ) ) {
			$credit_outstanding = $this->container->get( WalletRepository::class )->total_outstanding();
		}

		// Value the outstanding points in currency using the redemption ratio.
		$ratio = (int) $this->container->get( \Yazan\Rewards\Core\Settings\Settings::class )->get( 'wallet.points_to_currency', 100 );
		$points_value = $this->money->points_to_currency( $points_outstanding, $ratio > 0 ? $ratio : 100 );

		return array(
			'points_outstanding' => $points_outstanding,
			'points_value'       => $points_value,
			'credit_outstanding' => $this->money->format( $credit_outstanding ),
			'total_value'        => $this->money->format( (float) $points_value + (float) $credit_outstanding ),
		);
	}
}
