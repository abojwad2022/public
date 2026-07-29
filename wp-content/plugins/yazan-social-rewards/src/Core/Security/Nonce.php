<?php
/**
 * Nonce helpers.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small helpers around the WordPress nonce API. REST uses the standard `wp_rest`
 * nonce (validated by core via the X-WP-Nonce header); these helpers cover
 * front-end AJAX forms and mint the REST nonce for the SPA boot island.
 */
final class Nonce {

	/** Action for the customer-facing AJAX/REST surface. */
	public const ACTION = 'yazan_rewards';

	/**
	 * Mint the REST nonce for the JS boot payload.
	 *
	 * @return string
	 */
	public function rest(): string {
		return wp_create_nonce( 'wp_rest' );
	}

	/**
	 * Create a nonce for a custom AJAX action.
	 *
	 * @param string $action Action name.
	 * @return string
	 */
	public function create( string $action = self::ACTION ): string {
		return wp_create_nonce( $action );
	}

	/**
	 * Verify a raw nonce value for a custom action. Caller must wp_unslash().
	 *
	 * @param string $value  Raw nonce.
	 * @param string $action Action name.
	 * @return bool
	 */
	public function verify( string $value, string $action = self::ACTION ): bool {
		return (bool) wp_verify_nonce( $value, $action );
	}
}
