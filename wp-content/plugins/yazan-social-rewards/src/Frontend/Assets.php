<?php
/**
 * Front-end asset loader (account hub).
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Frontend;

use Yazan\Rewards\Core\Contracts\Hookable;
use Yazan\Rewards\Core\Support\Assets as AssetHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the account-hub CSS/JS only on the My Account page and hands the
 * script the REST root + a fresh `wp_rest` nonce so redeem/apply calls are
 * authenticated and CSRF-safe.
 */
final class Assets implements Hookable {

	/**
	 * @param AssetHelper $assets Asset URL/version helper.
	 */
	public function __construct( private AssetHelper $assets ) {}

	/**
	 * @inheritDoc
	 */
	public function hooks(): array {
		return array(
			array( 'type' => 'action', 'hook' => 'wp_enqueue_scripts', 'method' => 'enqueue' ),
		);
	}

	/**
	 * Enqueue on the account page only.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}

		wp_enqueue_style(
			'yazan-rewards-account',
			$this->assets->url( 'assets/css/account.css' ),
			array(),
			$this->assets->version( 'assets/css/account.css' )
		);

		wp_enqueue_script(
			'yazan-rewards-account',
			$this->assets->url( 'assets/js/account.js' ),
			array(),
			$this->assets->version( 'assets/js/account.js' ),
			true
		);

		// Notifications panel (inbox + preferences) — reuses the YazanRewards global,
		// so it must load after the account script it is localized on.
		wp_enqueue_style(
			'yazan-rewards-notifications',
			$this->assets->url( 'assets/css/account-notifications.css' ),
			array( 'yazan-rewards-account' ),
			$this->assets->version( 'assets/css/account-notifications.css' )
		);
		wp_enqueue_script(
			'yazan-rewards-notifications',
			$this->assets->url( 'assets/js/account-notifications.js' ),
			array( 'yazan-rewards-account' ),
			$this->assets->version( 'assets/js/account-notifications.js' ),
			true
		);

		wp_localize_script(
			'yazan-rewards-account',
			'YazanRewards',
			array(
				'restUrl' => esc_url_raw( rest_url( 'yazan-rewards/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'redeeming' => __( 'Redeeming…', 'yazan-rewards' ),
					'redeemed'  => __( 'Redeemed!', 'yazan-rewards' ),
					'error'     => __( 'Something went wrong. Please try again.', 'yazan-rewards' ),
					'confirm'   => __( 'Redeem this reward?', 'yazan-rewards' ),
					'inReview'  => __( 'In review', 'yazan-rewards' ),
				),
			)
		);
	}
}
