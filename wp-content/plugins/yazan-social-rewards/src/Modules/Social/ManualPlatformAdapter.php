<?php
/**
 * Manual/auto verification adapter.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Social;

use Yazan\Rewards\Core\Contracts\PlatformAdapterInterface;
use Yazan\Rewards\Core\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The v1 verification method. Share intents are trusted on click (approved);
 * UGC submissions default to admin review (pending) unless auto-approval is
 * enabled in settings. A future real adapter (Instagram/TikTok/X) replaces this
 * per platform without touching the submission flow.
 */
final class ManualPlatformAdapter implements PlatformAdapterInterface {

	/**
	 * @param Settings $settings Settings.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * @inheritDoc
	 */
	public function platform(): string {
		return 'manual';
	}

	/**
	 * @inheritDoc
	 */
	public function verify( array $action ): string {
		$type = (string) ( $action['action_type'] ?? '' );

		if ( 'share' === $type ) {
			return 'approved'; // Trust-on-click; throttled elsewhere.
		}

		// UGC: admin review unless auto-approval is on.
		return $this->settings->get( 'social.auto_approve_ugc', false ) ? 'approved' : 'pending';
	}
}
