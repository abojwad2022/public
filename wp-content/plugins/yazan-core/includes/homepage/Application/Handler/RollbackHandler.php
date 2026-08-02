<?php
/**
 * Restore an earlier revision.
 *
 * Restores INTO THE DRAFT, never straight to live. One click should not change the public
 * homepage — the operator reviews what came back and then publishes deliberately, which is the
 * same rule the rest of this module follows.
 *
 * The current draft is snapshotted first, so the restore is itself undoable.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Application\Handler;

use Yazan\Homepage\Application\Service\DocumentValidator;
use Yazan\Homepage\Domain\Component\ComponentRegistry;
use Yazan\Homepage\Domain\Document\DocumentKey;
use Yazan\Homepage\Domain\Document\SectionCollection;
use Yazan\Homepage\Domain\Exception\SectionNotFound;
use Yazan\Homepage\Domain\Port\AuthorizationPort;
use Yazan\Homepage\Domain\Port\EventDispatcherPort;
use Yazan\Homepage\Domain\Port\HomepageRepositoryPort;
use Yazan\Homepage\Domain\Port\RevisionRepositoryPort;
use Yazan\Homepage\Domain\Section\Section;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Restores a revision into the draft.
 */
final class RollbackHandler extends AbstractHandler {

	/** @var RevisionRepositoryPort */
	private $revisions;

	/**
	 * @param HomepageRepositoryPort $documents Repository.
	 * @param AuthorizationPort      $auth      Authorization.
	 * @param ComponentRegistry      $registry  Registry.
	 * @param DocumentValidator      $validator Validator.
	 * @param EventDispatcherPort    $events    Dispatcher.
	 * @param RevisionRepositoryPort $revisions Revisions.
	 */
	public function __construct(
		HomepageRepositoryPort $documents,
		AuthorizationPort $auth,
		ComponentRegistry $registry,
		DocumentValidator $validator,
		EventDispatcherPort $events,
		RevisionRepositoryPort $revisions
	) {
		parent::__construct( $documents, $auth, $registry, $validator, $events );

		$this->revisions = $revisions;
	}

	/**
	 * @param DocumentKey $key         Document key.
	 * @param int         $revision_id Revision to restore.
	 * @param int|null    $version     Expected document version.
	 * @return array
	 * @throws SectionNotFound When the revision does not exist.
	 */
	public function handle( DocumentKey $key, $revision_id, $version = null ) {
		$this->auth->require_permission( 'homepage.rollback' );

		$revision = $this->revisions->find( (int) $revision_id );

		if ( ! $revision || $revision['doc_key'] !== $key->value() ) {
			throw ( new SectionNotFound( 'That revision does not exist.' ) )
				->with_context( array( 'revision' => (int) $revision_id ) );
		}

		$document = $this->load( $key, $version );

		// Snapshot what is about to be replaced, so "restore" is not a one-way door.
		$this->revisions->append( $document, $this->auth->actor_id(), 'before_rollback', false );

		$sections = array();

		foreach ( (array) $revision['sections'] as $raw ) {
			/*
			 * Rebuilt as stored, whether or not the component is registered today. A revision can
			 * predate a plugin being deactivated, and refusing the whole restore over one block
			 * nobody can currently edit would make the safety net useless exactly when it matters.
			 */
			$sections[] = Section::from_array( (array) $raw );
		}

		$document->restore_from_revision( SectionCollection::of( $sections ), (int) $revision_id );

		$this->persist( $document );

		return $this->result(
			$document,
			array(
				'restored_from' => (int) $revision_id,
				'sections'      => $document->sections()->to_array(),
			)
		);
	}
}
