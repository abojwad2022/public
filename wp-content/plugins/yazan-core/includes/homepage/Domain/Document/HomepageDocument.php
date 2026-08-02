<?php
/**
 * The homepage document — the aggregate root.
 *
 * Every structural change to the homepage goes through this object, which is what makes the
 * invariants enforceable in one place instead of re-checked in each REST controller:
 *
 *   1. Section order is the array order — no stored position can drift.
 *   2. A section id appears at most once.
 *   3. `max_instances` per component type is respected (the caller supplies the limit; the
 *      aggregate refuses to exceed it).
 *   4. `version` only ever increases, and only here.
 *   5. Events are buffered and released after a successful save, never before.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Document;

use Yazan\Homepage\Domain\Event\DomainEvent;
use Yazan\Homepage\Domain\Exception\ValidationFailed;
use Yazan\Homepage\Domain\Section\Section;
use Yazan\Homepage\Domain\Section\SectionId;
use Yazan\Homepage\Domain\Section\SectionState;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aggregate root.
 */
final class HomepageDocument {

	/** @var int 0 for a document that has never been persisted. */
	private $id;

	/** @var DocumentKey */
	private $key;

	/** @var string */
	private $title;

	/** @var string */
	private $status;

	/** @var DocumentVersion */
	private $version;

	/** @var SectionCollection */
	private $sections;

	/** @var int|null UTC timestamp for a scheduled publish. */
	private $scheduled_at;

	/** @var int|null */
	private $published_revision_id;

	/** @var array Sections as they are currently live (empty until first publish). */
	private $live_sections;

	/**
	 * @var int WordPress page this document renders on. 0 = the front page (the default document).
	 *
	 * A landing page is the same document type bound somewhere else — which is why "multi
	 * homepages" needed no new aggregate, only a key that was never assumed to be 'default'.
	 */
	private $bound_page_id = 0;

	/** @var DomainEvent[] */
	private $events = array();

	/**
	 * @param int               $id                     Row id.
	 * @param DocumentKey       $key                    Key.
	 * @param string            $title                  Title.
	 * @param string            $status                 Status.
	 * @param DocumentVersion   $version                Version.
	 * @param SectionCollection $sections               Draft sections.
	 * @param array             $live_sections          Published sections payload.
	 * @param int|null          $scheduled_at           Scheduled publish time.
	 * @param int|null          $published_revision_id  Published revision.
	 */
	private function __construct( $id, DocumentKey $key, $title, $status, DocumentVersion $version, SectionCollection $sections, array $live_sections, $scheduled_at, $published_revision_id ) {
		$this->id                    = (int) $id;
		$this->key                   = $key;
		$this->title                 = (string) $title;
		$this->status                = DocumentStatus::normalize( $status );
		$this->version               = $version;
		$this->sections              = $sections;
		$this->live_sections         = $live_sections;
		$this->scheduled_at          = $scheduled_at ? (int) $scheduled_at : null;
		$this->published_revision_id = $published_revision_id ? (int) $published_revision_id : null;
	}

	/**
	 * A brand-new, empty document.
	 *
	 * An empty document is a valid state and renders the theme's own defaults — that is the
	 * backward-compatibility guarantee, not an accident.
	 *
	 * @param DocumentKey $key   Key.
	 * @param string      $title Title.
	 * @return self
	 */
	public static function create( DocumentKey $key, $title = '' ) {
		return new self(
			0,
			$key,
			$title,
			DocumentStatus::DRAFT,
			DocumentVersion::initial(),
			SectionCollection::empty_collection(),
			array(),
			null,
			null
		);
	}

	/**
	 * Rebuild from storage.
	 *
	 * @param array $row Persisted representation.
	 * @return self
	 */
	public static function from_array( array $row ) {
		$sections = array();
		foreach ( (array) ( isset( $row['sections'] ) ? $row['sections'] : array() ) as $section ) {
			$sections[] = Section::from_array( (array) $section );
		}

		$document = new self(
			isset( $row['id'] ) ? $row['id'] : 0,
			DocumentKey::from( isset( $row['key'] ) ? $row['key'] : DocumentKey::DEFAULT_KEY ),
			isset( $row['title'] ) ? $row['title'] : '',
			isset( $row['status'] ) ? $row['status'] : DocumentStatus::DRAFT,
			DocumentVersion::from( isset( $row['version'] ) ? $row['version'] : 1 ),
			SectionCollection::of( $sections ),
			isset( $row['live_sections'] ) ? (array) $row['live_sections'] : array(),
			isset( $row['scheduled_at'] ) ? $row['scheduled_at'] : null,
			isset( $row['published_revision_id'] ) ? $row['published_revision_id'] : null
		);

		$document->bound_page_id = isset( $row['bound_page_id'] ) ? (int) $row['bound_page_id'] : 0;

		return $document;
	}

	/* ------------------------------------------------------------------ reads */

	/** @return int */
	public function id() {
		return $this->id;
	}

	/** @return DocumentKey */
	public function key() {
		return $this->key;
	}

	/** @return string */
	public function title() {
		return $this->title;
	}

	/** @return string */
	public function status() {
		return $this->status;
	}

	/** @return DocumentVersion */
	public function version() {
		return $this->version;
	}

	/** @return SectionCollection */
	public function sections() {
		return $this->sections;
	}

	/** @return array */
	public function live_sections() {
		return $this->live_sections;
	}

	/** @return int|null */
	public function scheduled_at() {
		return $this->scheduled_at;
	}

	/** @return int|null */
	public function published_revision_id() {
		return $this->published_revision_id;
	}

	/** @return int */
	public function bound_page_id() {
		return $this->bound_page_id;
	}

	/**
	 * Bind this document to a WordPress page, or to nothing.
	 *
	 * @param int $page_id Page id, or 0 to unbind.
	 * @return void
	 */
	public function bind_to_page( $page_id ) {
		$page_id = max( 0, (int) $page_id );

		if ( $page_id === $this->bound_page_id ) {
			return;
		}

		$this->bound_page_id = $page_id;

		// No touch() here on purpose. The version is the optimistic-lock token for CONTENT: it
		// exists so two people editing sections cannot overwrite each other. Binding changes where
		// the document renders, not what it says. Bumping it made the builder's open copy stale the
		// moment someone bound a page in the modal beside it, and the very next keystroke was
		// refused with "Someone else saved the homepage" — the someone else being themselves.
	}

	/**
	 * Has anything been published yet? Until it has, the theme's own defaults are authoritative.
	 *
	 * @return bool
	 */
	public function has_live_content() {
		return ! empty( $this->live_sections );
	}

	/**
	 * Draft differs from live.
	 *
	 * @return bool
	 */
	public function has_unpublished_changes() {
		return wp_json_encode( $this->sections->to_array() ) !== wp_json_encode( $this->live_sections );
	}

	/* ----------------------------------------------------------------- writes */

	/**
	 * @param int $id Assigned row id (repository only, on first insert).
	 * @return void
	 */
	public function assign_id( $id ) {
		if ( ! $this->id ) {
			$this->id = (int) $id;
		}
	}

	/**
	 * Add a section.
	 *
	 * @param Section  $section       Section.
	 * @param int|null $position      Insert index; null appends.
	 * @param int      $max_instances Component limit; 0 = unlimited.
	 * @return void
	 * @throws ValidationFailed When the limit is reached.
	 */
	public function add_section( Section $section, $position = null, $max_instances = 0 ) {
		if ( $max_instances > 0 && $this->sections->count_of_type( $section->type()->value() ) >= $max_instances ) {
			throw ( new ValidationFailed( 'This section type cannot be added again.' ) )
				->with_context(
					array(
						'type'          => $section->type()->value(),
						'max_instances' => (int) $max_instances,
					)
				);
		}

		$this->sections = $this->sections->add( $section, $position );
		$this->touch();

		$this->events[] = DomainEvent::section(
			DomainEvent::SECTION_CREATED,
			$section->id()->value(),
			array(
				'type'     => $section->type()->value(),
				'position' => $this->sections->position_of( $section->id() ),
			)
		);
	}

	/**
	 * Replace a section, recording the content diff.
	 *
	 * @param Section $section Updated section.
	 * @return void
	 */
	public function replace_section( Section $section ) {
		$before = $this->sections->get( $section->id() );

		$this->sections = $this->sections->replace( $section );
		$this->touch();

		$this->events[] = DomainEvent::section(
			DomainEvent::SECTION_UPDATED,
			$section->id()->value(),
			array(
				'type'   => $section->type()->value(),
				'before' => $before->to_array(),
				'after'  => $section->to_array(),
			)
		);
	}

	/**
	 * Remove a section. The full snapshot is recorded because deletion is not undoable from the
	 * section tree — the audit row is the only remaining copy until the next revision.
	 *
	 * @param SectionId $id Section id.
	 * @return void
	 */
	public function remove_section( SectionId $id ) {
		$section = $this->sections->get( $id );

		$this->sections = $this->sections->remove( $id );
		$this->touch();

		$this->events[] = DomainEvent::section(
			DomainEvent::SECTION_DELETED,
			$id->value(),
			array(
				'type'     => $section->type()->value(),
				'snapshot' => $section->to_array(),
			)
		);
	}

	/**
	 * Duplicate a section directly after the original.
	 *
	 * @param SectionId $id            Source section.
	 * @param int       $max_instances Component limit.
	 * @return SectionId The new section's id.
	 */
	public function duplicate_section( SectionId $id, $max_instances = 0 ) {
		$source = $this->sections->get( $id );
		$copy   = $source->duplicated();

		if ( $max_instances > 0 && $this->sections->count_of_type( $copy->type()->value() ) >= $max_instances ) {
			throw ( new ValidationFailed( 'This section type cannot be added again.' ) )
				->with_context(
					array(
						'type'          => $copy->type()->value(),
						'max_instances' => (int) $max_instances,
					)
				);
		}

		$position       = $this->sections->position_of( $id );
		$this->sections = $this->sections->add( $copy, null === $position ? null : $position + 1 );
		$this->touch();

		$this->events[] = DomainEvent::section(
			DomainEvent::SECTION_DUPLICATED,
			$copy->id()->value(),
			array(
				'type'   => $copy->type()->value(),
				'source' => $id->value(),
			)
		);

		return $copy->id();
	}

	/**
	 * Enable or disable a section.
	 *
	 * @param SectionId $id      Section id.
	 * @param bool      $enabled Desired state.
	 * @return void
	 */
	public function toggle_section( SectionId $id, $enabled ) {
		$section = $this->sections->get( $id );
		$state   = $enabled ? SectionState::ENABLED : SectionState::DISABLED;

		if ( $section->state() === $state ) {
			return;
		}

		$this->sections = $this->sections->replace( $section->with_state( $state ) );
		$this->touch();

		$this->events[] = DomainEvent::section(
			DomainEvent::SECTION_TOGGLED,
			$id->value(),
			array(
				'type'  => $section->type()->value(),
				'state' => $state,
			)
		);
	}

	/**
	 * Reorder every section.
	 *
	 * @param string[] $ordered_ids Full permutation of section ids.
	 * @return void
	 */
	public function reorder( array $ordered_ids ) {
		$before = array();
		foreach ( $this->sections->all() as $section ) {
			$before[] = $section->id()->value();
		}

		$this->sections = $this->sections->reorder( $ordered_ids );
		$this->touch();

		$after = array();
		foreach ( $this->sections->all() as $section ) {
			$after[] = $section->id()->value();
		}

		$this->events[] = DomainEvent::document(
			DomainEvent::SECTIONS_REORDERED,
			$this->key->value(),
			array(
				'before' => $before,
				'after'  => $after,
			)
		);
	}

	/**
	 * Replace the whole draft (used by import and template application).
	 *
	 * @param SectionCollection $sections New sections.
	 * @param string            $reason   Audit reason.
	 * @return void
	 */
	public function replace_sections( SectionCollection $sections, $reason = '', $detailed = true ) {
		// Computed BEFORE the assignment, obviously — and before touch(), so a save that changes
		// nothing still says so rather than inventing a difference.
		$changed = $detailed
			? SectionDiff::describe( $this->sections->to_array(), $sections->to_array() )
			: array();

		$this->sections = $sections;
		$this->touch();

		$payload = array(
			'reason'   => $reason,
			'sections' => $sections->count(),
		);

		if ( $detailed ) {
			// `changed` is the old-value/new-value record the specification asked for. An empty
			// list is recorded as such: "the operator saved and nothing moved" is a fact worth
			// keeping, not an absence to be guessed at later.
			$payload['changed'] = $changed ? $changed : array( 'no field changes' );
		}

		$this->events[] = DomainEvent::document(
			DomainEvent::DOCUMENT_SAVED,
			$this->key->value(),
			$payload
		);
	}

	/**
	 * Promote the draft to live.
	 *
	 * @param int      $revision_id Revision recorded for this publish.
	 * @param int|null $actor_id    Publishing user.
	 * @return void
	 */
	public function publish( $revision_id, $actor_id = null ) {
		$this->live_sections         = $this->sections->to_array();
		$this->status                = DocumentStatus::PUBLISHED;
		$this->published_revision_id = (int) $revision_id;
		$this->scheduled_at          = null;
		$this->touch();

		$this->events[] = DomainEvent::document(
			DomainEvent::DOCUMENT_PUBLISHED,
			$this->key->value(),
			array(
				'revision' => (int) $revision_id,
				'sections' => count( $this->live_sections ),
				'actor'    => $actor_id,
			)
		);
	}

	/**
	 * Put an earlier published version back on the LIVE page, leaving the draft alone.
	 *
	 * This is the difference between "undo the publish" and "restore a revision":
	 *
	 *   restore_from_revision() replaces the DRAFT   — the editor's work, invisible to visitors.
	 *   revert_live_to()        replaces the LIVE    — what visitors see, right now.
	 *
	 * Someone who has just published a mistake needs the second one, and needs it without losing
	 * whatever they were in the middle of writing.
	 *
	 * @param array $sections    Sections from the earlier published revision.
	 * @param int   $revision_id That revision's id.
	 * @return void
	 */
	public function revert_live_to( array $sections, $revision_id ) {
		$this->live_sections         = $sections;
		$this->status                = DocumentStatus::PUBLISHED;
		$this->published_revision_id = (int) $revision_id;
		$this->touch();

		$this->events[] = DomainEvent::document(
			DomainEvent::PUBLISH_REVERTED,
			$this->key->value(),
			array(
				'revision' => (int) $revision_id,
				'sections' => count( $sections ),
			)
		);
	}

	/**
	 * Arrange a future publish.
	 *
	 * @param int $timestamp UTC timestamp.
	 * @return void
	 */
	public function schedule( $timestamp ) {
		$this->scheduled_at = (int) $timestamp;
		$this->status       = DocumentStatus::SCHEDULED;
		$this->touch();

		$this->events[] = DomainEvent::document(
			DomainEvent::DOCUMENT_SCHEDULED,
			$this->key->value(),
			array( 'scheduled_at' => (int) $timestamp )
		);
	}

	/**
	 * Cancel a pending scheduled publish.
	 *
	 * @return void
	 */
	public function unschedule() {
		if ( null === $this->scheduled_at ) {
			return;
		}

		$this->scheduled_at = null;
		$this->status       = $this->has_live_content() ? DocumentStatus::PUBLISHED : DocumentStatus::DRAFT;
		$this->touch();
	}

	/**
	 * Restore a revision INTO THE DRAFT — never straight to live.
	 *
	 * One click should not change the public homepage. Publishing stays a separate, deliberate act.
	 *
	 * @param SectionCollection $sections    Sections from the revision.
	 * @param int               $revision_id Source revision.
	 * @return void
	 */
	public function restore_from_revision( SectionCollection $sections, $revision_id ) {
		$this->sections = $sections;
		$this->touch();

		$this->events[] = DomainEvent::document(
			DomainEvent::DOCUMENT_ROLLED_BACK,
			$this->key->value(),
			array(
				'from_revision' => (int) $revision_id,
				'sections'      => $sections->count(),
			)
		);
	}

	/**
	 * Record an event raised outside the aggregate (blocked fields, exports…).
	 *
	 * @param DomainEvent $event Event.
	 * @return void
	 */
	public function record( DomainEvent $event ) {
		$this->events[] = $event;
	}

	/**
	 * Take the buffered events. Called by the handler AFTER a successful save.
	 *
	 * @return DomainEvent[]
	 */
	public function release_events() {
		$events       = $this->events;
		$this->events = array();
		return $events;
	}

	/**
	 * Bump the version. Private on purpose: nothing outside the aggregate may set it.
	 *
	 * @return void
	 */
	private function touch() {
		$this->version = $this->version->next();
	}

	/** @return array */
	public function to_array() {
		return array(
			'id'                    => $this->id,
			'key'                   => $this->key->value(),
			'title'                 => $this->title,
			'status'                => $this->status,
			'version'               => $this->version->value(),
			'sections'              => $this->sections->to_array(),
			'live_sections'         => $this->live_sections,
			'scheduled_at'          => $this->scheduled_at,
			'published_revision_id' => $this->published_revision_id,
			'bound_page_id'         => $this->bound_page_id,
		);
	}
}
