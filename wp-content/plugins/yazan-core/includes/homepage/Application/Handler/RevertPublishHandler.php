<?php
/**
 * Undo the last publish.
 *
 * Puts the previously published version back on the LIVE page immediately, and does not touch the
 * draft. That combination is the whole point: someone who just published a mistake needs visitors
 * to stop seeing it NOW, without losing the work they were in the middle of.
 *
 * It is not a rollback. RollbackHandler restores into the draft and waits for a deliberate
 * publish, which is right when you are reaching into history on purpose and wrong when the shop
 * window is currently broken.
 *
 * Gated on `homepage.publish`, not on `homepage.rollback`: this changes what visitors see, which
 * is exactly what that permission governs — and requiring a second permission would mean the
 * person who made the mistake often cannot fix it.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Application\Handler;

use Yazan\Homepage\Application\Service\DocumentValidator;
use Yazan\Homepage\Domain\Component\ComponentRegistry;
use Yazan\Homepage\Domain\Document\DocumentKey;
use Yazan\Homepage\Domain\Exception\ValidationFailed;
use Yazan\Homepage\Domain\Port\AuthorizationPort;
use Yazan\Homepage\Domain\Port\EventDispatcherPort;
use Yazan\Homepage\Domain\Port\HomepageRepositoryPort;
use Yazan\Homepage\Domain\Port\RevisionRepositoryPort;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reverts the live page one publish.
 */
final class RevertPublishHandler extends AbstractHandler {

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
	 * Is there an earlier published version to fall back to?
	 *
	 * @param DocumentKey $key Document key.
	 * @return array|null The revision row, or null.
	 */
	public function target( DocumentKey $key ) {
		$document = $this->documents->get( $key );

		if ( ! $document->has_live_content() ) {
			return null;
		}

		return $this->revisions->previous_publish( $key->value(), (int) $document->published_revision_id() );
	}

	/**
	 * @param DocumentKey $key     Document key.
	 * @param int|null    $version Expected document version.
	 * @return array
	 * @throws ValidationFailed When this is the first published version.
	 */
	public function handle( DocumentKey $key, $version = null ) {
		$this->auth->require_permission( 'homepage.publish' );

		$target = $this->target( $key );

		if ( ! $target ) {
			throw new ValidationFailed(
				__( 'There is no earlier published version to go back to.', 'yazan' )
			);
		}

		$document = $this->load( $key, $version );

		$document->revert_live_to( (array) $target['sections'], (int) $target['id'] );

		// Note the deliberate absence of assert_publishable(): this content WAS live once, and
		// re-validating it against today's rules could refuse the very rollback that is meant to
		// rescue the page. Structural validation still runs inside persist().
		$this->persist( $document );

		return $this->result(
			$document,
			array(
				'reverted_to' => (int) $target['id'],
				'revision_no' => (int) $target['revision_no'],
			)
		);
	}
}
