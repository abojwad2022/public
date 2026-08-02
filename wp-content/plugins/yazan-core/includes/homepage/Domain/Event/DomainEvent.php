<?php
/**
 * A fact about something that already happened.
 *
 * One class rather than twelve: every listener in this module dispatches on the name and reads the
 * payload, so twelve near-identical subclasses would add ceremony without adding a single check.
 * The names are constants here, which is what makes them greppable and typo-proof.
 *
 * Events are collected inside the aggregate and released only AFTER a successful save, so a failed
 * write can never produce an audit row for a change that did not happen.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Event;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable domain event.
 */
final class DomainEvent {

	const SECTION_CREATED    = 'homepage.section_created';
	const SECTION_UPDATED    = 'homepage.section_updated';
	const SECTION_DELETED    = 'homepage.section_deleted';
	const SECTION_DUPLICATED = 'homepage.section_duplicated';
	const SECTION_TOGGLED    = 'homepage.section_toggled';
	const SECTIONS_REORDERED = 'homepage.sections_reordered';
	const DOCUMENT_SAVED     = 'homepage.saved';
	const DOCUMENT_PUBLISHED = 'homepage.published';
	const DOCUMENT_SCHEDULED = 'homepage.scheduled';
	const DOCUMENT_ROLLED_BACK = 'homepage.rolled_back';
	const DOCUMENT_IMPORTED  = 'homepage.imported';
	const DOCUMENT_EXPORTED  = 'homepage.exported';
	const FIELDS_BLOCKED     = 'homepage.permission_blocked';

	/** @var string */
	private $name;

	/** @var string */
	private $object_type;

	/** @var string */
	private $object_id;

	/** @var array */
	private $changes;

	/**
	 * @param string $name        Event name constant.
	 * @param string $object_type Audit object type.
	 * @param string $object_id   Audit object id.
	 * @param array  $changes     Before/after payload.
	 */
	private function __construct( $name, $object_type, $object_id, array $changes ) {
		$this->name        = $name;
		$this->object_type = $object_type;
		$this->object_id   = $object_id;
		$this->changes     = $changes;
	}

	/**
	 * Event about the document as a whole.
	 *
	 * @param string $name    Event name.
	 * @param string $key     Document key.
	 * @param array  $changes Payload.
	 * @return self
	 */
	public static function document( $name, $key, array $changes = array() ) {
		return new self( $name, 'homepage', $key, $changes );
	}

	/**
	 * Event about one section.
	 *
	 * @param string $name       Event name.
	 * @param string $section_id Section uuid.
	 * @param array  $changes    Payload.
	 * @return self
	 */
	public static function section( $name, $section_id, array $changes = array() ) {
		return new self( $name, 'homepage_section', $section_id, $changes );
	}

	/** @return string */
	public function name() {
		return $this->name;
	}

	/** @return string */
	public function object_type() {
		return $this->object_type;
	}

	/** @return string */
	public function object_id() {
		return $this->object_id;
	}

	/** @return array */
	public function changes() {
		return $this->changes;
	}
}
