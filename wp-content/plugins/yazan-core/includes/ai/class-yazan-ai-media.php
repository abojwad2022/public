<?php
/**
 * Yazan AI — media sideload.
 *
 * Persists an AI-generated image (base64) into the WordPress media library and tags it with a
 * provenance flag (`_yz_ai_generated`) so AI imagery is always distinguishable from real photography —
 * important for a house built on authenticity. Returns the attachment id + URL for review; the caller
 * decides whether to attach it to a product gallery (never automatic).
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base64 image → media library attachment.
 */
class Yazan_AI_Media {

	/** Marks an attachment as AI-generated (provenance). */
	const PROVENANCE_META = '_yz_ai_generated';

	/**
	 * Sideload a base64 image into the media library.
	 *
	 * @param string $b64        Base64 image data.
	 * @param string $mime       MIME type (image/png|jpeg|webp).
	 * @param int    $product_id Optional parent product id.
	 * @param string $alt        Optional alt text / title.
	 * @return array{id:int,url:string}|WP_Error
	 */
	public static function sideload_base64( $b64, $mime, $product_id = 0, $alt = '' ) {
		$bytes = base64_decode( (string) $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $bytes || '' === $bytes ) {
			return new WP_Error( 'yazan_ai_bad_image', __( 'The generated image data was invalid.', 'yazan' ) );
		}
		if ( strlen( $bytes ) > 12 * 1024 * 1024 ) {
			return new WP_Error( 'yazan_ai_image_too_large', __( 'The generated image was too large.', 'yazan' ) );
		}

		$mime = strtolower( (string) $mime );
		if ( false !== strpos( $mime, 'jpeg' ) || false !== strpos( $mime, 'jpg' ) ) {
			$ext = 'jpg';
		} elseif ( false !== strpos( $mime, 'webp' ) ) {
			$ext = 'webp';
		} else {
			$ext  = 'png';
			$mime = 'image/png';
		}

		$filename = 'yazan-ai-' . absint( $product_id ) . '-' . substr( md5( $bytes . microtime() ), 0, 8 ) . '.' . $ext;

		$upload = wp_upload_bits( $filename, null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'yazan_ai_upload_failed', (string) $upload['error'] );
		}

		$filetype   = wp_check_filetype( $upload['file'], null );
		$attachment = array(
			'post_mime_type' => $filetype['type'] ? $filetype['type'] : $mime,
			'post_title'     => sanitize_text_field( '' !== $alt ? $alt : 'YAZAN AI image' ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attach_id = wp_insert_attachment( $attachment, $upload['file'], absint( $product_id ) );
		if ( is_wp_error( $attach_id ) || ! $attach_id ) {
			return is_wp_error( $attach_id ) ? $attach_id : new WP_Error( 'yazan_ai_attach_failed', __( 'Could not create the media attachment.', 'yazan' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$meta = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
		wp_update_attachment_metadata( $attach_id, $meta );

		update_post_meta( $attach_id, self::PROVENANCE_META, 1 );
		if ( '' !== $alt ) {
			update_post_meta( $attach_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}

		return array( 'id' => (int) $attach_id, 'url' => (string) wp_get_attachment_url( $attach_id ) );
	}
}
