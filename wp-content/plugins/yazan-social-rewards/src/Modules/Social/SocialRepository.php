<?php
/**
 * Social actions repository.
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
 * Reads/writes the `social_actions` table (share intents + UGC submissions).
 */
final class SocialRepository extends AbstractRepository {

	/**
	 * @inheritDoc
	 */
	protected function table_name(): string {
		return 'social_actions';
	}

	/**
	 * Create a social action row.
	 *
	 * @param array $data user_id, platform, action_type, media_type, target_url,
	 *                    submission_url, status, verify_method, points_award,
	 *                    external_id, checks (array).
	 * @return int
	 */
	public function create( array $data ): int {
		$submission = esc_url_raw( (string) ( $data['submission_url'] ?? '' ) );
		return $this->insert(
			array(
				'user_id'        => (int) $data['user_id'],
				'platform'       => sanitize_key( (string) ( $data['platform'] ?? '' ) ),
				'action_type'    => sanitize_key( (string) ( $data['action_type'] ?? '' ) ),
				'media_type'     => sanitize_key( (string) ( $data['media_type'] ?? '' ) ),
				'target_url'     => esc_url_raw( (string) ( $data['target_url'] ?? '' ) ),
				'submission_url' => $submission,
				'url_hash'       => '' !== $submission ? $this->url_hash( $submission ) : '',
				'status'         => sanitize_key( (string) ( $data['status'] ?? 'pending' ) ),
				'verify_method'  => sanitize_key( (string) ( $data['verify_method'] ?? '' ) ),
				'points_award'   => max( 0, (int) ( $data['points_award'] ?? 0 ) ),
				'external_id'    => sanitize_text_field( (string) ( $data['external_id'] ?? '' ) ),
				'checks'         => isset( $data['checks'] ) ? (string) wp_json_encode( $data['checks'] ) : null,
				'reviewed_by'    => 0,
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * A stable hash of a normalised submission URL, for duplicate-content detection.
	 *
	 * @param string $url URL.
	 * @return string 32-char md5.
	 */
	public function url_hash( string $url ): string {
		$parts = wp_parse_url( strtolower( trim( $url ) ) );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		$host = preg_replace( '/^www\./', '', (string) $parts['host'] );
		$path = rtrim( (string) ( $parts['path'] ?? '' ), '/' );
		$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		return md5( $host . $path . $query );
	}

	/**
	 * Whether a submission URL (by hash) was already submitted (pending/approved).
	 *
	 * @param string $url_hash   URL hash.
	 * @param int    $exclude_id Row id to exclude.
	 * @return bool
	 */
	public function has_url_hash( string $url_hash, int $exclude_id = 0 ): bool {
		if ( '' === $url_hash ) {
			return false;
		}
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE url_hash = %s AND id <> %d AND status IN ('pending','approved') LIMIT 1",
				$url_hash,
				$exclude_id
			)
		);
		return $id > 0;
	}

	/**
	 * The user id that first submitted a URL (by hash), or 0. Used to tell a resubmit
	 * (same user) apart from a repost of someone else's content (different user).
	 *
	 * @param string $url_hash   URL hash.
	 * @param int    $exclude_id Row to exclude.
	 * @return int
	 */
	public function owner_of_url_hash( string $url_hash, int $exclude_id = 0 ): int {
		if ( '' === $url_hash ) {
			return 0;
		}
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$table} WHERE url_hash = %s AND id <> %d AND status IN ('pending','approved') ORDER BY id ASC LIMIT 1",
				$url_hash,
				$exclude_id
			)
		);
	}

	/**
	 * Store verification check results + the method used.
	 *
	 * @param int    $id     Action id.
	 * @param array  $checks Check results.
	 * @param string $method api|manual.
	 * @return void
	 */
	public function save_verification( int $id, array $checks, string $method ): void {
		$this->update(
			array( 'checks' => (string) wp_json_encode( $checks ), 'verify_method' => sanitize_key( $method ) ),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Store engagement metrics for an action.
	 *
	 * @param int   $id      Action id.
	 * @param array $metrics Metrics map.
	 * @return void
	 */
	public function save_metrics( int $id, array $metrics ): void {
		$this->update(
			array( 'metrics' => (string) wp_json_encode( $metrics ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Load an action by id.
	 *
	 * @param int $id Id.
	 * @return object|null
	 */
	public function get( int $id ): ?object {
		return $this->find( $id );
	}

	/**
	 * Set an action's status + reviewer.
	 *
	 * @param int $id          Id.
	 * @param string $status   Status.
	 * @param int $reviewed_by Reviewer user id.
	 * @return void
	 */
	public function set_status( int $id, string $status, int $reviewed_by = 0 ): void {
		$this->update(
			array( 'status' => sanitize_key( $status ), 'reviewed_by' => $reviewed_by, 'reviewed_at' => current_time( 'mysql' ) ),
			array( 'id' => $id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Count a user's actions of a type since a datetime (share throttle).
	 *
	 * @param int    $user_id     User id.
	 * @param string $action_type Type.
	 * @param string $since       MySQL datetime.
	 * @return int
	 */
	public function count_since( int $user_id, string $action_type, string $since ): int {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND action_type = %s AND created_at >= %s", $user_id, sanitize_key( $action_type ), $since )
		);
	}

	/**
	 * A user's actions (newest first).
	 *
	 * @param int $user_id User id.
	 * @param int $limit   Rows.
	 * @return object[]
	 */
	public function for_user( int $user_id, int $limit = 50 ): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		$limit = max( 1, min( 200, $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT %d", $user_id, $limit ) );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Pending actions awaiting review (admin).
	 *
	 * @param int $limit Rows.
	 * @return object[]
	 */
	public function pending( int $limit = 50 ): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		$limit = max( 1, min( 200, $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'pending' ORDER BY id ASC LIMIT %d", $limit ) );
		return is_array( $rows ) ? $rows : array();
	}
}
