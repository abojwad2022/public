<?php
/**
 * Facebook connector.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Social\Connectors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Facebook connector (Login + Graph API).
 */
final class FacebookConnector extends AbstractConnector {

	/**
	 * @inheritDoc
	 */
	public function network(): string {
		return 'facebook';
	}

	/**
	 * @inheritDoc
	 */
	public function label(): string {
		return __( 'Facebook', 'yazan-rewards' );
	}

	/**
	 * @inheritDoc
	 */
	public function hosts(): array {
		return array( 'facebook.com', 'fb.com', 'fb.watch' );
	}

	/**
	 * @inheritDoc
	 */
	protected function oauth_config(): array {
		return array(
			'auth_url'    => 'https://www.facebook.com/v19.0/dialog/oauth',
			'token_url'   => 'https://graph.facebook.com/v19.0/oauth/access_token',
			'scopes'      => array( 'public_profile' ),
			'account_url' => 'https://graph.facebook.com/me?fields=id,name,link',
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function map_account( array $body ): array {
		return array(
			'external_id' => (string) ( $body['id'] ?? '' ),
			'handle'      => (string) ( $body['name'] ?? '' ),
			'profile_url' => (string) ( $body['link'] ?? '' ),
		);
	}
}
