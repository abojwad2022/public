<?php
/**
 * Template repository on $wpdb.
 *
 * A template is a stored payload, not a living entity — it has no behaviour of its own, and giving
 * it one would be ceremony. What matters is that applying one goes through the SAME factory and
 * the same permission checks as building a section by hand: a template is a convenience, never a
 * second way in.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Infrastructure\Persistence;

use Yazan\Homepage\Domain\Port\TemplateRepositoryPort;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores templates in wp_yazan_homepage_templates.
 */
final class WpdbTemplateRepository implements TemplateRepositoryPort {

	/**
	 * @param string $kind           section | document.
	 * @param string $name           Template name.
	 * @param string $component_type Component type for a section template; '' for a document.
	 * @param array  $payload        Sections payload.
	 * @param int    $preview_media  Optional attachment id.
	 * @param int    $author         User id.
	 * @return int
	 */
	public function save( $kind, $name, $component_type, array $payload, $preview_media, $author ) {
		global $wpdb;

		$json = wp_json_encode( $payload );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			Schema::table( 'templates' ),
			array(
				'kind'             => 'document' === $kind ? 'document' : 'section',
				'component_type'   => (string) $component_type,
				'name'             => mb_substr( (string) $name, 0, 190 ),
				'payload'          => false === $json ? '' : $json,
				'preview_media_id' => (int) $preview_media,
				'is_global'        => 0,
				'created_by'       => (int) $author,
				'created_at'       => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * @param string $kind Optional filter.
	 * @return array<int,array>
	 */
	public function all( $kind = '' ) {
		global $wpdb;

		$table = Schema::table( 'templates' );

		if ( $kind ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, kind, component_type, name, preview_media_id, created_by, created_at FROM {$table} WHERE kind = %s ORDER BY name ASC", $kind ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( "SELECT id, kind, component_type, name, preview_media_id, created_by, created_at FROM {$table} ORDER BY kind ASC, name ASC", ARRAY_A );
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param int $id Template id.
	 * @return array|null
	 */
	public function find( $id ) {
		global $wpdb;

		$table = Schema::table( 'templates' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );

		if ( ! $row ) {
			return null;
		}

		$decoded         = json_decode( (string) $row['payload'], true );
		$row['payload']  = is_array( $decoded ) ? $decoded : array();

		return $row;
	}

	/**
	 * @param int $id Template id.
	 * @return bool
	 */
	public function delete( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->delete( Schema::table( 'templates' ), array( 'id' => (int) $id ) );
	}
}
