<?php
/**
 * Reorder the whole section list.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Application\Handler;

use Yazan\Homepage\Domain\Document\DocumentKey;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reorders sections.
 */
final class ReorderSectionsHandler extends AbstractHandler {

	/**
	 * @param DocumentKey $key     Document key.
	 * @param string[]    $order   Every section id, in the new order.
	 * @param int|null    $version Expected document version.
	 * @return array
	 */
	public function handle( DocumentKey $key, array $order, $version = null ) {
		$this->auth->require_permission( 'homepage.sections.sort' );

		$document = $this->load( $key, $version );

		// The aggregate refuses a partial list, so a dropped id cannot silently delete a section.
		$document->reorder( $order );

		$this->persist( $document );

		return $this->result( $document, array( 'sections' => $document->sections()->to_array() ) );
	}
}
