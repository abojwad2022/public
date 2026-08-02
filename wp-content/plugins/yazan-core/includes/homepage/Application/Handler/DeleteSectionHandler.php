<?php
/**
 * Remove a section from the draft.
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
 * Deletes a section.
 */
final class DeleteSectionHandler extends AbstractHandler {

	/**
	 * @param DocumentKey $key     Document key.
	 * @param string      $id      Section uuid.
	 * @param int|null    $version Expected document version.
	 * @return array
	 */
	public function handle( DocumentKey $key, $id, $version = null ) {
		$document   = $this->load( $key, $version );
		$section_id = SectionId::from( $id );
		$section    = $document->sections()->get( $section_id );

		$this->policy->require_delete( $this->definition_for( $section ) );

		$document->remove_section( $section_id );

		$this->persist( $document );

		return $this->result( $document, array( 'deleted' => $section_id->value() ) );
	}
}
