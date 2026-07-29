<?php
/**
 * Pinterest connector.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Social\Connectors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pinterest connector (v5 API).
 */
final class PinterestConnector extends AbstractConnector {

	/**
	 * @inheritDoc
	 */
	public function network(): string {
		return 'pinterest';
	}

	/**
	 * @inheritDoc
	 */
	public function label(): string {
		return __( 'Pinterest', 'yazan-rewards' );
	}

	/**
	 * @inheritDoc
	 */
	public function hosts(): array {
		return array( 'pinterest.com', 'pin.it' );
	}

	/**
	 * @inheritDoc
	 */
	protected function oauth_config(): array {
		return array(
			'auth_url'    => 'https://www.pinterest.com/oauth/',
			'token_url'   => 'https://api.pinterest.com/v5/oauth/token',
			'scopes'      => array( 'user_accounts:read', 'pins:read' ),
			'account_url' => 'https://api.pinterest.com/v5/user_account',
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function map_account( array $body ): array {
		return array(
			'external_id' => (string) ( $body['id'] ?? '' ),
			'handle'      => (string) ( $body['username'] ?? '' ),
			'profile_url' => '' !== (string) ( $body['username'] ?? '' ) ? 'https://www.pinterest.com/' . $body['username'] : '',
		);
	}
}
