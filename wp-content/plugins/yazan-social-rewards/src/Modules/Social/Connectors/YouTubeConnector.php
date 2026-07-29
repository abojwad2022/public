<?php
/**
 * YouTube (Google) connector.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Social\Connectors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * YouTube connector (Google OAuth + YouTube Data API).
 */
final class YouTubeConnector extends AbstractConnector {

	/**
	 * @inheritDoc
	 */
	public function network(): string {
		return 'youtube';
	}

	/**
	 * @inheritDoc
	 */
	public function label(): string {
		return __( 'YouTube', 'yazan-rewards' );
	}

	/**
	 * @inheritDoc
	 */
	public function hosts(): array {
		return array( 'youtube.com', 'youtu.be' );
	}

	/**
	 * @inheritDoc
	 */
	protected function oauth_config(): array {
		return array(
			'auth_url'          => 'https://accounts.google.com/o/oauth2/v2/auth',
			'token_url'         => 'https://oauth2.googleapis.com/token',
			'scopes'            => array( 'openid', 'profile', 'https://www.googleapis.com/auth/youtube.readonly' ),
			'account_url'       => 'https://www.googleapis.com/oauth2/v3/userinfo',
			// Needed for a refresh token from Google.
			'extra_auth_params' => array( 'access_type' => 'offline', 'prompt' => 'consent' ),
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function map_account( array $body ): array {
		return array(
			'external_id' => (string) ( $body['sub'] ?? '' ),
			'handle'      => (string) ( $body['name'] ?? '' ),
			'profile_url' => (string) ( $body['profile'] ?? '' ),
		);
	}
}
