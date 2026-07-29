<?php
/**
 * Duplicate URL / post detector.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\AntiFraud\Detection;

use Yazan\Rewards\Core\Contracts\DetectorInterface;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Modules\Social\SocialRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flags a submitted post URL that was already used: a resubmit by the same customer
 * (`duplicate_url`) or a repost of another customer's content (`duplicate_post`, a
 * stronger signal). Reuses the Social engine's normalised `url_hash` dedup — the same
 * hash the manual verifier already rejects on — so detection is a single indexed read.
 */
final class DuplicateContentDetector implements DetectorInterface {

	/**
	 * @param SocialRepository $social   Social actions repo (url_hash helpers).
	 * @param Settings         $settings Settings.
	 */
	public function __construct(
		private SocialRepository $social,
		private Settings $settings
	) {}

	/**
	 * @inheritDoc
	 */
	public function id(): string {
		return 'duplicate';
	}

	/**
	 * @inheritDoc
	 */
	public function enabled(): bool {
		return (bool) $this->settings->get( 'antifraud.detectors.duplicate', true );
	}

	/**
	 * @inheritDoc
	 */
	public function detect( string $event, int $user_id, array $context ): array {
		$url = (string) ( $context['url'] ?? '' );
		if ( '' === $url ) {
			return array();
		}
		$hash    = $this->social->url_hash( $url );
		$exclude = (int) ( $context['exclude_id'] ?? 0 );
		if ( '' === $hash || ! $this->social->has_url_hash( $hash, $exclude ) ) {
			return array();
		}

		$owner = $this->social->owner_of_url_hash( $hash, $exclude );
		if ( $owner > 0 && $owner !== $user_id ) {
			return array( array(
				'reason'   => 'duplicate_post',
				'weight'   => 70,
				'severity' => 'high',
				'meta'     => array( 'original_user' => $owner ),
			) );
		}
		return array( array(
			'reason'   => 'duplicate_url',
			'weight'   => 40,
			'severity' => 'medium',
			'meta'     => array(),
		) );
	}
}
