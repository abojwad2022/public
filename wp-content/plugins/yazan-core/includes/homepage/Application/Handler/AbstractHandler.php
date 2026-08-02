<?php
/**
 * Shared machinery for every write use case.
 *
 * The sequence is identical for all of them and lives here exactly once:
 *
 *   authorize → load → assert version → mutate → validate → save → release events
 *
 * Events are released ONLY after the save returns. An audit row for a change that failed to
 * persist is worse than no audit row at all.
 *
 * NOTE ON THE APPROVED DESIGN: the architecture document listed a Command DTO per use case. They
 * are not built. A DTO whose only job is to carry three scalars from the REST mapper to a handler
 * that re-validates all of them adds a file per operation and enforces no invariant of its own.
 * The handlers below take explicit arguments instead. Declared here rather than quietly skipped.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Application\Handler;

use Yazan\Homepage\Application\Service\DocumentValidator;
use Yazan\Homepage\Domain\Component\ComponentDefinition;
use Yazan\Homepage\Domain\Component\ComponentRegistry;
use Yazan\Homepage\Domain\Document\DocumentKey;
use Yazan\Homepage\Domain\Document\HomepageDocument;
use Yazan\Homepage\Domain\Exception\UnknownComponentType;
use Yazan\Homepage\Domain\Exception\VersionConflict;
use Yazan\Homepage\Domain\Policy\SectionPolicy;
use Yazan\Homepage\Domain\Port\AuthorizationPort;
use Yazan\Homepage\Domain\Port\EventDispatcherPort;
use Yazan\Homepage\Domain\Port\HomepageRepositoryPort;
use Yazan\Homepage\Domain\Section\Section;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base write handler.
 */
abstract class AbstractHandler {

	/** @var HomepageRepositoryPort */
	protected $documents;

	/** @var AuthorizationPort */
	protected $auth;

	/** @var ComponentRegistry */
	protected $registry;

	/** @var DocumentValidator */
	protected $validator;

	/** @var EventDispatcherPort */
	protected $events;

	/** @var SectionPolicy */
	protected $policy;

	/**
	 * @param HomepageRepositoryPort $documents Repository.
	 * @param AuthorizationPort      $auth      Authorization.
	 * @param ComponentRegistry      $registry  Registry.
	 * @param DocumentValidator      $validator Validator.
	 * @param EventDispatcherPort    $events    Dispatcher.
	 */
	public function __construct(
		HomepageRepositoryPort $documents,
		AuthorizationPort $auth,
		ComponentRegistry $registry,
		DocumentValidator $validator,
		EventDispatcherPort $events
	) {
		$this->documents = $documents;
		$this->auth      = $auth;
		$this->registry  = $registry;
		$this->validator = $validator;
		$this->events    = $events;
		$this->policy    = new SectionPolicy( $auth );
	}

	/**
	 * Load the document and refuse a stale write.
	 *
	 * @param DocumentKey $key              Document key.
	 * @param int|null    $expected_version Version the client believes it is changing; null skips.
	 * @return HomepageDocument
	 * @throws VersionConflict When the client is behind.
	 */
	protected function load( DocumentKey $key, $expected_version = null ) {
		$document = $this->documents->get( $key );

		if ( null !== $expected_version && (int) $expected_version !== $document->version()->value() ) {
			throw ( new VersionConflict( 'This homepage was changed by someone else.' ) )->with_context(
				array(
					'expected' => (int) $expected_version,
					'actual'   => $document->version()->value(),
				)
			);
		}

		return $document;
	}

	/**
	 * Validate, save, then publish the buffered events.
	 *
	 * @param HomepageDocument $document Document.
	 * @return HomepageDocument
	 */
	protected function persist( HomepageDocument $document ) {
		$this->validator->assert_valid( $document );

		$this->documents->save( $document );

		$this->events->dispatch_all( $document->release_events() );

		return $document;
	}

	/**
	 * @param string $type Component type.
	 * @return ComponentDefinition
	 * @throws UnknownComponentType When absent.
	 */
	protected function definition( $type ) {
		$definition = $this->registry->get( $type );

		if ( ! $definition ) {
			throw ( new UnknownComponentType( 'Unknown component type: ' . $type ) )
				->with_context( array( 'type' => $type ) );
		}

		return $definition;
	}

	/**
	 * @param Section $section Section.
	 * @return ComponentDefinition
	 */
	protected function definition_for( Section $section ) {
		return $this->definition( $section->type()->value() );
	}

	/**
	 * The standard write response.
	 *
	 * @param HomepageDocument $document Document.
	 * @param array            $extra    Extra keys (blocked, section…).
	 * @return array
	 */
	protected function result( HomepageDocument $document, array $extra = array() ) {
		return array_merge(
			array(
				'version'                 => $document->version()->value(),
				'status'                  => $document->status(),
				'has_unpublished_changes' => $document->has_unpublished_changes(),
				'blocked'                 => array(),
				// Sections whose component is not registered right now. Carried, not editable,
				// not rendered — the builder badges them instead of pretending they are gone.
				'unavailable'             => $this->validator->warnings( $document ),
			),
			$extra
		);
	}
}
