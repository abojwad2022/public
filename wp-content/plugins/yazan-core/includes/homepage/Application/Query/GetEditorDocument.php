<?php
/**
 * Everything the builder needs to open, in one request.
 *
 * One round trip on purpose: document, component catalog, and the exact permission map the UI uses
 * to decide what to draw. Three separate calls would let the UI render before it knows what the
 * user may do — and the flicker of controls appearing then vanishing is worse than a slower open.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Application\Query;

use Yazan\Homepage\Application\Handler\RevertPublishHandler;
use Yazan\Homepage\Application\Service\PermissionFilter;
use Yazan\Homepage\Domain\Component\ComponentRegistry;
use Yazan\Homepage\Domain\Design\DesignSchema;
use Yazan\Homepage\Domain\Document\DocumentKey;
use Yazan\Homepage\Domain\Policy\SectionPolicy;
use Yazan\Homepage\Domain\Port\AuthorizationPort;
use Yazan\Homepage\Domain\Port\HomepageRepositoryPort;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the editor view of a document.
 */
final class GetEditorDocument {

	/** Module-wide permissions the builder switches on. */
	const UI_PERMISSIONS = array(
		'homepage.view',
		'homepage.edit',
		'homepage.preview',
		'homepage.publish',
		'homepage.rollback',
		'homepage.import',
		'homepage.export',
		'homepage.sections.create',
		'homepage.sections.edit',
		'homepage.sections.delete',
		'homepage.sections.sort',
		'homepage.sections.duplicate',
		'homepage.templates.view',
		'homepage.templates.create',
		'homepage.templates.delete',
		'homepage.design.edit',
		'homepage.layout.edit',
		'homepage.colors.edit',
		'homepage.typography.edit',
		'homepage.animations.edit',
		'homepage.products.edit',
		'media.upload',
		'media.delete',
	);

	/** @var HomepageRepositoryPort */
	private $documents;

	/** @var ComponentRegistry */
	private $registry;

	/** @var AuthorizationPort */
	private $auth;

	/** @var PermissionFilter */
	private $filter;

	/** @var RevertPublishHandler */
	private $revert;

	/**
	 * @param HomepageRepositoryPort $documents Repository.
	 * @param ComponentRegistry      $registry  Registry.
	 * @param AuthorizationPort      $auth      Authorization.
	 * @param PermissionFilter       $filter    Field filter.
	 * @param RevertPublishHandler   $revert    Used only to ask whether an undo target exists.
	 */
	public function __construct(
		HomepageRepositoryPort $documents,
		ComponentRegistry $registry,
		AuthorizationPort $auth,
		PermissionFilter $filter,
		RevertPublishHandler $revert
	) {
		$this->documents = $documents;
		$this->registry  = $registry;
		$this->auth      = $auth;
		$this->filter    = $filter;
		$this->revert    = $revert;
	}

	/**
	 * @param DocumentKey $key Document key.
	 * @return array
	 */
	public function handle( DocumentKey $key ) {
		$document = $this->documents->get( $key );
		$policy   = new SectionPolicy( $this->auth );

		return array(
			'document'   => array(
				'key'                     => $document->key()->value(),
				'title'                   => $document->title(),
				'status'                  => $document->status(),
				'version'                 => $document->version()->value(),
				'sections'                => $document->sections()->to_array(),
				'scheduled_at'            => $document->scheduled_at(),
				'published_revision_id'   => $document->published_revision_id(),
				'has_live_content'        => $document->has_live_content(),
				'has_unpublished_changes' => $document->has_unpublished_changes(),
				// Whether "Undo publish" has anywhere to go. Computed here so the button is drawn
				// only when it would actually work, instead of failing on click.
				'can_revert_publish'      => (bool) $this->revert->target( $key ),
				'bound_page_id'           => $document->bound_page_id(),
				// Where the preview iframe should point. A landing page previews on its own URL,
				// not on the front page — previewing the wrong page is worse than no preview.
				'preview_url'             => $document->bound_page_id()
					? (string) get_permalink( $document->bound_page_id() )
					: home_url( '/' ),
			),
			'components' => $this->catalog(),
			// Shared by every section, so it travels once rather than repeated seventeen times.
			'design'     => DesignSchema::schema()->to_array(),
			'can'        => $this->capabilities(),
			'sections'   => $policy->map( $this->registry->all() ),
		);
	}

	/**
	 * The component catalog, including every field's schema.
	 *
	 * @return array<int,array>
	 */
	public function catalog() {
		$out = array();

		foreach ( $this->registry->all() as $definition ) {
			$out[] = $definition->to_array();
		}

		return $out;
	}

	/**
	 * Module-wide plus field-level permission answers, resolved once.
	 *
	 * @return array<string,bool>
	 */
	public function capabilities() {
		$map = array();

		foreach ( self::UI_PERMISSIONS as $permission ) {
			$map[ $permission ] = $this->auth->can( $permission );
		}

		foreach ( $this->filter->capability_map( DesignSchema::schema() ) as $permission => $allowed ) {
			$map[ $permission ] = $allowed;
		}

		foreach ( $this->registry->all() as $definition ) {
			foreach ( $this->filter->capability_map( $definition->schema() ) as $permission => $allowed ) {
				$map[ $permission ] = $allowed;
			}

			$scoped = $definition->section_permission();
			if ( $scoped ) {
				$map[ $scoped ] = $this->auth->can( $scoped );
			}
		}

		return $map;
	}
}
