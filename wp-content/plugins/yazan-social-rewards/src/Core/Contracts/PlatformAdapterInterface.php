<?php
/**
 * Social platform adapter contract.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How a social action is verified. v1 ships a manual/auto adapter (admin review
 * or trust-on-submit). A future real adapter (Instagram/TikTok/X APIs) implements
 * this same interface — auto-verifying a follow or a hashtag post — and is bound
 * per platform via the container, with no change to the submission flow.
 */
interface PlatformAdapterInterface {

	/**
	 * The platform id this adapter verifies (e.g. "instagram", "manual").
	 *
	 * @return string
	 */
	public function platform(): string;

	/**
	 * Verify a social action.
	 *
	 * @param array $action The social action row/data (platform, action_type, url…).
	 * @return string One of: "approved", "rejected", "pending".
	 */
	public function verify( array $action ): string;
}
