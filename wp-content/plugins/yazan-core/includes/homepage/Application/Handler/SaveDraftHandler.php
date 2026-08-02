<?php
/**
 * Replace the whole draft in one write — the autosave path.
 *
 * The builder holds the entire document in memory and sends it back; doing that as one write keeps
 * the client simple and the version counter meaningful. Every section still goes through the same
 * schema sanitisation and the same field-level permission filter as a single-section edit — the
 * bulk path is NOT a shortcut around either.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Application\Handler;

use Yazan\Homepage\Application\Service\DocumentValidator;
use Yazan\Homepage\Application\Service\FieldSanitizer;
use Yazan\Homepage\Application\Service\PermissionFilter;
use Yazan\Homepage\Application\Service\SectionFactory;
use Yazan\Homepage\Domain\Component\ComponentRegistry;
use Yazan\Homepage\Domain\Design\DesignSchema;
use Yazan\Homepage\Domain\Document\DocumentKey;
use Yazan\Homepage\Domain\Document\SectionCollection;
use Yazan\Homepage\Domain\Port\AuthorizationPort;
use Yazan\Homepage\Domain\Port\EventDispatcherPort;
use Yazan\Homepage\Domain\Port\HomepageRepositoryPort;
use Yazan\Homepage\Domain\Section\Section;
use Yazan\Homepage\Domain\ValueObject\Schedule;
use Yazan\Homepage\Domain\ValueObject\Visibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Saves the full draft.
 */
final class SaveDraftHandler extends AbstractHandler {

	/** @var FieldSanitizer */
	private $sanitizer;

	/** @var PermissionFilter */
	private $filter;

	/** @var SectionFactory */
	private $factory;

	/**
	 * @param HomepageRepositoryPort $documents Repository.
	 * @param AuthorizationPort      $auth      Authorization.
	 * @param ComponentRegistry      $registry  Registry.
	 * @param DocumentValidator      $validator Validator.
	 * @param EventDispatcherPort    $events    Dispatcher.
	 * @param FieldSanitizer         $sanitizer Sanitizer.
	 * @param PermissionFilter       $filter    Field filter.
	 * @param SectionFactory         $factory   Section factory.
	 */
	public function __construct(
		HomepageRepositoryPort $documents,
		AuthorizationPort $auth,
		ComponentRegistry $registry,
		DocumentValidator $validator,
		EventDispatcherPort $events,
		FieldSanitizer $sanitizer,
		PermissionFilter $filter,
		SectionFactory $factory
	) {
		parent::__construct( $documents, $auth, $registry, $validator, $events );

		$this->sanitizer = $sanitizer;
		$this->filter    = $filter;
		$this->factory   = $factory;
	}

	/**
	 * @param DocumentKey $key      Document key.
	 * @param array       $sections Incoming sections.
	 * @param int|null    $version  Expected document version.
	 * @return array
	 */
	public function handle( DocumentKey $key, array $sections, $version = null ) {
		$this->auth->require_permission( 'homepage.edit' );

		$document = $this->load( $key, $version );

		$existing = array();
		foreach ( $document->sections()->all() as $section ) {
			$existing[ $section->id()->value() ] = $section;
		}

		$next    = array();
		$blocked = array();

		foreach ( $sections as $raw ) {
			$raw  = (array) $raw;
			$type = isset( $raw['type'] ) ? (string) $raw['type'] : '';

			$definition = $this->registry->get( $type );

			if ( ! $definition ) {
				/*
				 * An unregistered type is carried through UNCHANGED rather than dropped. The
				 * builder cannot edit it, but a plugin being briefly deactivated must not delete
				 * the content that belongs to it.
				 */
				$id = isset( $raw['id'] ) ? (string) $raw['id'] : '';
				if ( $id && isset( $existing[ $id ] ) ) {
					$next[] = $existing[ $id ];
				}
				continue;
			}

			$id      = isset( $raw['id'] ) ? (string) $raw['id'] : '';
			$current = ( $id && isset( $existing[ $id ] ) ) ? $existing[ $id ] : null;

			if ( $current ) {
				$this->policy->require_edit( $definition );
			} else {
				$this->policy->require_create( $definition );
			}

			$base  = $current ? $current->content() : $definition->defaults();
			$clean = $this->sanitizer->sanitize( $definition->schema(), isset( $raw['content'] ) ? (array) $raw['content'] : array(), $base );

			list( $allowed, $section_blocked ) = $this->filter->apply( $definition->schema(), $clean, $base );

			foreach ( $section_blocked as $entry ) {
				$entry['section'] = $id;
				$blocked[]        = $entry;
			}

			$section = $current
				? $current->with_content( $allowed )
				: $this->factory->make( $type )->with_content( $allowed );

			if ( isset( $raw['design'] ) ) {
				$base_design = $current ? $current->design() : array();

				$clean_design = $this->sanitizer->sanitize( DesignSchema::schema(), (array) $raw['design'], $base_design );

				list( $allowed_design, $design_blocked ) = $this->filter->apply( DesignSchema::schema(), $clean_design, $base_design );

				foreach ( $design_blocked as $entry ) {
					$entry['section'] = $id;
					$blocked[]        = $entry;
				}

				$section = $section->with_design( $allowed_design );
			}

			if ( isset( $raw['label'] ) ) {
				$section = $section->with_label( sanitize_text_field( (string) $raw['label'] ) );
			}
			if ( isset( $raw['state'] ) ) {
				$section = $section->with_state( (string) $raw['state'] );
			}
			if ( isset( $raw['visibility'] ) ) {
				$section = $section->with_visibility( Visibility::from_array( (array) $raw['visibility'] ) );
			}
			if ( isset( $raw['schedule'] ) ) {
				$section = $section->with_schedule( Schedule::from_array( (array) $raw['schedule'] ) );
			}

			$next[] = $section;
		}

		// Deleting by omission requires the delete permission, checked per removed section.
		$kept = array();
		foreach ( $next as $section ) {
			$kept[ $section->id()->value() ] = true;
		}

		foreach ( $existing as $id => $section ) {
			if ( ! isset( $kept[ $id ] ) ) {
				$this->policy->require_delete( $this->definition_for( $section ) );
			}
		}

		/*
		 * The bulk path can reorder just by sending the array differently, so the sort permission
		 * has to be checked HERE too. Without this, a role denied `homepage.sections.sort` could
		 * still reorder the homepage by saving the draft — the exact hole a convenient bulk
		 * endpoint tends to open.
		 */
		$before = array_keys( $existing );
		$after  = array();
		foreach ( $next as $section ) {
			$after[] = $section->id()->value();
		}

		$common_before = array_values( array_intersect( $before, $after ) );
		$common_after  = array_values( array_intersect( $after, $before ) );

		if ( $common_before !== $common_after ) {
			$this->auth->require_permission( 'homepage.sections.sort' );
		}

		$document->replace_sections( SectionCollection::of( $next ), 'draft_save' );

		$this->persist( $document );

		return $this->result(
			$document,
			array(
				'sections' => $document->sections()->to_array(),
				'blocked'  => $blocked,
			)
		);
	}
}
