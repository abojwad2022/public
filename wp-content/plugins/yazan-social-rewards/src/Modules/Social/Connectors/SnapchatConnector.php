<?php
/**
 * Snapchat connector.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Social\Connectors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Snapchat connector (Login Kit).
 */
final class SnapchatConnector extends AbstractConnector {

	/**
	 * @inheritDoc
	 */
	public function network(): string {
		return 'snapchat';
	}

	/**
	 * @inheritDoc
	 */
	public function label(): string {
		return __( 'Snapchat', 'yazan-rewards' );
	}

	/**
	 * @inheritDoc
	 */
	public function hosts(): array {
		return array( 'snapchat.com', 't.snapchat.com' );
	}

	/**
	 * @inheritDoc
	 */
	protected function oauth_config(): array {
		return array(
			'auth_url'    => 'https://accounts.snapchat.com/login/oauth2/authorize',
			'token_url'   => 'https://accounts.snapchat.com/login/oauth2/access_token',
			'scopes'      => array( 'https://auth.snapchat.com/oauth2/api/user.display_name' ),
			'account_url' => 'https://kit.snapchat.com/v1/me',
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function map_account( array $body ): array {
		$me = $body['data']['me'] ?? array();
		return array(
			'external_id' => (string) ( $me['externalId'] ?? '' ),
			'handle'      => (string) ( $me['displayName'] ?? '' ),
			'profile_url' => '',
		);
	}
}
