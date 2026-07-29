<?php
/**
 * Social content verification orchestrator.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Social;

use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Core\Support\Crypto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picks the verification method for a submission:
 *   METHOD 1 (API) — when the network's connector is configured AND the customer has
 *     a verified linked account; the connector reads the post via the API.
 *   METHOD 2 (manual) — otherwise, the six manual checks (URL exists, ownership,
 *     hashtag, mention/link, date, duplicate).
 * Returns a normalised verdict + per-check results either way.
 */
final class SocialVerificationService {

	/**
	 * @param ConnectorRegistry        $registry Connector registry.
	 * @param SocialAccountRepository   $accounts Linked accounts.
	 * @param ManualVerifier            $manual   Manual verifier.
	 * @param Crypto                    $crypto   Token decryption.
	 * @param Settings                  $settings Settings.
	 */
	public function __construct(
		private ConnectorRegistry $registry,
		private SocialAccountRepository $accounts,
		private ManualVerifier $manual,
		private Crypto $crypto,
		private Settings $settings
	) {}

	/**
	 * Verify a submission. `$network` may be '' to auto-detect from the URL.
	 *
	 * @param int    $user_id    Customer.
	 * @param string $network    Network id, or ''.
	 * @param string $url        Post URL.
	 * @param int    $exclude_id Row to exclude from the duplicate check.
	 * @return array{verdict:string,method:string,network:string,checks:array,reasons:array}
	 */
	public function verify( int $user_id, string $network, string $url, int $exclude_id = 0 ): array {
		$connector = '' !== $network ? $this->registry->get( $network ) : $this->registry->for_url( $url );
		$network   = $connector ? $connector->network() : sanitize_key( $network );
		$account   = $connector ? $this->accounts->for_user_platform( $user_id, $connector->network() ) : null;

		$context = array(
			'handle'           => $account ? (string) $account->handle : '',
			'external_id'      => $account ? (string) ( $account->external_id ?? '' ) : '',
			'required_hashtag' => (string) $this->settings->get( 'social.verify.required_hashtag', '' ),
			'required_mention' => (string) $this->settings->get( 'social.verify.required_mention', '' ),
			'window_days'      => (int) $this->settings->get( 'social.verify.window_days', 30 ),
		);

		// METHOD 1 — API verification when the connector is live and the account linked.
		if ( $connector && $connector->is_configured() && $account && ! empty( $account->verified ) ) {
			$api = $connector->verify_content(
				array_merge( $context, array( 'url' => $url ) ),
				$this->tokens( $account )
			);
			if ( is_array( $api ) ) {
				return $this->shape_api( $api, $network );
			}
		}

		// METHOD 2 — manual submission checks.
		$result            = $this->manual->verify( array( 'url' => $url, 'exclude_id' => $exclude_id ), $context );
		$result['network'] = $network;
		return $result;
	}

	/**
	 * Decrypt an account's stored OAuth tokens.
	 *
	 * @param object $account social_accounts row.
	 * @return array{access_token:string,refresh_token:string}
	 */
	private function tokens( object $account ): array {
		return array(
			'access_token'  => $this->crypto->decrypt( (string) ( $account->access_token ?? '' ) ),
			'refresh_token' => $this->crypto->decrypt( (string) ( $account->refresh_token ?? '' ) ),
		);
	}

	/**
	 * Normalise a connector's API verdict.
	 *
	 * @param array  $api     Connector result.
	 * @param string $network Network id.
	 * @return array{verdict:string,method:string,network:string,checks:array,reasons:array}
	 */
	private function shape_api( array $api, string $network ): array {
		$verdict = (string) ( $api['verdict'] ?? '' );
		if ( '' === $verdict ) {
			$checks   = (array) ( $api['checks'] ?? $api );
			$failed   = in_array( false, $checks, true );
			$verdict  = $failed ? 'pending' : 'approved';
		}
		return array(
			'verdict' => in_array( $verdict, array( 'approved', 'pending', 'rejected' ), true ) ? $verdict : 'pending',
			'method'  => 'api',
			'network' => $network,
			'checks'  => (array) ( $api['checks'] ?? $api ),
			'reasons' => (array) ( $api['reasons'] ?? array() ),
		);
	}
}
