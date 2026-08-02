<?php
/**
 * Document persistence boundary.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Port;

use Yazan\Homepage\Domain\Document\DocumentKey;
use Yazan\Homepage\Domain\Document\HomepageDocument;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads and stores homepage documents.
 */
interface HomepageRepositoryPort {

	/**
	 * Load a document, creating an empty one if it does not exist yet.
	 *
	 * @param DocumentKey $key Key.
	 * @return HomepageDocument
	 */
	public function get( DocumentKey $key );

	/**
	 * Persist the draft side of a document.
	 *
	 * @param HomepageDocument $document Document.
	 * @return void
	 */
	public function save( HomepageDocument $document );

	/**
	 * The published payload only — the hot read used by the storefront.
	 *
	 * @param DocumentKey $key Key.
	 * @return array Sections payload; empty array when nothing is published.
	 */
	public function live_payload( DocumentKey $key );

	/**
	 * Every document, for the builder's switcher.
	 *
	 * @return array<int,array>
	 */
	public function listing();

	/**
	 * The document key bound to a WordPress page, or null.
	 *
	 * @param int $page_id Page id.
	 * @return string|null
	 */
	public function key_for_page( $page_id );

	/**
	 * Delete a document. The default document is never deletable.
	 *
	 * @param DocumentKey $key Key.
	 * @return bool
	 */
	public function delete( DocumentKey $key );

	/**
	 * Every document with a scheduled publish that is now due.
	 *
	 * @param int $now UTC timestamp.
	 * @return HomepageDocument[]
	 */
	public function due_for_publish( $now );
}
