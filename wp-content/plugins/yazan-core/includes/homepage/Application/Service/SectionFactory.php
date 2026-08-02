<?php
/**
 * Builds a valid Section from a component type.
 *
 * The only place a Section is created from user input. Going through a factory is what guarantees
 * that a new section always starts complete — every schema field present, every value sanitised —
 * rather than accumulating keys as someone edits it.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Application\Service;

use Yazan\Homepage\Domain\Component\ComponentRegistry;
use Yazan\Homepage\Domain\Exception\UnknownComponentType;
use Yazan\Homepage\Domain\Section\ComponentType;
use Yazan\Homepage\Domain\Section\Section;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Section construction.
 */
final class SectionFactory {

	/** @var ComponentRegistry */
	private $registry;

	/** @var FieldSanitizer */
	private $sanitizer;

	/**
	 * @param ComponentRegistry $registry  Registry.
	 * @param FieldSanitizer    $sanitizer Sanitizer.
	 */
	public function __construct( ComponentRegistry $registry, FieldSanitizer $sanitizer ) {
		$this->registry  = $registry;
		$this->sanitizer = $sanitizer;
	}

	/**
	 * @param string $type   Component type.
	 * @param array  $values Optional initial values.
	 * @param string $label  Optional editor label.
	 * @return Section
	 * @throws UnknownComponentType When the type is not registered.
	 */
	public function make( $type, array $values = array(), $label = '' ) {
		$definition = $this->registry->get( $type );

		if ( ! $definition ) {
			throw ( new UnknownComponentType( 'Unknown component type: ' . $type ) )
				->with_context( array( 'type' => $type ) );
		}

		$content = $this->sanitizer->sanitize(
			$definition->schema(),
			$values,
			$definition->defaults()
		);

		return Section::create( ComponentType::from( $type ), $content, $label );
	}

	/**
	 * Rebuild a section from an untrusted array (import, template application).
	 *
	 * @param array $data Section data.
	 * @return Section
	 * @throws UnknownComponentType When the type is not registered.
	 */
	public function rehydrate( array $data ) {
		$type       = isset( $data['type'] ) ? (string) $data['type'] : '';
		$definition = $this->registry->get( $type );

		if ( ! $definition ) {
			throw ( new UnknownComponentType( 'Unknown component type: ' . $type ) )
				->with_context( array( 'type' => $type ) );
		}

		$section = $this->make(
			$type,
			isset( $data['content'] ) ? (array) $data['content'] : array(),
			isset( $data['label'] ) ? (string) $data['label'] : ''
		);

		if ( isset( $data['state'] ) ) {
			$section = $section->with_state( (string) $data['state'] );
		}

		if ( isset( $data['design'] ) && is_array( $data['design'] ) ) {
			$section = $section->with_design( $data['design'] );
		}

		return $section;
	}
}
