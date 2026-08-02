<?php
/**
 * Revision repository on $wpdb — append only.
 *
 * There is no update and no single-row delete, by design: a history that can be edited is not a
 * history. Trimming happens in bulk through prune(), and the prune itself is audited.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Infrastructure\Persistence;

use Yazan\Homepage\Domain\Document\HomepageDocument;
use Yazan\Homepage\Domain\Port\RevisionRepositoryPort;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores revisions in wp_yazan_homepage_revisions.
 */
final class WpdbRevisionRepository implements RevisionRepositoryPort {

	/**
	 * Snapshot a document.
	 *
	 * @param HomepageDocument $document   Document.
	 * @param int              $author     User id.
	 * @param string           $note       Note.
	 * @param bool             $is_publish Publish snapshot.
	 * @return int
	 */
	public function append( HomepageDocument $document, $author, $note = '', $is_publish = false ) {
		global $wpdb;

		$table   = Schema::table( 'revisions' );
		$key     = $document->key()->value();
		$payload = DocumentSerializer::encode( $document->sections()->to_array() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$next = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(revision_no),0) + 1 FROM {$table} WHERE doc_key = %s", $key ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$table,
			array(
				'doc_key'     => $key,
				'revision_no' => $next,
				'payload'     => $payload,
				'note'        => mb_substr( (string) $note, 0, 190 ),
				'author_id'   => (int) $author,
				'is_publish'  => $is_publish ? 1 : 0,
				'size_bytes'  => strlen( $payload ),
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * @param string $document_key Key.
	 * @param int    $limit        Limit.
	 * @param int    $offset       Offset.
	 * @return array<int,array>
	 */
	public function listing( $document_key, $limit = 30, $offset = 0 ) {
		global $wpdb;

		$table = Schema::table( 'revisions' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, revision_no, note, author_id, is_publish, size_bytes, created_at
				 FROM {$table} WHERE doc_key = %s ORDER BY revision_no DESC LIMIT %d OFFSET %d",
				$document_key,
				max( 1, min( 100, (int) $limit ) ),
				max( 0, (int) $offset )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param int $revision_id Revision id.
	 * @return array|null
	 */
	public function find( $revision_id ) {
		global $wpdb;

		$table = Schema::table( 'revisions' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $revision_id ), ARRAY_A );

		if ( ! $row ) {
			return null;
		}

		$row['sections'] = DocumentSerializer::decode( $row['payload'] );

		return $row;
	}

	/**
	 * Keep the newest N revisions.
	 *
	 * @param string $document_key Key.
	 * @param int    $keep         Keep count.
	 * @return int
	 */
	public function prune( $document_key, $keep ) {
		global $wpdb;

		$table = Schema::table( 'revisions' );
		$keep  = max( 5, (int) $keep );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cutoff = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT revision_no FROM {$table} WHERE doc_key = %s ORDER BY revision_no DESC LIMIT 1 OFFSET %d",
				$document_key,
				$keep
			)
		);

		if ( ! $cutoff ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE doc_key = %s AND revision_no <= %d", $document_key, $cutoff ) );
	}
}
