<?php
/**
 * Copy a section, directly after the original.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Application\Handler;

use Yazan\Homepage\Domain\Document\DocumentKey;
use Yazan\Homepage\Domain\Section\SectionId;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Duplicates a section.
 */
final class DuplicateSectionHandler extends AbstractHandler {

	/**
	 * @param DocumentKey $key     Document key.
	 * @param string      $id      Section uuid.
	 * @param int|null    $version Expected document version.
	 * @return array
	 */
	public function handle( DocumentKey $key, $id, $version = null ) {
		$this->auth->require_permission( 'homepage.sections.duplicate' );

		$document   = $this->load( $key, $version );
		$section_id = SectionId::from( $id );
		$section    = $document->sections()->get( $section_id );
		$definition = $this->definition_for( $section );

		// Duplicating creates a section, so the create policy applies too — otherwise duplicate
		// would be a way around a component someone is not allowed to add.
		$this->policy->require_create( $definition );

		$new_id = $document->duplicate_section( $section_id, $definition->max_instances() );

		$this->persist( $document );

		return $this->result(
			$document,
			array( 'section' => $document->sections()->get( $new_id )->to_array() )
		);
	}
}
