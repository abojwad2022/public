<?php
/**
 * Reward catalog repository.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Rewards;

use Yazan\Rewards\Core\Database\AbstractRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads/writes the `rewards` catalog table.
 */
final class RewardRepository extends AbstractRepository {

	/**
	 * @inheritDoc
	 */
	protected function table_name(): string {
		return 'rewards';
	}

	/**
	 * Load a reward by id.
	 *
	 * @param int $id Reward id.
	 * @return Reward|null
	 */
	public function get( int $id ): ?Reward {
		$row = $this->find( $id );
		return $row ? Reward::from_row( $row ) : null;
	}

	/**
	 * Active rewards for the catalog, ordered for display.
	 *
	 * @return Reward[]
	 */
	public function active(): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE active = 1 ORDER BY sort ASC, cost_points ASC, id ASC" );
		return array_map( array( Reward::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Total catalog rows (used to decide whether to seed).
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
	 * Create a reward. Returns the new id.
	 *
	 * @param array $data type, title, description, cost_points, value(array),
	 *                    stock, tier_min, active, sort.
	 * @return int
	 */
	public function create( array $data ): int {
		return $this->insert(
			array(
				'type'           => sanitize_key( (string) ( $data['type'] ?? 'credit' ) ),
				'title'          => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
				'description'    => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
				'cost_points'    => max( 0, (int) ( $data['cost_points'] ?? 0 ) ),
				'value'          => wp_json_encode( (array) ( $data['value'] ?? array() ) ),
				'stock'          => (int) ( $data['stock'] ?? -1 ),
				'tier_min'       => (int) ( $data['tier_min'] ?? 0 ),
				'active'         => ! empty( $data['active'] ) ? 1 : 0,
				'sort'           => (int) ( $data['sort'] ?? 0 ),
				'available_from' => $this->dt( $data['available_from'] ?? null ),
				'available_to'   => $this->dt( $data['available_to'] ?? null ),
				'per_user_limit' => max( 0, (int) ( $data['per_user_limit'] ?? 0 ) ),
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Update a reward (only provided keys change).
	 *
	 * @param int   $id   Reward id.
	 * @param array $data Any reward fields.
	 * @return bool
	 */
	public function update_reward( int $id, array $data ): bool {
		$fields  = array();
		$formats = array();
		$str = array( 'type', 'title', 'description' );
		$int = array( 'cost_points', 'stock', 'tier_min', 'sort', 'per_user_limit' );
		foreach ( $str as $k ) {
			if ( array_key_exists( $k, $data ) ) {
				$fields[ $k ] = ( 'type' === $k ) ? sanitize_key( (string) $data[ $k ] ) : ( 'description' === $k ? sanitize_textarea_field( (string) $data[ $k ] ) : sanitize_text_field( (string) $data[ $k ] ) );
				$formats[]    = '%s';
			}
		}
		foreach ( $int as $k ) {
			if ( array_key_exists( $k, $data ) ) {
				$fields[ $k ] = (int) $data[ $k ];
				$formats[]    = '%d';
			}
		}
		if ( array_key_exists( 'value', $data ) ) {
			$fields['value'] = wp_json_encode( (array) $data['value'] );
			$formats[]       = '%s';
		}
		if ( array_key_exists( 'active', $data ) ) {
			$fields['active'] = ! empty( $data['active'] ) ? 1 : 0;
			$formats[]        = '%d';
		}
		foreach ( array( 'available_from', 'available_to' ) as $dt ) {
			if ( array_key_exists( $dt, $data ) ) {
				$fields[ $dt ] = $this->dt( $data[ $dt ] );
				$formats[]     = '%s';
			}
		}
		if ( empty( $fields ) ) {
			return false;
		}
		return $this->update( $fields, array( 'id' => $id ), $formats, array( '%d' ) ) >= 0;
	}

	/**
	 * Delete a reward.
	 *
	 * @param int $id Reward id.
	 * @return void
	 */
	public function delete_reward( int $id ): void {
		$wpdb = $this->db->wpdb();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Normalise an optional datetime.
	 *
	 * @param mixed $v Raw.
	 * @return string
	 */
	private function dt( $v ): string {
		$v = is_string( $v ) ? trim( $v ) : '';
		return '' === $v ? '0000-00-00 00:00:00' : sanitize_text_field( $v );
	}

	/**
	 * Atomically decrement finite stock by one, only if stock is still positive.
	 * Returns true when a unit was reserved (or the reward is unlimited).
	 *
	 * @param int $reward_id Reward id.
	 * @return bool
	 */
	public function reserve_stock( int $reward_id ): bool {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Unlimited (stock < 0) always succeeds without a write.
		$is_unlimited = (int) $wpdb->get_var( $wpdb->prepare( "SELECT stock < 0 FROM {$table} WHERE id = %d", $reward_id ) );
		if ( 1 === $is_unlimited ) {
			return true;
		}
		$affected = $wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET stock = stock - 1 WHERE id = %d AND stock > 0", $reward_id )
		);
		// phpcs:enable
		return $affected > 0;
	}

	/**
	 * Return a reserved unit to stock (compensating action on a failed delivery).
	 *
	 * @param int $reward_id Reward id.
	 * @return void
	 */
	public function release_stock( int $reward_id ): void {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET stock = stock + 1 WHERE id = %d AND stock >= 0", $reward_id ) );
	}
}
