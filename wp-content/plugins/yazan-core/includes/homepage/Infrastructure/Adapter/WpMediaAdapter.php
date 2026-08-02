<?php
/**
 * Media adapter — the WordPress media library.
 *
 * SVG is absent from every allow-list on purpose: WordPress does not sanitise SVG, an uploaded one
 * executes script in the page's origin, and "we will sanitise it later" is how that stays unfixed.
 * Icons come from a closed set shipped with the module instead.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Infrastructure\Adapter;

use Yazan\Homepage\Domain\Port\MediaPort;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves attachments for renderers and validates them on write.
 */
final class WpMediaAdapter implements MediaPort {

	/** Image types the builder may reference. */
	const IMAGE_MIMES = array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif' );

	/** Video types the builder may reference. */
	const VIDEO_MIMES = array( 'video/mp4', 'video/webm' );

	/**
	 * @param int      $attachment_id Attachment id.
	 * @param string[] $allowed_mimes Allowed mimes; empty = images.
	 * @return bool
	 */
	public function is_allowed( $attachment_id, array $allowed_mimes = array() ) {
		$attachment_id = (int) $attachment_id;

		if ( $attachment_id <= 0 ) {
			return false;
		}

		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return false;
		}

		$mime    = (string) get_post_mime_type( $attachment_id );
		$allowed = $allowed_mimes ? $allowed_mimes : self::IMAGE_MIMES;

		return in_array( $mime, $allowed, true );
	}

	/**
	 * @param int    $attachment_id Attachment id.
	 * @param string $size          Size.
	 * @return string
	 */
	public function url( $attachment_id, $size = 'full' ) {
		$attachment_id = (int) $attachment_id;

		if ( $attachment_id <= 0 ) {
			return '';
		}

		$url = wp_get_attachment_image_url( $attachment_id, $size );

		return $url ? $url : '';
	}

	/**
	 * @param int    $attachment_id Attachment id.
	 * @param string $size          Size.
	 * @return array
	 */
	public function image( $attachment_id, $size = 'full' ) {
		$attachment_id = (int) $attachment_id;

		$empty = array(
			'id'     => 0,
			'url'    => '',
			'srcset' => '',
			'sizes'  => '',
			'alt'    => '',
			'width'  => 0,
			'height' => 0,
		);

		if ( $attachment_id <= 0 ) {
			return $empty;
		}

		$src = wp_get_attachment_image_src( $attachment_id, $size );

		if ( ! $src ) {
			return $empty;
		}

		return array(
			'id'     => $attachment_id,
			'url'    => (string) $src[0],
			'srcset' => (string) wp_get_attachment_image_srcset( $attachment_id, $size ),
			'sizes'  => (string) wp_get_attachment_image_sizes( $attachment_id, $size ),
			'alt'    => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'width'  => (int) $src[1],
			'height' => (int) $src[2],
		);
	}
}
