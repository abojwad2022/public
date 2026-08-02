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
				'bound_page_id'         => isset( $row['bound_page_id'] ) ? (int) $row['bound_page_id'] : 0,
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
			'bound_page_id'         => $document->bound_page_id(),
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
	 * Every document, for the switcher in the builder.
	 *
	 * @return array<int,array>
	 */
	public function listing() {
		global $wpdb;

		$table = Schema::table( 'documents' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT doc_key, title, status, bound_page_id, updated_at FROM {$table} ORDER BY doc_key = 'default' DESC, title ASC", ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The document bound to a WordPress page, if any.
	 *
	 * Reads only the key: the caller loads the full document afterwards, and this runs on every
	 * page view of a site that may have no landing pages at all.
	 *
	 * @param int $page_id Page id.
	 * @return string|null Document key.
	 */
	public function key_for_page( $page_id ) {
		$page_id = (int) $page_id;

		if ( $page_id <= 0 ) {
			return null;
		}

		$cache_key = 'page:' . $page_id;
		$cached    = $this->cache->get( $cache_key, false );

		if ( is_string( $cached ) ) {
			return '' === $cached ? null : $cached;
		}

		global $wpdb;
		$table = Schema::table( 'documents' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$key = $wpdb->get_var( $wpdb->prepare( "SELECT doc_key FROM {$table} WHERE bound_page_id = %d LIMIT 1", $page_id ) );

		// The MISS is cached too, as an empty string — otherwise every ordinary page on the site
		// pays for a query that will never find anything.
		$this->cache->set( $cache_key, (string) $key, HOUR_IN_SECONDS * 12 );

		return $key ? (string) $key : null;
	}

	/**
	 * Remove a document and everything that belongs to it.
	 *
	 * @param DocumentKey $key Key.
	 * @return bool
	 */
	public function delete( DocumentKey $key ) {
		if ( DocumentKey::DEFAULT_KEY === $key->value() ) {
			// The homepage itself is not deletable. Emptying it is a different, reversible act.
			return false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Schema::table( 'revisions' ), array( 'doc_key' => $key->value() ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$done = $wpdb->delete( Schema::table( 'documents' ), array( 'doc_key' => $key->value() ) );

		$this->cache->flush();

		return (bool) $done;
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
