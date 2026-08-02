<?php
/**
 * The template library: save, apply, delete.
 *
 * One class for three small operations that share every dependency. Splitting them into three
 * files would triple the wiring without separating anything that actually varies.
 *
 * The rule that matters: applying a template is NOT a privileged path. Every section it produces
 * goes through SectionFactory — so it is re-sanitised against the current schema — and through the
 * same SectionPolicy as adding one by hand. A template saved when a component allowed more can
 * never smuggle content past today's rules.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Application\Handler;

use Yazan\Homepage\Application\Service\DocumentValidator;
use Yazan\Homepage\Application\Service\SectionFactory;
use Yazan\Homepage\Domain\Component\ComponentRegistry;
use Yazan\Homepage\Domain\Document\DocumentKey;
use Yazan\Homepage\Domain\Document\SectionCollection;
use Yazan\Homepage\Domain\Exception\SectionNotFound;
use Yazan\Homepage\Domain\Exception\ValidationFailed;
use Yazan\Homepage\Domain\Port\AuthorizationPort;
use Yazan\Homepage\Domain\Port\EventDispatcherPort;
use Yazan\Homepage\Domain\Port\HomepageRepositoryPort;
use Yazan\Homepage\Domain\Port\RevisionRepositoryPort;
use Yazan\Homepage\Domain\Port\TemplateRepositoryPort;
use Yazan\Homepage\Domain\Section\Section;
use Yazan\Homepage\Domain\Section\SectionId;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template use cases.
 */
final class TemplateHandler extends AbstractHandler {

	/** @var TemplateRepositoryPort */
	private $templates;

	/** @var RevisionRepositoryPort */
	private $revisions;

	/** @var SectionFactory */
	private $factory;

	/**
	 * @param HomepageRepositoryPort $documents Repository.
	 * @param AuthorizationPort      $auth      Authorization.
	 * @param ComponentRegistry      $registry  Registry.
	 * @param DocumentValidator      $validator Validator.
	 * @param EventDispatcherPort    $events    Dispatcher.
	 * @param TemplateRepositoryPort $templates Templates.
	 * @param RevisionRepositoryPort $revisions Revisions.
	 * @param SectionFactory         $factory   Section factory.
	 */
	public function __construct(
		HomepageRepositoryPort $documents,
		AuthorizationPort $auth,
		ComponentRegistry $registry,
		DocumentValidator $validator,
		EventDispatcherPort $events,
		TemplateRepositoryPort $templates,
		RevisionRepositoryPort $revisions,
		SectionFactory $factory
	) {
		parent::__construct( $documents, $auth, $registry, $validator, $events );

		$this->templates = $templates;
		$this->revisions = $revisions;
		$this->factory   = $factory;
	}

	/**
	 * Everything saved, with the component label resolved for display.
	 *
	 * @return array
	 */
	public function listing() {
		$this->auth->require_permission( 'homepage.templates.view' );

		$rows = $this->templates->all();

		foreach ( $rows as $index => $row ) {
			$definition                  = $this->registry->get( (string) $row['component_type'] );
			$rows[ $index ]['label']     = $definition ? $definition->label() : (string) $row['component_type'];
			$rows[ $index ]['available'] = 'document' === $row['kind'] || (bool) $definition;
			$rows[ $index ]['shared']    = 'global' === $row['kind'];
		}

		return array( 'templates' => $rows );
	}

	/**
	 * Save one section, or the whole draft, as a template.
	 *
	 * @param DocumentKey $key        Document key.
	 * @param string      $name       Template name.
	 * @param string|null $section_id Section to save; null saves the whole homepage.
	 * @return array
	 */
	public function save( DocumentKey $key, $name, $section_id = null ) {
		$this->auth->require_permission( 'homepage.templates.create' );

		$name = trim( (string) $name );

		if ( '' === $name ) {
			throw new ValidationFailed( __( 'Give the template a name.', 'yazan' ) );
		}

		$document = $this->documents->get( $key );

		if ( $section_id ) {
			$section    = $document->sections()->get( SectionId::from( $section_id ) );
			$definition = $this->definition_for( $section );

			// You cannot bottle a section you are not allowed to edit.
			$this->policy->require_edit( $definition );

			$id = $this->templates->save(
				'section',
				$name,
				$section->type()->value(),
				array( $section->to_array() ),
				0,
				$this->auth->actor_id()
			);

			return array(
				'id'   => $id,
				'kind' => 'section',
				'name' => $name,
			);
		}

		if ( 0 === $document->sections()->count() ) {
			throw new ValidationFailed( __( 'There is nothing to save yet.', 'yazan' ) );
		}

		$id = $this->templates->save(
			'document',
			$name,
			'',
			$document->sections()->to_array(),
			0,
			$this->auth->actor_id()
		);

		return array(
			'id'   => $id,
			'kind' => 'document',
			'name' => $name,
		);
	}

	/**
	 * Turn a section in this document into a SHARED one.
	 *
	 * The section is stored once, and the document keeps a reference to it in the same position.
	 * From then on every page pointing at it renders the same content — including this one.
	 *
	 * @param DocumentKey $key        Document key.
	 * @param string      $section_id Section to share.
	 * @param string      $name       Name for the shared section.
	 * @param int|null    $version    Expected document version.
	 * @return array
	 */
	public function make_global( DocumentKey $key, $section_id, $name, $version = null ) {
		$this->auth->require_permission( 'homepage.templates.create' );

		$name = trim( (string) $name );

		if ( '' === $name ) {
			throw new ValidationFailed( __( 'Give the shared section a name.', 'yazan' ) );
		}

		$document = $this->load( $key, $version );
		$id       = SectionId::from( $section_id );
		$section  = $document->sections()->get( $id );

		if ( 'global' === $section->type()->value() ) {
			throw new ValidationFailed( __( 'That section is already shared.', 'yazan' ) );
		}

		$definition = $this->definition_for( $section );

		$this->policy->require_edit( $definition );

		$shared_id = $this->templates->save(
			'global',
			$name,
			$section->type()->value(),
			array( $section->to_array() ),
			0,
			$this->auth->actor_id()
		);

		// The reference takes the section's place, keeping its position, its state and its
		// schedule — those stay per-page, which is what lets one shared band be paused on one
		// page and running on another.
		$reference = $this->factory->make( 'global', array( 'ref' => $shared_id ), $name )
			->with_state( $section->state() )
			->with_visibility( $section->visibility() )
			->with_schedule( $section->schedule() );

		$position = $document->sections()->position_of( $id );

		$document->remove_section( $id );
		$document->add_section( $reference, $position );

		$this->persist( $document );

		return $this->result(
			$document,
			array(
				'shared'   => $shared_id,
				'sections' => $document->sections()->to_array(),
			)
		);
	}

	/**
	 * Break the link: replace a reference with its own private copy.
	 *
	 * @param DocumentKey $key        Document key.
	 * @param string      $section_id The reference.
	 * @param int|null    $version    Expected document version.
	 * @return array
	 */
	public function detach( DocumentKey $key, $section_id, $version = null ) {
		$document  = $this->load( $key, $version );
		$id        = SectionId::from( $section_id );
		$reference = $document->sections()->get( $id );

		if ( 'global' !== $reference->type()->value() ) {
			throw new ValidationFailed( __( 'That section is not a shared one.', 'yazan' ) );
		}

		$content = $reference->content();
		$row     = $this->templates->find( (int) ( $content['ref'] ?? 0 ) );

		if ( ! $row || 'global' !== $row['kind'] ) {
			throw new SectionNotFound( __( 'The shared section it points at is gone.', 'yazan' ) );
		}

		$copy = $this->factory->rehydrate( (array) ( $row['payload'][0] ?? array() ) );

		$this->policy->require_create( $this->definition_for( $copy ) );

		$copy = $copy
			->with_state( $reference->state() )
			->with_visibility( $reference->visibility() )
			->with_schedule( $reference->schedule() );

		$position = $document->sections()->position_of( $id );

		$document->remove_section( $id );
		$document->add_section( $copy, $position );

		$this->persist( $document );

		return $this->result(
			$document,
			array( 'sections' => $document->sections()->to_array() )
		);
	}

	/**
	 * Apply a template to the draft.
	 *
	 * A section template is APPENDED. A document template REPLACES the draft — and because that
	 * throws work away, it needs the same permissions an import does and snapshots first.
	 *
	 * @param DocumentKey $key      Document key.
	 * @param int         $id       Template id.
	 * @param int|null    $position Where to insert a section template.
	 * @param int|null    $version  Expected document version.
	 * @return array
	 */
	public function apply( DocumentKey $key, $id, $position = null, $version = null ) {
		$template = $this->templates->find( (int) $id );

		if ( ! $template ) {
			throw ( new SectionNotFound( __( 'That template does not exist.', 'yazan' ) ) )
				->with_context( array( 'template' => (int) $id ) );
		}

		$document = $this->load( $key, $version );

		if ( 'document' === $template['kind'] ) {
			$this->auth->require_permission( 'homepage.sections.create' );
			$this->auth->require_permission( 'homepage.sections.delete' );
			$this->auth->require_permission( 'homepage.sections.sort' );

			$this->revisions->append( $document, $this->auth->actor_id(), 'before_template', false );

			$sections = array();

			foreach ( (array) $template['payload'] as $raw ) {
				// New identities: applying the same template twice must not produce two sections
				// claiming to be the same one.
				$sections[] = $this->factory->rehydrate( (array) $raw );
			}

			// No field-level diff: applying a template replaces every section with a new identity, so a
			// per-field list would be one 'added' line per section and nothing anyone can read.
			$document->replace_sections( SectionCollection::of( $sections ), 'template', false );

			$this->persist( $document );

			return $this->result(
				$document,
				array(
					'applied'  => (int) $id,
					'sections' => $document->sections()->to_array(),
				)
			);
		}

		$raw        = (array) ( $template['payload'][0] ?? array() );
		$definition = $this->definition( (string) ( $raw['type'] ?? '' ) );

		$this->policy->require_create( $definition );

		if ( 'global' === $template['kind'] ) {
			// A shared section is inserted by REFERENCE. Copying it here would produce something
			// that looks shared in the list and stops being shared the moment anyone edits it.
			$section = $this->factory->make( 'global', array( 'ref' => (int) $id ), (string) $template['name'] );
		} else {
			$section = $this->factory->rehydrate( $raw );
		}

		$document->add_section( $section, $position, $definition->max_instances() );

		$this->persist( $document );

		return $this->result(
			$document,
			array(
				'applied' => (int) $id,
				'section' => $section->to_array(),
			)
		);
	}

	/**
	 * @param int $id Template id.
	 * @return array
	 */
	public function remove( $id ) {
		$this->auth->require_permission( 'homepage.templates.delete' );

		return array( 'deleted' => (bool) $this->templates->delete( (int) $id ) );
	}
}
