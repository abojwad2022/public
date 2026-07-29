<?php
/**
 * Loyalty-tier repository.
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
 * Reads/writes the `tiers` table (loyalty-tier definitions).
 */
final class TierRepository extends AbstractRepository {

	/**
	 * @inheritDoc
	 */
	protected function table_name(): string {
		return 'tiers';
	}

	/**
	 * All tiers ordered ascending by threshold.
	 *
	 * @return object[]
	 */
	public function all(): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY threshold_points ASC, sort ASC" );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * A tier by slug.
	 *
	 * @param string $slug Tier slug.
	 * @return object|null
	 */
	public function find_by_slug( string $slug ): ?object {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s LIMIT 1", sanitize_key( $slug ) ) );
		return $row ?: null;
	}

	/**
	 * Count of tier rows.
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
	 * Create a tier.
	 *
	 * @param array $data name, slug, threshold_points, multiplier, benefits(array),
	 *                    sort, badge, color, discount_percent.
	 * @return int
	 */
	public function create( array $data ): int {
		return $this->insert(
			array(
				'name'             => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
				'slug'             => sanitize_key( (string) ( $data['slug'] ?? '' ) ),
				'threshold_points' => max( 0, (int) ( $data['threshold_points'] ?? 0 ) ),
				'multiplier'       => (float) ( $data['multiplier'] ?? 1 ),
				'benefits'         => wp_json_encode( (array) ( $data['benefits'] ?? array() ) ),
				'sort'             => (int) ( $data['sort'] ?? 0 ),
				'badge'            => sanitize_text_field( (string) ( $data['badge'] ?? '' ) ),
				'color'            => sanitize_text_field( (string) ( $data['color'] ?? '' ) ),
				'discount_percent' => max( 0, (float) ( $data['discount_percent'] ?? 0 ) ),
			),
			array( '%s', '%s', '%d', '%f', '%s', '%d', '%s', '%s', '%f' )
		);
	}

	/**
	 * Update level presentation/benefit fields by slug (idempotent seed sync).
	 *
	 * @param string $slug Tier slug.
	 * @param array  $data badge, color, discount_percent, multiplier, threshold_points, benefits.
	 * @return void
	 */
	public function update_by_slug( string $slug, array $data ): void {
		$fields  = array();
		$formats = array();
		foreach ( array( 'badge' => '%s', 'color' => '%s' ) as $k => $fmt ) {
			if ( array_key_exists( $k, $data ) ) {
				$fields[ $k ] = sanitize_text_field( (string) $data[ $k ] );
				$formats[]    = $fmt;
			}
		}
		if ( array_key_exists( 'discount_percent', $data ) ) {
			$fields['discount_percent'] = max( 0, (float) $data['discount_percent'] );
			$formats[]                  = '%f';
		}
		if ( array_key_exists( 'multiplier', $data ) ) {
			$fields['multiplier'] = (float) $data['multiplier'];
			$formats[]            = '%f';
		}
		if ( array_key_exists( 'benefits', $data ) ) {
			$fields['benefits'] = wp_json_encode( (array) $data['benefits'] );
			$formats[]          = '%s';
		}
		if ( empty( $fields ) ) {
			return;
		}
		$this->update( $fields, array( 'slug' => sanitize_key( $slug ) ), $formats, array( '%s' ) );
	}
}
