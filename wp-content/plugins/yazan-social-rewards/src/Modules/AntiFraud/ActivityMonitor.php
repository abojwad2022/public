<?php
/**
 * Activity-velocity detector.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\AntiFraud;

use Yazan\Rewards\Core\Contracts\DetectorInterface;
use Yazan\Rewards\Core\Security\RateLimiter;
use Yazan\Rewards\Core\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Monitors how fast a customer repeats an action (e.g. UGC submissions) using the
 * transient-backed {@see RateLimiter}. Repeating the same action many times in a short
 * window is a bot/farming signal. Complements the points-credit velocity check the risk
 * engine already runs on the ledger.
 */
final class ActivityMonitor implements DetectorInterface {

	/** Rolling window, seconds. */
	private const WINDOW = 300;

	/**
	 * @param RateLimiter $limiter  Rate limiter.
	 * @param Settings    $settings Settings.
	 */
	public function __construct(
		private RateLimiter $limiter,
		private Settings $settings
	) {}

	/**
	 * @inheritDoc
	 */
	public function id(): string {
		return 'velocity';
	}

	/**
	 * @inheritDoc
	 */
	public function enabled(): bool {
		return (bool) $this->settings->get( 'antifraud.detectors.velocity', true );
	}

	/**
	 * @inheritDoc
	 */
	public function detect( string $event, int $user_id, array $context ): array {
		$max   = max( 1, (int) $this->settings->get( 'antifraud.velocity_max', 12 ) );
		$key   = 'act_' . sanitize_key( $event );
		$count = $this->limiter->count( $key, (string) $user_id );
		$this->limiter->hit( $key, (string) $user_id, self::WINDOW );

		if ( $count >= $max ) {
			return array( array(
				'reason'   => 'velocity',
				'weight'   => 50,
				'severity' => 'high',
				'meta'     => array( 'count' => $count, 'window_s' => self::WINDOW ),
			) );
		}
		return array();
	}
}
