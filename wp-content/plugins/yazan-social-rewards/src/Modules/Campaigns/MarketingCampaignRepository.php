<?php
/**
 * Marketing campaign repository.
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
 * Reads/writes the `marketing_campaigns` table.
 */
final class MarketingCampaignRepository extends AbstractRepository {

	/**
	 * @inheritDoc
	 */
	protected function table_name(): string {
		return 'marketing_campaigns';
	}

	/**
	 * Load a campaign.
	 *
	 * @param int $id Campaign id.
	 * @return Campaign|null
	 */
	public function get( int $id ): ?Campaign {
		$row = $this->find( $id );
		return $row ? Campaign::from_row( $row ) : null;
	}

	/**
	 * All campaigns (admin), newest first.
	 *
	 * @return Campaign[]
	 */
	public function all(): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC" );
		return array_map( array( Campaign::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Active campaigns (for the customer catalog).
	 *
	 * @return Campaign[]
	 */
	public function active(): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'active' ORDER BY id DESC" );
		return array_map( array( Campaign::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Create a campaign. Returns the new id.
	 *
	 * @param array $data title, description, media(array), target(array),
	 *                    reward_amount, status, starts_at, ends_at.
	 * @return int
	 */
	public function create( array $data ): int {
		$now = current_time( 'mysql' );
		return $this->insert(
			array(
				'title'         => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
				'description'   => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
				'media'         => wp_json_encode( (array) ( $data['media'] ?? array() ) ),
				'target'        => wp_json_encode( (array) ( $data['target'] ?? array( 'type' => 'all', 'values' => array() ) ) ),
				'reward_amount' => max( 0, (int) ( $data['reward_amount'] ?? 0 ) ),
				'status'        => sanitize_key( (string) ( $data['status'] ?? Campaign::STATUS_DRAFT ) ),
				'starts_at'     => $this->dt( $data['starts_at'] ?? null ),
				'ends_at'       => $this->dt( $data['ends_at'] ?? null ),
				'created_by'    => get_current_user_id(),
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Update a campaign (only provided keys change).
	 *
	 * @param int   $id   Campaign id.
	 * @param array $data Any campaign fields.
	 * @return bool
	 */
	public function update_campaign( int $id, array $data ): bool {
		$fields  = array( 'updated_at' => current_time( 'mysql' ) );
		$formats = array( '%s' );

		if ( array_key_exists( 'title', $data ) ) {
			$fields['title'] = sanitize_text_field( (string) $data['title'] );
			$formats[]       = '%s';
		}
		if ( array_key_exists( 'description', $data ) ) {
			$fields['description'] = sanitize_textarea_field( (string) $data['description'] );
			$formats[]             = '%s';
		}
		foreach ( array( 'media', 'target' ) as $json ) {
			if ( array_key_exists( $json, $data ) ) {
				$fields[ $json ] = wp_json_encode( (array) $data[ $json ] );
				$formats[]       = '%s';
			}
		}
		if ( array_key_exists( 'reward_amount', $data ) ) {
			$fields['reward_amount'] = max( 0, (int) $data['reward_amount'] );
			$formats[]               = '%d';
		}
		if ( array_key_exists( 'status', $data ) ) {
			$fields['status'] = sanitize_key( (string) $data['status'] );
			$formats[]        = '%s';
		}
		foreach ( array( 'starts_at', 'ends_at' ) as $dt ) {
			if ( array_key_exists( $dt, $data ) ) {
				$fields[ $dt ] = $this->dt( $data[ $dt ] );
				$formats[]     = '%s';
			}
		}
		return $this->update( $fields, array( 'id' => $id ), $formats, array( '%d' ) ) >= 0;
	}

	/**
	 * Set a campaign's status.
	 *
	 * @param int    $id     Campaign id.
	 * @param string $status New status.
	 * @return void
	 */
	public function set_status( int $id, string $status ): void {
		$this->update(
			array( 'status' => sanitize_key( $status ), 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Delete a campaign.
	 *
	 * @param int $id Campaign id.
	 * @return void
	 */
	public function delete_campaign( int $id ): void {
		$wpdb = $this->db->wpdb();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Campaign ids due for auto-activation or auto-completion.
	 *
	 * @return array{activate:int[],complete:int[]}
	 */
	public function due_transitions(): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		$now   = current_time( 'mysql' );
		$zero  = '0000-00-00 00:00:00';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$activate = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE status = 'scheduled' AND starts_at <> %s AND starts_at <= %s",
			$zero,
			$now
		) );
		$complete = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE status = 'active' AND ends_at <> %s AND ends_at < %s",
			$zero,
			$now
		) );
		// phpcs:enable

		return array(
			'activate' => array_map( 'intval', (array) $activate ),
			'complete' => array_map( 'intval', (array) $complete ),
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
