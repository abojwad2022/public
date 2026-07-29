<?php
/**
 * Rule repository.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Rules;

use Yazan\Rewards\Core\Database\AbstractRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads/writes the `rules` table. All SQL is parameterised; only the trusted
 * table name (from Database::table) is interpolated.
 */
final class RuleRepository extends AbstractRepository {

	/**
	 * @inheritDoc
	 */
	protected function table_name(): string {
		return 'rules';
	}

	/**
	 * Active rules for an event, ordered by priority.
	 *
	 * @param string $event Event key.
	 * @return Rule[]
	 */
	public function active_for_event( string $event ): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE event = %s AND active = 1 ORDER BY priority ASC, id ASC", $event )
		);
		return array_map( array( Rule::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Load a single rule.
	 *
	 * @param int $id Rule id.
	 * @return Rule|null
	 */
	public function get( int $id ): ?Rule {
		$row = $this->find( $id );
		return $row ? Rule::from_row( $row ) : null;
	}

	/**
	 * All rules (admin listing), newest first.
	 *
	 * @return Rule[]
	 */
	public function all(): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC" );
		return array_map( array( Rule::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Count rules bound to an event (used to decide whether to seed defaults).
	 *
	 * @param string $event Event key.
	 * @return int
	 */
	public function count_for_event( string $event ): int {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event = %s", $event ) );
	}

	/**
	 * Create a rule. Returns the new id.
	 *
	 * @param array $data name, event, conditions(array), award_type, award_value,
	 *                    priority, per_user_cap, active, starts_at, ends_at.
	 * @return int
	 */
	public function create( array $data ): int {
		return $this->insert(
			array(
				'name'         => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
				'event'        => sanitize_key( (string) ( $data['event'] ?? '' ) ),
				'conditions'   => wp_json_encode( (array) ( $data['conditions'] ?? array() ) ),
				'actions'      => wp_json_encode( array_values( (array) ( $data['actions'] ?? array() ) ) ),
				'award_type'   => sanitize_key( (string) ( $data['award_type'] ?? 'fixed' ) ),
				'award_value'  => (float) ( $data['award_value'] ?? 0 ),
				'priority'     => (int) ( $data['priority'] ?? 10 ),
				'per_user_cap' => (int) ( $data['per_user_cap'] ?? 0 ),
				'active'       => ! empty( $data['active'] ) ? 1 : 0,
				'starts_at'    => $this->nullable_datetime( $data['starts_at'] ?? null ),
				'ends_at'      => $this->nullable_datetime( $data['ends_at'] ?? null ),
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%f', '%d', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Update a rule. Only the provided keys are changed.
	 *
	 * @param int   $id   Rule id.
	 * @param array $data Any of: name, event, conditions, actions, award_type,
	 *                    award_value, priority, per_user_cap, active, starts_at, ends_at.
	 * @return bool
	 */
	public function update_rule( int $id, array $data ): bool {
		$fields  = array();
		$formats = array();

		$map = array(
			'name'         => '%s',
			'event'        => '%s',
			'award_type'   => '%s',
			'award_value'  => '%f',
			'priority'     => '%d',
			'per_user_cap' => '%d',
		);
		foreach ( $map as $key => $fmt ) {
			if ( array_key_exists( $key, $data ) ) {
				$value           = ( '%d' === $fmt ) ? (int) $data[ $key ] : ( ( '%f' === $fmt ) ? (float) $data[ $key ] : ( in_array( $key, array( 'event' ), true ) ? sanitize_key( (string) $data[ $key ] ) : sanitize_text_field( (string) $data[ $key ] ) ) );
				$fields[ $key ]  = $value;
				$formats[]       = $fmt;
			}
		}
		if ( array_key_exists( 'conditions', $data ) ) {
			$fields['conditions'] = wp_json_encode( (array) $data['conditions'] );
			$formats[]            = '%s';
		}
		if ( array_key_exists( 'actions', $data ) ) {
			$fields['actions'] = wp_json_encode( array_values( (array) $data['actions'] ) );
			$formats[]         = '%s';
		}
		if ( array_key_exists( 'active', $data ) ) {
			$fields['active'] = ! empty( $data['active'] ) ? 1 : 0;
			$formats[]        = '%d';
		}
		foreach ( array( 'starts_at', 'ends_at' ) as $dt ) {
			if ( array_key_exists( $dt, $data ) ) {
				$fields[ $dt ] = $this->nullable_datetime( $data[ $dt ] );
				$formats[]     = '%s';
			}
		}

		if ( empty( $fields ) ) {
			return false;
		}
		return $this->update( $fields, array( 'id' => $id ), $formats, array( '%d' ) ) >= 0;
	}

	/**
	 * Delete a rule.
	 *
	 * @param int $id Rule id.
	 * @return void
	 */
	public function delete_rule( int $id ): void {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Normalise an optional datetime to a string or the zero date.
	 *
	 * @param mixed $value Raw.
	 * @return string
	 */
	private function nullable_datetime( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		return '' === $value ? '0000-00-00 00:00:00' : sanitize_text_field( $value );
	}
}
