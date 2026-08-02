<?php
/**
 * Document repository on $wpdb.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Infrastructure\Persistence;

use Yazan\Homepage\Domain\Document\DocumentKey;
use Yazan\Homepage\Domain\Document\HomepageDocument;
use Yazan\Homepage\Domain\Port\CachePort;
use Yazan\Homepage\Domain\Port\HomepageRepositoryPort;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores documents in wp_yazan_homepage_documents.
 */
final class WpdbHomepageRepository implements HomepageRepositoryPort {

	/** @var CachePort */
	private $cache;

	/**
	 * @param CachePort $cache Cache.
	 */
	public function __construct( CachePort $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Load a document, or a fresh empty one.
	 *
	 * An absent row is NOT an error: an empty document renders the theme's own defaults, which is
	 * exactly the state a site is in before anyone has touched the builder.
	 *
	 * @param DocumentKey $key Key.
	 * @return HomepageDocument
	 */
	public function get( DocumentKey $key ) {
		global $wpdb;

		$table = Schema::table( 'documents' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE doc_key = %s", $key->value() ), ARRAY_A );

		if ( ! $row ) {
			return HomepageDocument::create( $key );
		}

		return HomepageDocument::from_array(
			array(
				'id'                    => (int) $row['id'],
				'key'                   => $row['doc_key'],
				'title'                 => $row['title'],
				'status'                => $row['status'],
				'version'               => (int) $row['version'],
				'sections'              => DocumentSerializer::decode( $row['draft_payload'] ),
				'live_sections'         => DocumentSerializer::decode( $row['live_payload'] ),
				'scheduled_at'          => $row['scheduled_at'] ? strtotime( $row['scheduled_at'] . ' UTC' ) : null,
				'published_revision_id' => (int) $row['published_revision_id'],
			)
		);
	}

	/**
	 * Insert or update.
	 *
	 * @param HomepageDocument $document Document.
	 * @return void
	 */
	public function save( HomepageDocument $document ) {
		global $wpdb;

		$table = Schema::table( 'documents' );
		$now   = gmdate( 'Y-m-d H:i:s' );

		$data = array(
			'doc_key'               => $document->key()->value(),
			'title'                 => $document->title(),
			'status'                => $document->status(),
			'version'               => $document->version()->value(),
			'schema_version'        => DocumentSerializer::PAYLOAD_VERSION,
			'draft_payload'         => DocumentSerializer::encode( $document->sections()->to_array() ),
			'live_payload'          => DocumentSerializer::encode( $document->live_sections() ),
			'scheduled_at'          => $document->scheduled_at() ? gmdate( 'Y-m-d H:i:s', $document->scheduled_at() ) : null,
			'published_revision_id' => $document->published_revision_id(),
			'updated_by'            => get_current_user_id(),
			'updated_at'            => $now,
		);

		if ( $document->id() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, $data, array( 'id' => $document->id() ) );
		} else {
			$data['created_at'] = $now;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert( $table, $data );
			$document->assign_id( (int) $wpdb->insert_id );
		}

		$this->cache->flush();
	}

	/**
	 * The published sections only — the storefront's hot read.
	 *
	 * @param DocumentKey $key Key.
	 * @return array
	 */
	public function live_payload( DocumentKey $key ) {
		$cache_key = 'live:' . $key->value();
		$cached    = $this->cache->get( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$table = Schema::table( 'documents' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$json = $wpdb->get_var( $wpdb->prepare( "SELECT live_payload FROM {$table} WHERE doc_key = %s", $key->value() ) );

		$sections = DocumentSerializer::decode( $json );

		$this->cache->set( $cache_key, $sections, HOUR_IN_SECONDS * 12 );

		return $sections;
	}

	/**
	 * Documents whose scheduled publish time has arrived.
	 *
	 * @param int $now UTC timestamp.
	 * @return HomepageDocument[]
	 */
	public function due_for_publish( $now ) {
		global $wpdb;

		$table = Schema::table( 'documents' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT doc_key FROM {$table} WHERE status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= %s",
				gmdate( 'Y-m-d H:i:s', (int) $now )
			)
		);

		$out = array();
		foreach ( (array) $keys as $key ) {
			$out[] = $this->get( DocumentKey::from( $key ) );
		}

		return $out;
	}
}
