<?php
/**
 * Ambassador repository.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Ambassador;

use Yazan\Rewards\Core\Database\AbstractRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads/writes the `ambassadors` table.
 */
final class AmbassadorRepository extends AbstractRepository {

	/**
	 * @inheritDoc
	 */
	protected function table_name(): string {
		return 'ambassadors';
	}

	/**
	 * Ambassador by WP user id.
	 *
	 * @param int $user_id User id.
	 * @return Ambassador|null
	 */
	public function get_by_user( int $user_id ): ?Ambassador {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d LIMIT 1", $user_id ) );
		return $row ? Ambassador::from_row( $row ) : null;
	}

	/**
	 * Ambassador by id.
	 *
	 * @param int $id Row id.
	 * @return Ambassador|null
	 */
	public function get( int $id ): ?Ambassador {
		$row = $this->find( $id );
		return $row ? Ambassador::from_row( $row ) : null;
	}

	/**
	 * Count of active ambassadors (for analytics).
	 *
	 * @return int
	 */
	public function count_active(): int {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'active'" );
	}

	/**
	 * Create an application row.
	 *
	 * @param array $data user_id, code, status, tier, commission_rate.
	 * @return int
	 */
	public function create( array $data ): int {
		$now = current_time( 'mysql' );
		return $this->insert(
			array(
				'user_id'         => (int) $data['user_id'],
				'code'            => sanitize_text_field( (string) ( $data['code'] ?? '' ) ),
				'status'          => sanitize_key( (string) ( $data['status'] ?? 'pending' ) ),
				'tier'            => sanitize_key( (string) ( $data['tier'] ?? '' ) ),
				'commission_rate' => (float) ( $data['commission_rate'] ?? 0 ),
				'stats'           => wp_json_encode( (array) ( $data['stats'] ?? array() ) ),
				'applied_at'      => $now,
				'approved_at'     => ( 'active' === ( $data['status'] ?? '' ) ) ? $now : '0000-00-00 00:00:00',
			),
			array( '%d', '%s', '%s', '%s', '%f', '%s', '%s', '%s' )
		);
	}

	/**
	 * Update an ambassador's status (and approval time when activating).
	 *
	 * @param int    $id     Row id.
	 * @param string $status New status.
	 * @return void
	 */
	public function set_status( int $id, string $status ): void {
		$data    = array( 'status' => sanitize_key( $status ) );
		$formats = array( '%s' );
		if ( 'active' === $status ) {
			$data['approved_at'] = current_time( 'mysql' );
			$formats[]           = '%s';
		}
		$this->update( $data, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	/**
	 * Persist cached stats.
	 *
	 * @param int   $id    Row id.
	 * @param array $stats Stats.
	 * @return void
	 */
	public function set_stats( int $id, array $stats ): void {
		$this->update(
			array( 'stats' => wp_json_encode( $stats ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}
}
