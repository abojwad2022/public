<?php
/**
 * Revision persistence boundary. Append-only by contract.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Port;

use Yazan\Homepage\Domain\Document\HomepageDocument;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores and reads document revisions.
 */
interface RevisionRepositoryPort {

	/**
	 * @param HomepageDocument $document Document to snapshot.
	 * @param int              $author   User id.
	 * @param string           $note     Short note.
	 * @param bool             $is_publish Whether this snapshot accompanies a publish.
	 * @return int New revision id.
	 */
	public function append( HomepageDocument $document, $author, $note = '', $is_publish = false );

	/**
	 * @param string $document_key Document key.
	 * @param int    $limit        Max rows.
	 * @param int    $offset       Offset.
	 * @return array<int,array>
	 */
	public function listing( $document_key, $limit = 30, $offset = 0 );

	/**
	 * @param int $revision_id Revision id.
	 * @return array|null
	 */
	public function find( $revision_id );

	/**
	 * Trim to the newest N revisions for a document.
	 *
	 * @param string $document_key Document key.
	 * @param int    $keep         How many to keep.
	 * @return int Rows removed.
	 */
	public function prune( $document_key, $keep );
}
