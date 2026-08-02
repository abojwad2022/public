<?php
/**
 * Documents: list, create, bind to a page, delete.
 *
 * A landing page is not a new kind of thing — it is the same document with a different key, bound
 * to a WordPress page instead of the front page. That is why this arrived as one handler and a
 * column rather than a second builder.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Application\Handler;

use Yazan\Homepage\Domain\Document\DocumentKey;
use Yazan\Homepage\Domain\Document\HomepageDocument;
use Yazan\Homepage\Domain\Exception\SectionNotFound;
use Yazan\Homepage\Domain\Exception\ValidationFailed;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Document-level use cases.
 */
final class DocumentsHandler extends AbstractHandler {

	/**
	 * Every document, with its binding resolved to a page title.
	 *
	 * @return array
	 */
	public function listing() {
		$this->auth->require_permission( 'homepage.view' );

		$rows = $this->documents->listing();

		foreach ( $rows as $index => $row ) {
			$page_id = (int) $row['bound_page_id'];

			$rows[ $index ]['page'] = $page_id ? get_the_title( $page_id ) : '';
			$rows[ $index ]['url']  = $page_id ? (string) get_permalink( $page_id ) : home_url( '/' );
		}

		return array( 'documents' => $rows );
	}

	/**
	 * Create a landing page document.
	 *
	 * @param string $key   Document key.
	 * @param string $title Human title.
	 * @return array
	 */
	public function create( $key, $title ) {
		$this->auth->require_permission( 'homepage.settings' );

		$document_key = DocumentKey::from( $key );

		if ( DocumentKey::DEFAULT_KEY === $document_key->value() ) {
			throw new ValidationFailed( __( 'That key is reserved for the homepage.', 'yazan' ) );
		}

		$existing = $this->documents->get( $document_key );

		if ( $existing->id() ) {
			throw ( new ValidationFailed( __( 'A page with that key already exists.', 'yazan' ) ) )
				->with_context( array( 'key' => $document_key->value() ) );
		}

		$document = HomepageDocument::create( $document_key, sanitize_text_field( (string) $title ) );

		$this->documents->save( $document );

		return array(
			'key'   => $document_key->value(),
			'title' => $document->title(),
		);
	}

	/**
	 * Point a document at a WordPress page, or detach it.
	 *
	 * @param DocumentKey $key     Document key.
	 * @param int         $page_id Page id, or 0.
	 * @return array
	 */
	public function bind( DocumentKey $key, $page_id ) {
		$this->auth->require_permission( 'homepage.settings' );

		$page_id = max( 0, (int) $page_id );

		if ( DocumentKey::DEFAULT_KEY === $key->value() && $page_id ) {
			throw new ValidationFailed( __( 'The homepage document always renders on the front page.', 'yazan' ) );
		}

		if ( $page_id ) {
			$post = get_post( $page_id );

			if ( ! $post || 'page' !== $post->post_type ) {
				throw ( new SectionNotFound( __( 'That page does not exist.', 'yazan' ) ) )
					->with_context( array( 'page' => $page_id ) );
			}

			$taken = $this->documents->key_for_page( $page_id );

			if ( $taken && $taken !== $key->value() ) {
				// Two documents on one page would mean whichever query ran first decides what the
				// visitor sees — a coin toss dressed up as configuration.
				throw ( new ValidationFailed( __( 'Another page layout is already bound to that page.', 'yazan' ) ) )
					->with_context( array( 'page' => $page_id, 'document' => $taken ) );
			}
		}

		$document = $this->documents->get( $key );

		if ( ! $document->id() ) {
			throw new SectionNotFound( __( 'That layout does not exist.', 'yazan' ) );
		}

		$document->bind_to_page( $page_id );

		$this->documents->save( $document );

		return array(
			'key'     => $key->value(),
			'page'    => $page_id,
			'version' => $document->version()->value(),
		);
	}

	/**
	 * @param DocumentKey $key Document key.
	 * @return array
	 */
	public function remove( DocumentKey $key ) {
		$this->auth->require_permission( 'homepage.settings' );

		if ( DocumentKey::DEFAULT_KEY === $key->value() ) {
			throw new ValidationFailed( __( 'The homepage cannot be deleted.', 'yazan' ) );
		}

		$deleted = (bool) $this->documents->delete( $key );

		if ( $deleted ) {
			// Any experiment pointing at this layout is now comparing against something that does
			// not exist. Left alone, half the visitors would be sent to a document the repository
			// answers as empty — they would silently see the theme's own homepage while the report
			// went on counting them as the variant's audience. Clean both sides here.
			$this->drop_experiments_for( $key );
		}

		return array( 'deleted' => $deleted );
	}

	/**
	 * Remove every experiment that uses a document, on either side.
	 *
	 * @param DocumentKey $key Document being deleted.
	 * @return void
	 */
	private function drop_experiments_for( DocumentKey $key ) {
		$store = \Yazan\Homepage\Infrastructure\Bootstrap\ServiceFactory::experiments();

		foreach ( $store->all() as $control => $experiment ) {
			if ( $experiment->control()->value() !== $key->value() && $experiment->variant()->value() !== $key->value() ) {
				continue;
			}

			$store->remove( $experiment->control() );
			$store->clear( $experiment->control()->value() );
		}
	}
}
