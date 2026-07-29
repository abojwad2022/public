<?php
/**
 * Instagram connector.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Social\Connectors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Instagram (Basic Display / Graph) connector. OAuth activates once the app's
 * client id/secret are configured; otherwise submissions use the manual method.
 */
final class InstagramConnector extends AbstractConnector {

	/**
	 * @inheritDoc
	 */
	public function network(): string {
		return 'instagram';
	}

	/**
	 * @inheritDoc
	 */
	public function label(): string {
		return __( 'Instagram', 'yazan-rewards' );
	}

	/**
	 * @inheritDoc
	 */
	public function hosts(): array {
		return array( 'instagram.com', 'instagr.am' );
	}

	/**
	 * @inheritDoc
	 */
	protected function oauth_config(): array {
		return array(
			'auth_url'    => 'https://api.instagram.com/oauth/authorize',
			'token_url'   => 'https://api.instagram.com/oauth/access_token',
			'scopes'      => array( 'user_profile', 'user_media' ),
			'account_url' => 'https://graph.instagram.com/me?fields=id,username',
		);
	}
}
