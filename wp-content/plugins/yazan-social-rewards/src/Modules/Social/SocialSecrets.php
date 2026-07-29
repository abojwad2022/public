<?php
/**
 * Per-network OAuth app credentials store.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Social;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores each social network's OAuth app credentials (client id + secret). Values
 * come from a `wp-config.php` constant when defined (e.g. YZRW_INSTAGRAM_CLIENT_ID /
 * YZRW_INSTAGRAM_CLIENT_SECRET), otherwise from a non-autoloaded option. The secret
 * is write-only from the outside: {@see self::status()} exposes only a `last4`, never
 * the raw value, so it is never serialised into a REST/admin response. Mirrors
 * yazan-core's Yazan_AI_Secrets pattern.
 */
final class SocialSecrets {

	/** Non-autoloaded option holding `{network: {client_id, client_secret}}`. */
	private const OPTION = 'yazan_rewards_social_secrets';

	/**
	 * The effective credentials for a network (constant overrides stored value).
	 *
	 * @param string $network Network id.
	 * @return array{client_id:string,client_secret:string}
	 */
	public function get( string $network ): array {
		$network = sanitize_key( $network );
		$stored  = $this->all()[ $network ] ?? array();

		$id     = $this->constant( $network, 'CLIENT_ID' );
		$secret = $this->constant( $network, 'CLIENT_SECRET' );

		return array(
			'client_id'     => '' !== $id ? $id : (string) ( $stored['client_id'] ?? '' ),
			'client_secret' => '' !== $secret ? $secret : (string) ( $stored['client_secret'] ?? '' ),
		);
	}

	/**
	 * Persist stored credentials for a network. Empty strings clear a field. A field
	 * backed by a constant cannot be overwritten from here (the constant always wins).
	 *
	 * @param string $network       Network id.
	 * @param string $client_id     Client id.
	 * @param string $client_secret Client secret.
	 * @return void
	 */
	public function set( string $network, string $client_id, string $client_secret ): void {
		$network = sanitize_key( $network );
		$all     = $this->all();

		$all[ $network ] = array(
			'client_id'     => sanitize_text_field( $client_id ),
			'client_secret' => sanitize_text_field( $client_secret ),
		);
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Whether a network has both an id and a secret configured.
	 *
	 * @param string $network Network id.
	 * @return bool
	 */
	public function has( string $network ): bool {
		$creds = $this->get( $network );
		return '' !== $creds['client_id'] && '' !== $creds['client_secret'];
	}

	/**
	 * Safe, non-secret status for the admin UI.
	 *
	 * @param string $network Network id.
	 * @return array{configured:bool,id_last4:string,secret_last4:string,source:string}
	 */
	public function status( string $network ): array {
		$network = sanitize_key( $network );
		$creds   = $this->get( $network );
		$source  = ( '' !== $this->constant( $network, 'CLIENT_ID' ) || '' !== $this->constant( $network, 'CLIENT_SECRET' ) )
			? 'constant'
			: ( isset( $this->all()[ $network ] ) ? 'option' : 'none' );

		return array(
			'configured'   => '' !== $creds['client_id'] && '' !== $creds['client_secret'],
			'id_last4'     => $this->last4( $creds['client_id'] ),
			'secret_last4' => $this->last4( $creds['client_secret'] ),
			'source'       => $source,
		);
	}

	/**
	 * Remove a network's stored credentials.
	 *
	 * @param string $network Network id.
	 * @return void
	 */
	public function forget( string $network ): void {
		$network = sanitize_key( $network );
		$all     = $this->all();
		unset( $all[ $network ] );
		update_option( self::OPTION, $all, false );
	}

	/**
	 * All stored credentials.
	 *
	 * @return array<string,array<string,string>>
	 */
	private function all(): array {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Read a network constant override, e.g. YZRW_INSTAGRAM_CLIENT_SECRET.
	 *
	 * @param string $network Network id.
	 * @param string $suffix  CLIENT_ID | CLIENT_SECRET.
	 * @return string
	 */
	private function constant( string $network, string $suffix ): string {
		$name = 'YZRW_' . strtoupper( preg_replace( '/[^a-z0-9]/', '', $network ) ) . '_' . $suffix;
		return ( defined( $name ) && '' !== (string) constant( $name ) ) ? (string) constant( $name ) : '';
	}

	/**
	 * Last 4 chars of a secret (or '' when empty).
	 *
	 * @param string $value Secret value.
	 * @return string
	 */
	private function last4( string $value ): string {
		$len = strlen( $value );
		return $len > 0 ? substr( $value, max( 0, $len - 4 ) ) : '';
	}
}
