<?php
/**
 * LinkedIn connector.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Social\Connectors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LinkedIn connector (OpenID Connect).
 */
final class LinkedInConnector extends AbstractConnector {

	/**
	 * @inheritDoc
	 */
	public function network(): string {
		return 'linkedin';
	}

	/**
	 * @inheritDoc
	 */
	public function label(): string {
		return __( 'LinkedIn', 'yazan-rewards' );
	}

	/**
	 * @inheritDoc
	 */
	public function hosts(): array {
		return array( 'linkedin.com', 'lnkd.in' );
	}

	/**
	 * @inheritDoc
	 */
	protected function oauth_config(): array {
		return array(
			'auth_url'    => 'https://www.linkedin.com/oauth/v2/authorization',
			'token_url'   => 'https://www.linkedin.com/oauth/v2/accessToken',
			'scopes'      => array( 'openid', 'profile' ),
			'account_url' => 'https://api.linkedin.com/v2/userinfo',
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function map_account( array $body ): array {
		return array(
			'external_id' => (string) ( $body['sub'] ?? '' ),
			'handle'      => (string) ( $body['name'] ?? '' ),
			'profile_url' => '',
		);
	}
}
