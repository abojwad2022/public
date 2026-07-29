<?php
/**
 * TikTok connector.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Social\Connectors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TikTok connector (Login Kit / Display API).
 */
final class TikTokConnector extends AbstractConnector {

	/**
	 * @inheritDoc
	 */
	public function network(): string {
		return 'tiktok';
	}

	/**
	 * @inheritDoc
	 */
	public function label(): string {
		return __( 'TikTok', 'yazan-rewards' );
	}

	/**
	 * @inheritDoc
	 */
	public function hosts(): array {
		return array( 'tiktok.com', 'vm.tiktok.com' );
	}

	/**
	 * @inheritDoc
	 */
	protected function oauth_config(): array {
		return array(
			'auth_url'    => 'https://www.tiktok.com/v2/auth/authorize/',
			'token_url'   => 'https://open.tiktokapis.com/v2/oauth/token/',
			'scopes'      => array( 'user.info.basic', 'video.list' ),
			'account_url' => 'https://open.tiktokapis.com/v2/user/info/',
		);
	}
}
