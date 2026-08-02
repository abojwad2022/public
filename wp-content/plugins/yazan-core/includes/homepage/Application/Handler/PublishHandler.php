<?php
/**
 * Make the draft live.
 *
 * The only operation in the module that changes what a visitor sees, which is why it carries its
 * own permission: a role can be allowed to edit all day and still not be able to publish.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Application\Handler;

use Yazan\Homepage\Application\Service\DocumentValidator;
use Yazan\Homepage\Domain\Component\ComponentRegistry;
use Yazan\Homepage\Domain\Document\DocumentKey;
use Yazan\Homepage\Domain\Port\AuthorizationPort;
use Yazan\Homepage\Domain\Port\ClockPort;
use Yazan\Homepage\Domain\Port\EventDispatcherPort;
use Yazan\Homepage\Domain\Port\HomepageRepositoryPort;
use Yazan\Homepage\Domain\Port\RevisionRepositoryPort;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Publishes the draft.
 */
final class PublishHandler extends AbstractHandler {

	/** How many revisions to keep per document. */
	const KEEP_REVISIONS = 50;

	/** @var RevisionRepositoryPort */
	private $revisions;

	/** @var ClockPort */
	private $clock;

	/**
	 * @param HomepageRepositoryPort $documents Repository.
	 * @param AuthorizationPort      $auth      Authorization.
	 * @param ComponentRegistry      $registry  Registry.
	 * @param DocumentValidator      $validator Validator.
	 * @param EventDispatcherPort    $events    Dispatcher.
	 * @param RevisionRepositoryPort $revisions Revisions.
	 * @param ClockPort              $clock     Clock.
	 */
	public function __construct(
		HomepageRepositoryPort $documents,
		AuthorizationPort $auth,
		ComponentRegistry $registry,
		DocumentValidator $validator,
		EventDispatcherPort $events,
		RevisionRepositoryPort $revisions,
		ClockPort $clock
	) {
		parent::__construct( $documents, $auth, $registry, $validator, $events );

		$this->revisions = $revisions;
		$this->clock     = $clock;
	}

	/**
	 * @param DocumentKey $key     Document key.
	 * @param string      $note    Optional revision note.
	 * @param int|null    $version Expected document version.
	 * @return array
	 */
	public function handle( DocumentKey $key, $note = '', $version = null ) {
		$this->auth->require_permission( 'homepage.publish' );

		$document = $this->load( $key, $version );

		// The STRICTER check, and only here: required fields are enforced at publish time, never
		// while drafting. Validated before snapshotting, because a revision of a broken document
		// is a trap for whoever restores it later.
		$this->validator->assert_publishable( $document );

		$revision_id = $this->revisions->append( $document, $this->auth->actor_id(), $note, true );

		$document->publish( $revision_id, $this->auth->actor_id() );

		$this->persist( $document );

		$this->revisions->prune( $key->value(), self::KEEP_REVISIONS );

		return $this->result(
			$document,
			array(
				'revision'     => $revision_id,
				'published_at' => $this->clock->now(),
			)
		);
	}

	/**
	 * Arrange a publish for later.
	 *
	 * @param DocumentKey $key       Document key.
	 * @param int         $timestamp UTC timestamp.
	 * @param int|null    $version   Expected document version.
	 * @return array
	 */
	public function schedule( DocumentKey $key, $timestamp, $version = null ) {
		$this->auth->require_permission( 'homepage.publish' );

		$timestamp = (int) $timestamp;

		if ( $timestamp <= $this->clock->now() ) {
			// "Schedule it for the past" means "publish now"; saying so is clearer than a silent
			// immediate publish that looks like a bug.
			return $this->handle( $key, 'scheduled_immediately', $version );
		}

		$document = $this->load( $key, $version );

		// A scheduled publish IS a publish, just later — same standard.
		$this->validator->assert_publishable( $document );

		$document->schedule( $timestamp );

		$this->persist( $document );

		return $this->result( $document, array( 'scheduled_at' => $timestamp ) );
	}

	/**
	 * Publish everything whose scheduled moment has arrived. Called from cron.
	 *
	 * @return int Documents published.
	 */
	public function run_due() {
		$due   = $this->documents->due_for_publish( $this->clock->now() );
		$count = 0;

		foreach ( $due as $document ) {
			// No permission check here: the actor was authorised when the schedule was set, and
			// cron has no user.
			$revision_id = $this->revisions->append( $document, 0, 'scheduled', true );

			$document->publish( $revision_id, 0 );

			$this->documents->save( $document );
			$this->events->dispatch_all( $document->release_events() );

			++$count;
		}

		return $count;
	}
}
