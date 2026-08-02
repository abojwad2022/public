<?php
/**
 * Template persistence boundary.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores reusable section and homepage templates.
 */
interface TemplateRepositoryPort {

	/**
	 * @param string $kind           section | document.
	 * @param string $name           Template name.
	 * @param string $component_type Component type for a section template; '' for a document.
	 * @param array  $payload        Sections payload.
	 * @param int    $preview_media  Optional attachment id.
	 * @param int    $author         User id.
	 * @return int New template id.
	 */
	public function save( $kind, $name, $component_type, array $payload, $preview_media, $author );

	/**
	 * @param string $kind Optional kind filter.
	 * @return array<int,array>
	 */
	public function all( $kind = '' );

	/**
	 * @param int $id Template id.
	 * @return array|null
	 */
	public function find( $id );

	/**
	 * @param int $id Template id.
	 * @return bool
	 */
	public function delete( $id );
}
