<?php
/**
 * Multiple-accounts detector.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\AntiFraud\Detection;

use Yazan\Rewards\Core\Contracts\DetectorInterface;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Core\Support\Fingerprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flags a customer who shares a fingerprint with other accounts: the same normalised
 * email (a Gmail-alias duplicate — strong signal) or the same client IP across several
 * accounts (weaker). It records each acting user's salted IP + email hash in user meta
 * (`_yzrw_fp_ip` / `_yzrw_fp_email`) and counts how many OTHER users share them, reusing
 * the shared {@see Fingerprint} hashes so nothing raw is ever stored.
 */
final class MultiAccountDetector implements DetectorInterface {

	public const META_IP    = '_yzrw_fp_ip';
	public const META_EMAIL = '_yzrw_fp_email';

	/**
	 * @param Fingerprint $fp       Fingerprint helper.
	 * @param Settings    $settings Settings.
	 */
	public function __construct(
		private Fingerprint $fp,
		private Settings $settings
	) {}

	/**
	 * @inheritDoc
	 */
	public function id(): string {
		return 'multi_account';
	}

	/**
	 * @inheritDoc
	 */
	public function enabled(): bool {
		return (bool) $this->settings->get( 'antifraud.detectors.multi_account', true );
	}

	/**
	 * @inheritDoc
	 */
	public function detect( string $event, int $user_id, array $context ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		$user       = get_userdata( $user_id );
		$email_hash = $user && ! empty( $user->user_email ) ? $this->fp->email_hash( (string) $user->user_email ) : '';
		$ip_hash    = (string) ( $context['ip_hash'] ?? $this->fp->ip_hash() );

		// Record this user's fingerprints for future comparisons.
		if ( '' !== $email_hash ) {
			update_user_meta( $user_id, self::META_EMAIL, $email_hash );
		}
		if ( '' !== $ip_hash ) {
			update_user_meta( $user_id, self::META_IP, $ip_hash );
		}

		$signals   = array();
		$threshold = max( 1, (int) $this->settings->get( 'antifraud.multi_account_max', 2 ) );

		// Same normalised email as another account = alias duplicate (high).
		if ( '' !== $email_hash ) {
			$shared = $this->count_meta( self::META_EMAIL, $email_hash, $user_id );
			if ( $shared >= 1 ) {
				$signals[] = array( 'reason' => 'multi_account', 'weight' => 80, 'severity' => 'high', 'meta' => array( 'by' => 'email', 'shared' => $shared ) );
			}
		}
		// Cluster of accounts on one IP (medium).
		if ( '' !== $ip_hash ) {
			$shared_ip = $this->count_meta( self::META_IP, $ip_hash, $user_id );
			if ( $shared_ip >= $threshold ) {
				$signals[] = array( 'reason' => 'multi_account', 'weight' => 40, 'severity' => 'medium', 'meta' => array( 'by' => 'ip', 'shared' => $shared_ip ) );
			}
		}
		return $signals;
	}

	/**
	 * Count OTHER users whose meta value matches.
	 *
	 * @param string $key   Meta key.
	 * @param string $value Meta value.
	 * @param int    $exclude Self.
	 * @return int
	 */
	private function count_meta( string $key, string $value, int $exclude ): int {
		$query = new \WP_User_Query(
			array(
				'meta_key'    => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'exclude'     => array( $exclude ),
				'fields'      => 'ID',
				'number'      => 50,
				'count_total' => true,
			)
		);
		return (int) $query->get_total();
	}
}
