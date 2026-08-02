<?php
/**
 * Whole-document validation, run before every save.
 *
 * Section-level sanitisation already guarantees each payload's shape; this is about the document
 * as a whole — limits, unknown types, required fields — the things no single section can see.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Application\Service;

use Yazan\Homepage\Domain\Component\ComponentRegistry;
use Yazan\Homepage\Domain\Component\ComponentSchema;
use Yazan\Homepage\Domain\Document\HomepageDocument;
use Yazan\Homepage\Domain\Exception\ValidationFailed;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Document invariants that live above a single section.
 */
final class DocumentValidator {

	/** Hard ceiling on sections per document — a runaway import backstop, not a UX limit. */
	const MAX_SECTIONS = 60;

	/** @var ComponentRegistry */
	private $registry;

	/**
	 * @param ComponentRegistry $registry Registry.
	 */
	public function __construct( ComponentRegistry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * @param HomepageDocument $document Document.
	 * @return void
	 * @throws ValidationFailed On any error.
	 */
	public function assert_valid( HomepageDocument $document ) {
		$errors = $this->errors( $document );

		if ( $errors ) {
			throw ( new ValidationFailed( 'The homepage document is not valid.' ) )
				->with_context( array( 'errors' => $errors ) );
		}
	}

	/**
	 * Collect every problem rather than failing on the first — an editor should see all of them at
	 * once, not fix one and discover the next.
	 *
	 * @param HomepageDocument $document Document.
	 * @return array<int,array>
	 */
	public function errors( HomepageDocument $document ) {
		$errors = array();
		$counts = array();

		if ( $document->sections()->count() > self::MAX_SECTIONS ) {
			$errors[] = array(
				'code'    => 'too_many_sections',
				'message' => 'This document has more sections than the limit allows.',
				'limit'   => self::MAX_SECTIONS,
			);
		}

		foreach ( $document->sections()->all() as $section ) {
			$type       = $section->type()->value();
			$definition = $this->registry->get( $type );

			if ( ! $definition ) {
				// Tolerated at render time, refused at save time: saving a document that references
				// a component nobody can edit would strand the content silently.
				$errors[] = array(
					'code'    => 'unknown_component',
					'section' => $section->id()->value(),
					'type'    => $type,
				);
				continue;
			}

			$counts[ $type ] = isset( $counts[ $type ] ) ? $counts[ $type ] + 1 : 1;
			$max             = $definition->max_instances();

			if ( $max > 0 && $counts[ $type ] > $max ) {
				$errors[] = array(
					'code'    => 'too_many_instances',
					'section' => $section->id()->value(),
					'type'    => $type,
					'limit'   => $max,
				);
			}

			foreach ( $this->required_errors( $definition->schema(), $section->content() ) as $field ) {
				$errors[] = array(
					'code'    => 'required_field',
					'section' => $section->id()->value(),
					'type'    => $type,
					'field'   => $field,
				);
			}
		}

		return $errors;
	}

	/**
	 * Required fields that are empty.
	 *
	 * @param ComponentSchema $schema  Schema.
	 * @param array           $content Content.
	 * @return string[]
	 */
	private function required_errors( ComponentSchema $schema, array $content ) {
		$missing = array();

		foreach ( $schema->fields() as $key => $field ) {
			if ( ! $field->is_required() ) {
				continue;
			}

			$value = isset( $content[ $key ] ) ? $content[ $key ] : null;

			if ( is_array( $value ) && array_key_exists( 'desktop', $value ) ) {
				$value = $value['desktop'];
			}

			if ( null === $value || '' === $value || array() === $value || 0 === $value ) {
				$missing[] = $key;
			}
		}

		return $missing;
	}
}
