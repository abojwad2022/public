<?php
/**
 * Translation loader.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the plugin text domain. Own domain (`yazan-rewards`) so the platform is
 * fully self-contained and independently translatable.
 */
final class I18n {

	/**
	 * Load translations. Hook: init (priority 1).
	 *
	 * @return void
	 */
	public function load(): void {
		load_plugin_textdomain(
			'yazan-rewards',
			false,
			dirname( YAZAN_REWARDS_BASENAME ) . '/languages'
		);
	}
}
