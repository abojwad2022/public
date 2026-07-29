<?php
/**
 * Achievement definitions + per-user progress repository.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Achievement;

use Yazan\Rewards\Core\Database\AbstractRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads/writes the `achievements` (definitions) and `user_achievements`
 * (progress/unlocks) tables.
 */
final class AchievementRepository extends AbstractRepository {

	/**
	 * @inheritDoc
	 */
	protected function table_name(): string {
		return 'achievements';
	}

	/**
	 * Full user_achievements table name.
	 *
	 * @return string
	 */
	private function progress_table(): string {
		return $this->db->table( 'user_achievements' );
	}

	/* -------------------------------------------------------------------- */
	/* Definitions                                                           */
	/* -------------------------------------------------------------------- */

	/**
	 * Achievements whose criteria react to a given event.
	 *
	 * @param string $event Event key stored in criteria.event.
	 * @return object[]
	 */
	public function for_event( string $event ): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// The criteria JSON is small; filter in PHP after a cheap active scan.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table}" );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$criteria = json_decode( (string) $row->criteria, true );
			if ( is_array( $criteria ) && ( $criteria['event'] ?? '' ) === $event ) {
				$row->criteria_decoded = $criteria;
				$out[]                 = $row;
			}
		}
		return $out;
	}

	/**
	 * All achievement definitions.
	 *
	 * @return object[]
	 */
	public function all(): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * A definition by its unique key.
	 *
	 * @param string $key Achievement key (akey).
	 * @return object|null
	 */
	public function by_key( string $key ): ?object {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE akey = %s LIMIT 1", sanitize_key( $key ) ) );
		return $row ?: null;
	}

	/**
	 * Count of definitions.
	 *
	 * @return int
	 */
	public function count_all(): int {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Create a definition.
	 *
	 * @param array $data key, name, description, icon, criteria(array), points_award, badge, tier.
	 * @return int
	 */
	public function create_definition( array $data ): int {
		return $this->insert(
			array(
				'akey'         => sanitize_key( (string) ( $data['key'] ?? '' ) ),
				'name'         => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
				'description'  => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
				'icon'         => sanitize_text_field( (string) ( $data['icon'] ?? '' ) ),
				'criteria'     => wp_json_encode( (array) ( $data['criteria'] ?? array() ) ),
				'points_award' => max( 0, (int) ( $data['points_award'] ?? 0 ) ),
				'badge'        => sanitize_text_field( (string) ( $data['badge'] ?? '' ) ),
				'tier'         => sanitize_key( (string) ( $data['tier'] ?? '' ) ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/* -------------------------------------------------------------------- */
	/* Progress                                                              */
	/* -------------------------------------------------------------------- */

	/**
	 * A user's progress row for an achievement, or null.
	 *
	 * @param int $user_id        User id.
	 * @param int $achievement_id Achievement id.
	 * @return object|null
	 */
	public function progress( int $user_id, int $achievement_id ): ?object {
		$wpdb  = $this->db->wpdb();
		$table = $this->progress_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND achievement_id = %d", $user_id, $achievement_id ) );
		return $row ?: null;
	}

	/**
	 * Increment (or create) progress and return the new progress value.
	 *
	 * @param int $user_id        User id.
	 * @param int $achievement_id Achievement id.
	 * @param int $by             Increment.
	 * @return int
	 */
	public function bump_progress( int $user_id, int $achievement_id, int $by = 1 ): int {
		$wpdb    = $this->db->wpdb();
		$table   = $this->progress_table();
		$current = $this->progress( $user_id, $achievement_id );

		if ( $current ) {
			$new = (int) $current->progress + $by;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, array( 'progress' => $new ), array( 'id' => (int) $current->id ), array( '%d' ), array( '%d' ) );
			return $new;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$table,
			array( 'user_id' => $user_id, 'achievement_id' => $achievement_id, 'progress' => $by, 'unlocked_at' => '0000-00-00 00:00:00' ),
			array( '%d', '%d', '%d', '%s' )
		);
		return $by;
	}

	/**
	 * Mark an achievement unlocked for a user (idempotent).
	 *
	 * @param int $user_id        User id.
	 * @param int $achievement_id Achievement id.
	 * @return bool True if this call performed the unlock (false if already unlocked).
	 */
	public function unlock( int $user_id, int $achievement_id ): bool {
		$wpdb  = $this->db->wpdb();
		$table = $this->progress_table();
		$row   = $this->progress( $user_id, $achievement_id );

		if ( $row && '0000-00-00 00:00:00' !== $row->unlocked_at ) {
			return false; // Already unlocked.
		}
		if ( $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, array( 'unlocked_at' => current_time( 'mysql' ) ), array( 'id' => (int) $row->id ), array( '%s' ), array( '%d' ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$table,
				array( 'user_id' => $user_id, 'achievement_id' => $achievement_id, 'progress' => 1, 'unlocked_at' => current_time( 'mysql' ) ),
				array( '%d', '%d', '%d', '%s' )
			);
		}
		return true;
	}

	/**
	 * A user's unlocked achievement ids.
	 *
	 * @param int $user_id User id.
	 * @return int[]
	 */
	public function unlocked_ids( int $user_id ): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->progress_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT achievement_id FROM {$table} WHERE user_id = %d AND unlocked_at <> '0000-00-00 00:00:00'", $user_id ) );
		return array_map( 'intval', (array) $ids );
	}
}
