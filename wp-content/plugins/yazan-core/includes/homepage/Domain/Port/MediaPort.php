<?php
/**
 * Media boundary — the WordPress media library, never a second one.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves attachment ids to renderable data.
 */
interface MediaPort {

	/**
	 * Is this a real attachment whose mime type is allowed?
	 *
	 * @param int      $attachment_id Attachment id.
	 * @param string[] $allowed_mimes Allowed mime types; empty = any image.
	 * @return bool
	 */
	public function is_allowed( $attachment_id, array $allowed_mimes = array() );

	/**
	 * @param int    $attachment_id Attachment id.
	 * @param string $size          Image size.
	 * @return string URL, or '' when missing.
	 */
	public function url( $attachment_id, $size = 'full' );

	/**
	 * Everything a renderer needs: url, srcset, sizes, alt, width, height.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $size          Image size.
	 * @return array
	 */
	public function image( $attachment_id, $size = 'full' );
}
