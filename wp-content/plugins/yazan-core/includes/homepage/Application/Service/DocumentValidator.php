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
	 * Can this be SAVED? Structural rules only.
	 *
	 * A draft is incomplete by definition — that is what a draft is. Blocking the save because a
	 * heading is still empty means the section cannot be added before it is finished, which is not
	 * a workflow anyone can use: the first thing you do after adding a section is type into it.
	 *
	 * Only rules that would corrupt the document are enforced here: too many sections, and more
	 * instances of a component than it allows.
	 *
	 * @param HomepageDocument $document Document.
	 * @return void
	 * @throws ValidationFailed On a structural problem.
	 */
	public function assert_valid( HomepageDocument $document ) {
		$errors = $this->structural_errors( $document );

		if ( $errors ) {
			throw ( new ValidationFailed( $this->describe( $errors ) ) )
				->with_context( array( 'errors' => $errors ) );
		}
	}

	/**
	 * Can this go LIVE? Structural rules plus everything a visitor would notice.
	 *
	 * Required fields are checked here and only here. Publishing is the moment the document stops
	 * being yours and starts being the shop window.
	 *
	 * @param HomepageDocument $document Document.
	 * @return void
	 * @throws ValidationFailed When something would ship broken.
	 */
	public function assert_publishable( HomepageDocument $document ) {
		$errors = array_merge( $this->structural_errors( $document ), $this->content_errors( $document ) );

		if ( $errors ) {
			throw ( new ValidationFailed( $this->describe( $errors ) ) )
				->with_context( array( 'errors' => $errors ) );
		}
	}

	/**
	 * A sentence an operator can act on, instead of "the document is not valid".
	 *
	 * @param array $errors Errors.
	 * @return string
	 */
	private function describe( array $errors ) {
		$first = $errors[0];

		switch ( $first['code'] ) {
			case 'required_field':
				$definition = $this->registry->get( $first['type'] );
				$label      = $definition ? $definition->label() : $first['type'];
				$field      = $definition && $definition->schema()->field( $first['field'] )
					? $definition->schema()->field( $first['field'] )->label()
					: $first['field'];

				return count( $errors ) > 1
					? sprintf(
						/* translators: 1: field label, 2: section label, 3: how many more problems. */
						__( '"%1$s" is required in the %2$s section (and %3$d more to fix).', 'yazan' ),
						$field,
						$label,
						count( $errors ) - 1
					)
					: sprintf(
						/* translators: 1: field label, 2: section label. */
						__( '"%1$s" is required in the %2$s section.', 'yazan' ),
						$field,
						$label
					);

			case 'too_many_instances':
				return __( 'One of these sections is used more times than it allows.', 'yazan' );

			case 'too_many_sections':
				return __( 'This homepage has more sections than the limit allows.', 'yazan' );

			default:
				return __( 'The homepage cannot be saved yet.', 'yazan' );
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
		return array_merge( $this->structural_errors( $document ), $this->content_errors( $document ) );
	}

	/**
	 * Problems that would corrupt the document itself.
	 *
	 * @param HomepageDocument $document Document.
	 * @return array<int,array>
	 */
	public function structural_errors( HomepageDocument $document ) {
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
				/*
				 * NOT an error.
				 *
				 * The first draft of this class refused to save a document containing a section
				 * whose component is no longer registered. The test suite showed what that
				 * actually means: deactivate the plugin that provides one component and the WHOLE
				 * homepage becomes unsaveable — the editor is locked out of every other section
				 * until someone reactivates it. That is far worse than the problem it prevented.
				 *
				 * An unknown type is refused where it belongs: SectionFactory refuses to CREATE or
				 * IMPORT one. An existing section is carried through untouched, reported as a
				 * warning so the builder can badge it, and simply not rendered.
				 */
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

		}

		return $errors;
	}

	/**
	 * Problems a visitor would see — checked at publish time, not while drafting.
	 *
	 * @param HomepageDocument $document Document.
	 * @return array<int,array>
	 */
	public function content_errors( HomepageDocument $document ) {
		$errors = array();

		foreach ( $document->sections()->all() as $section ) {
			$type       = $section->type()->value();
			$definition = $this->registry->get( $type );

			if ( ! $definition ) {
				continue;
			}

			// A disabled section ships nothing, so an empty required field in it is not a problem.
			if ( ! $section->is_enabled() ) {
				continue;
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
	 * Sections the builder cannot edit because their component is not registered.
	 *
	 * Not failures — information. The UI shows them greyed with "this section's component is not
	 * available", and they render nothing until it returns.
	 *
	 * @param HomepageDocument $document Document.
	 * @return array<int,array{section:string,type:string}>
	 */
	public function warnings( HomepageDocument $document ) {
		$warnings = array();

		foreach ( $document->sections()->all() as $section ) {
			$type = $section->type()->value();

			if ( ! $this->registry->get( $type ) ) {
				$warnings[] = array(
					'section' => $section->id()->value(),
					'type'    => $type,
				);
			}
		}

		return $warnings;
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
