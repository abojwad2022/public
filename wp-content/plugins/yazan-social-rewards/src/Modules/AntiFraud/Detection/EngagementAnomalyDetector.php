<?php
/**
 * Artificial-engagement detector.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\AntiFraud\Detection;

use Yazan\Rewards\Core\Contracts\DetectorInterface;
use Yazan\Rewards\Core\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flags implausible engagement metrics on a submission (bought likes/views/followers):
 * more likes than views, or an engagement ratio far above what organic content shows at
 * scale. Metrics come from the connector's API (`social_actions.metrics`), so this only
 * fires when a network's API is live; without metrics it is a no-op seam.
 */
final class EngagementAnomalyDetector implements DetectorInterface {

	/** Engagement rate above this on a high-view post is implausibly high. */
	private const MAX_RATE       = 0.80;
	private const HIGH_VIEWS     = 1000;

	/**
	 * @param Settings $settings Settings.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * @inheritDoc
	 */
	public function id(): string {
		return 'engagement';
	}

	/**
	 * @inheritDoc
	 */
	public function enabled(): bool {
		return (bool) $this->settings->get( 'antifraud.detectors.engagement', true );
	}

	/**
	 * @inheritDoc
	 */
	public function detect( string $event, int $user_id, array $context ): array {
		$metrics = (array) ( $context['metrics'] ?? array() );
		if ( empty( $metrics ) ) {
			return array();
		}
		$views    = (int) ( $metrics['views'] ?? $metrics['impressions'] ?? 0 );
		$likes    = (int) ( $metrics['likes'] ?? 0 );
		$comments = (int) ( $metrics['comments'] ?? 0 );

		// More likes than views is physically impossible → bought engagement.
		if ( $views > 0 && $likes > $views ) {
			return array( array(
				'reason'   => 'artificial_engagement',
				'weight'   => 60,
				'severity' => 'high',
				'meta'     => array( 'likes' => $likes, 'views' => $views ),
			) );
		}
		// Implausibly high engagement rate on a high-view post.
		if ( $views >= self::HIGH_VIEWS ) {
			$rate = ( $likes + $comments ) / $views;
			if ( $rate > self::MAX_RATE ) {
				return array( array(
					'reason'   => 'artificial_engagement',
					'weight'   => 50,
					'severity' => 'medium',
					'meta'     => array( 'rate' => round( $rate, 3 ), 'views' => $views ),
				) );
			}
		}
		return array();
	}
}
