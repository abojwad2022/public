<?php
/**
 * Risk-assessment decision.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\AntiFraud;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The outcome of {@see RiskEngine::assess()}: an action (allow | review), the summed
 * risk score, and the contributing signals. Per the product decision, a flagged action
 * is HELD for review — there is no hard auto-block — so `action` is allow or review.
 */
final class Decision {

	public const ALLOW  = 'allow';
	public const REVIEW = 'review';

	/**
	 * @param string $action  ALLOW | REVIEW.
	 * @param int    $score   Summed weight.
	 * @param array  $signals Contributing signals.
	 */
	public function __construct(
		public string $action,
		public int $score,
		public array $signals = array()
	) {}

	/**
	 * Whether the action should be held for manual review.
	 *
	 * @return bool
	 */
	public function needs_review(): bool {
		return self::REVIEW === $this->action;
	}

	/**
	 * Whether any signal fired (suspicious, even if below the review threshold).
	 *
	 * @return bool
	 */
	public function is_suspicious(): bool {
		return ! empty( $this->signals );
	}

	/**
	 * The distinct reason codes.
	 *
	 * @return string[]
	 */
	public function reasons(): array {
		return array_values( array_unique( array_map( static fn( $s ) => (string) ( $s['reason'] ?? '' ), $this->signals ) ) );
	}

	/**
	 * The highest signal severity (low|medium|high), or 'low'.
	 *
	 * @return string
	 */
	public function severity(): string {
		$rank = array( 'low' => 1, 'medium' => 2, 'high' => 3 );
		$max  = 'low';
		foreach ( $this->signals as $s ) {
			$sev = (string) ( $s['severity'] ?? 'low' );
			if ( ( $rank[ $sev ] ?? 1 ) > ( $rank[ $max ] ?? 1 ) ) {
				$max = $sev;
			}
		}
		return $max;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'action'  => $this->action,
			'score'   => $this->score,
			'reasons' => $this->reasons(),
			'signals' => $this->signals,
		);
	}
}
