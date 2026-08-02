<?php
/**
 * Enable or disable a section.
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
 * Toggles a section.
 */
final class ToggleSectionHandler extends AbstractHandler {

	/**
	 * @param DocumentKey $key     Document key.
	 * @param string      $id      Section uuid.
	 * @param bool        $enabled Desired state.
	 * @param int|null    $version Expected document version.
	 * @return array
	 */
	public function handle( DocumentKey $key, $id, $enabled, $version = null ) {
		$document   = $this->load( $key, $version );
		$section_id = SectionId::from( $id );
		$section    = $document->sections()->get( $section_id );

		$this->policy->require_edit( $this->definition_for( $section ) );

		$document->toggle_section( $section_id, (bool) $enabled );

		$this->persist( $document );

		return $this->result( $document, array( 'section' => $document->sections()->get( $section_id )->to_array() ) );
	}
}
