<?php
/**
 * Campaign repository.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Campaigns;

use Yazan\Rewards\Core\Database\AbstractRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads/writes the `campaigns` table (time-boxed earning multipliers/bonuses).
 */
final class CampaignRepository extends AbstractRepository {

	/**
	 * @inheritDoc
	 */
	protected function table_name(): string {
		return 'campaigns';
	}

	/**
	 * Active campaigns whose time window includes now, highest priority first.
	 *
	 * @return object[]
	 */
	public function active_now(): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		$now   = current_time( 'mysql' );
		$zero  = '0000-00-00 00:00:00';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE active = 1
				   AND ( starts_at = %s OR starts_at <= %s )
				   AND ( ends_at = %s OR ends_at >= %s )
				 ORDER BY priority DESC, id ASC",
				$zero,
				$now,
				$zero,
				$now
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Create a campaign.
	 *
	 * @param array $data name, type, rules(array), multiplier, priority, active,
	 *                    starts_at, ends_at.
	 * @return int
	 */
	public function create( array $data ): int {
		return $this->insert(
			array(
				'name'       => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
				'type'       => sanitize_key( (string) ( $data['type'] ?? 'multiplier' ) ),
				'rules'      => wp_json_encode( (array) ( $data['rules'] ?? array() ) ),
				'multiplier' => (float) ( $data['multiplier'] ?? 1 ),
				'priority'   => (int) ( $data['priority'] ?? 10 ),
				'active'     => ! empty( $data['active'] ) ? 1 : 0,
				'starts_at'  => $this->dt( $data['starts_at'] ?? null ),
				'ends_at'    => $this->dt( $data['ends_at'] ?? null ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%f', '%d', '%d', '%s', '%s', '%s' )
		);
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
}
