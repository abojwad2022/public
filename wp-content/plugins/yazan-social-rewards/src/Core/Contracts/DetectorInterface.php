<?php
/**
 * Fraud detector contract.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One fraud-detection strategy. Detectors are interchangeable and discovered by the
 * risk engine, which aggregates their weighted signals into a decision. A detector
 * NEVER blocks or writes anything itself — it only reports signals.
 *
 * A signal is an array: { reason: string, weight: int, severity: string, meta: array }.
 */
interface DetectorInterface {

	/**
	 * Stable detector id (also its settings toggle key), e.g. "duplicate".
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Whether this detector is enabled (feature/settings gate).
	 *
	 * @return bool
	 */
	public function enabled(): bool;

	/**
	 * Inspect an action and return zero or more signals.
	 *
	 * @param string $event   Event key (e.g. "ugc", "referral", "earn").
	 * @param int    $user_id Acting user.
	 * @param array  $context Event facts (url, content, metrics, ip_hash, source, …).
	 * @return array<int,array{reason:string,weight:int,severity:string,meta:array}>
	 */
	public function detect( string $event, int $user_id, array $context ): array;
}
