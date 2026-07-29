<?php
/**
 * Connected social accounts repository.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Social;

use Yazan\Rewards\Core\Database\AbstractRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads/writes the `social_accounts` table — a customer's connected social
 * handles per platform. One row per (user, platform). This backs the future real
 * OAuth adapter path; in v1 an account is a self-declared, admin-verifiable
 * handle used to attribute UGC.
 */
final class SocialAccountRepository extends AbstractRepository {

	/**
	 * @inheritDoc
	 */
	protected function table_name(): string {
		return 'social_accounts';
	}

	/**
	 * Connect (upsert) a user's account for a platform.
	 *
	 * @param int    $user_id  User id.
	 * @param string $platform Platform key.
	 * @param string $handle   Account handle/username.
	 * @param string $url      Profile URL.
	 * @param array  $meta     Extra (token refs, ids) — never store raw secrets.
	 * @return int Row id.
	 */
	public function connect( int $user_id, string $platform, string $handle, string $url = '', array $meta = array() ): int {
		$platform = sanitize_key( $platform );
		$existing = $this->for_user_platform( $user_id, $platform );

		$data = array(
			'handle'       => sanitize_text_field( $handle ),
			'profile_url'  => esc_url_raw( $url ),
			'status'       => 'connected',
			'meta'         => wp_json_encode( $meta ),
			'connected_at' => current_time( 'mysql' ),
		);

		if ( $existing ) {
			$this->update(
				$data,
				array( 'id' => (int) $existing->id ),
				array( '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			return (int) $existing->id;
		}

		return $this->insert(
			array_merge(
				array(
					'user_id'    => $user_id,
					'platform'   => $platform,
					'verified'   => 0,
					'created_at' => current_time( 'mysql' ),
				),
				$data
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Link an account established via OAuth (verified) with its encrypted tokens.
	 *
	 * @param int    $user_id  User id.
	 * @param string $platform Platform key.
	 * @param array  $data     handle, external_id, profile_url, access_token,
	 *                         refresh_token, token_expires_at, scope (tokens already
	 *                         encrypted by the caller).
	 * @return int Row id.
	 */
	public function connect_oauth( int $user_id, string $platform, array $data ): int {
		$platform = sanitize_key( $platform );
		$existing = $this->for_user_platform( $user_id, $platform );

		$fields = array(
			'handle'           => sanitize_text_field( (string) ( $data['handle'] ?? '' ) ),
			'external_id'      => sanitize_text_field( (string) ( $data['external_id'] ?? '' ) ),
			'profile_url'      => esc_url_raw( (string) ( $data['profile_url'] ?? '' ) ),
			'status'           => 'connected',
			'verified'         => 1,
			'access_token'     => (string) ( $data['access_token'] ?? '' ),
			'refresh_token'    => (string) ( $data['refresh_token'] ?? '' ),
			'token_expires_at' => (string) ( $data['token_expires_at'] ?? '0000-00-00 00:00:00' ),
			'scope'            => sanitize_text_field( (string) ( $data['scope'] ?? '' ) ),
			'connected_at'     => current_time( 'mysql' ),
		);
		$formats = array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' );

		if ( $existing ) {
			$this->update( $fields, array( 'id' => (int) $existing->id ), $formats, array( '%d' ) );
			return (int) $existing->id;
		}
		return $this->insert(
			array_merge( array( 'user_id' => $user_id, 'platform' => $platform, 'created_at' => current_time( 'mysql' ) ), $fields ),
			array_merge( array( '%d', '%s', '%s' ), $formats )
		);
	}

	/**
	 * Self-declare a handle (manual link, unverified until proven by a matching post
	 * or admin). Does not touch tokens.
	 *
	 * @param int    $user_id  User id.
	 * @param string $platform Platform.
	 * @param string $handle   Handle.
	 * @param string $url      Profile URL.
	 * @return int Row id.
	 */
	public function declare_handle( int $user_id, string $platform, string $handle, string $url = '' ): int {
		return $this->connect( $user_id, $platform, $handle, $url );
	}

	/**
	 * A user's account for a platform, or null.
	 *
	 * @param int    $user_id  User id.
	 * @param string $platform Platform key.
	 * @return object|null
	 */
	public function for_user_platform( int $user_id, string $platform ): ?object {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND platform = %s LIMIT 1", $user_id, sanitize_key( $platform ) ) );
		return $row ?: null;
	}

	/**
	 * All of a user's connected accounts.
	 *
	 * @param int $user_id User id.
	 * @return object[]
	 */
	public function for_user( int $user_id ): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND status = 'connected' ORDER BY platform ASC", $user_id ) );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Disconnect an account.
	 *
	 * @param int    $user_id  User id.
	 * @param string $platform Platform.
	 * @return void
	 */
	public function disconnect( int $user_id, string $platform ): void {
		$account = $this->for_user_platform( $user_id, $platform );
		if ( $account ) {
			$this->update( array( 'status' => 'disconnected' ), array( 'id' => (int) $account->id ), array( '%s' ), array( '%d' ) );
		}
	}

	/**
	 * Mark an account verified (admin/adapter).
	 *
	 * @param int $id Row id.
	 * @return void
	 */
	public function mark_verified( int $id ): void {
		$this->update( array( 'verified' => 1 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
	}
}
