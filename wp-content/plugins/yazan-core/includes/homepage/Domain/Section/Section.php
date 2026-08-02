<?php
/**
 * A single section on the homepage.
 *
 * `content` is an array rather than a typed object per component: components are data-defined, and
 * giving each one a PHP class would break the "a new section is one definition file" promise.
 * Safety comes from FieldSanitizer, which drops every key the component schema does not declare —
 * an unknown key never reaches this object.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Section;

use Yazan\Homepage\Domain\ValueObject\Schedule;
use Yazan\Homepage\Domain\ValueObject\Visibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The section entity.
 */
final class Section {

	/** @var SectionId */
	private $id;

	/** @var ComponentType */
	private $type;

	/** @var string */
	private $state;

	/** @var Visibility */
	private $visibility;

	/** @var Schedule */
	private $schedule;

	/** @var array */
	private $content;

	/** @var array Layout / style / animation payloads (phase 4 systems; carried from day one). */
	private $design;

	/** @var string */
	private $label;

	/**
	 * @param SectionId     $id         Id.
	 * @param ComponentType $type       Type.
	 * @param string        $state      SectionState::*.
	 * @param Visibility    $visibility Visibility.
	 * @param Schedule      $schedule   Schedule.
	 * @param array         $content    Sanitised content payload.
	 * @param array         $design     Layout/style/animation payload.
	 * @param string        $label      Optional editor label.
	 */
	private function __construct( SectionId $id, ComponentType $type, $state, Visibility $visibility, Schedule $schedule, array $content, array $design, $label ) {
		$this->id         = $id;
		$this->type       = $type;
		$this->state      = SectionState::normalize( $state );
		$this->visibility = $visibility;
		$this->schedule   = $schedule;
		$this->content    = $content;
		$this->design     = $design;
		$this->label      = (string) $label;
	}

	/**
	 * Create a brand new section.
	 *
	 * @param ComponentType $type    Type.
	 * @param array         $content Content payload (already sanitised).
	 * @param string        $label   Optional label.
	 * @return self
	 */
	public static function create( ComponentType $type, array $content, $label = '' ) {
		return new self(
			SectionId::generate(),
			$type,
			SectionState::ENABLED,
			Visibility::everywhere(),
			Schedule::always(),
			$content,
			array(),
			$label
		);
	}

	/**
	 * Rebuild from storage.
	 *
	 * @param array $data Serialised section.
	 * @return self
	 */
	public static function from_array( array $data ) {
		return new self(
			SectionId::from( isset( $data['id'] ) ? $data['id'] : '' ),
			ComponentType::from( isset( $data['type'] ) ? $data['type'] : '' ),
			isset( $data['state'] ) ? $data['state'] : SectionState::ENABLED,
			Visibility::from_array( isset( $data['visibility'] ) ? (array) $data['visibility'] : array() ),
			Schedule::from_array( isset( $data['schedule'] ) ? (array) $data['schedule'] : array() ),
			isset( $data['content'] ) ? (array) $data['content'] : array(),
			isset( $data['design'] ) ? (array) $data['design'] : array(),
			isset( $data['label'] ) ? $data['label'] : ''
		);
	}

	/** @return SectionId */
	public function id() {
		return $this->id;
	}

	/** @return ComponentType */
	public function type() {
		return $this->type;
	}

	/** @return string */
	public function state() {
		return $this->state;
	}

	/** @return bool */
	public function is_enabled() {
		return SectionState::ENABLED === $this->state;
	}

	/** @return Visibility */
	public function visibility() {
		return $this->visibility;
	}

	/** @return Schedule */
	public function schedule() {
		return $this->schedule;
	}

	/** @return array */
	public function content() {
		return $this->content;
	}

	/** @return array */
	public function design() {
		return $this->design;
	}

	/** @return string */
	public function label() {
		return $this->label;
	}

	/**
	 * Should this section render right now, for this device?
	 *
	 * @param string $device desktop | tablet | mobile.
	 * @param int    $now    UTC timestamp.
	 * @return bool
	 */
	public function should_render( $device, $now ) {
		return $this->is_enabled()
			&& $this->visibility->on( $device )
			&& $this->schedule->is_active_at( $now );
	}

	/**
	 * @param array $content New content payload.
	 * @return self
	 */
	public function with_content( array $content ) {
		$clone          = clone $this;
		$clone->content = $content;
		return $clone;
	}

	/**
	 * @param array $design New design payload.
	 * @return self
	 */
	public function with_design( array $design ) {
		$clone         = clone $this;
		$clone->design = $design;
		return $clone;
	}

	/**
	 * @param string $state SectionState::*.
	 * @return self
	 */
	public function with_state( $state ) {
		$clone        = clone $this;
		$clone->state = SectionState::normalize( $state );
		return $clone;
	}

	/**
	 * @param Visibility $visibility Visibility.
	 * @return self
	 */
	public function with_visibility( Visibility $visibility ) {
		$clone             = clone $this;
		$clone->visibility = $visibility;
		return $clone;
	}

	/**
	 * @param Schedule $schedule Schedule.
	 * @return self
	 */
	public function with_schedule( Schedule $schedule ) {
		$clone           = clone $this;
		$clone->schedule = $schedule;
		return $clone;
	}

	/**
	 * @param string $label Label.
	 * @return self
	 */
	public function with_label( $label ) {
		$clone        = clone $this;
		$clone->label = (string) $label;
		return $clone;
	}

	/**
	 * A copy carrying a NEW identity — used by duplicate and by template application.
	 *
	 * @return self
	 */
	public function duplicated() {
		$clone     = clone $this;
		$clone->id = SectionId::generate();
		return $clone;
	}

	/** @return array */
	public function to_array() {
		return array(
			'id'         => $this->id->value(),
			'type'       => $this->type->value(),
			'state'      => $this->state,
			'label'      => $this->label,
			'visibility' => $this->visibility->to_array(),
			'schedule'   => $this->schedule->to_array(),
			'content'    => $this->content,
			'design'     => $this->design,
		);
	}
}
