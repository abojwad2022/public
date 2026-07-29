<?php
/**
 * Yazan Dashboard — Media REST controller (yazan/v1).
 *
 * Uploads go straight into the WordPress Media Library (real attachments), so images are shared with
 * the storefront and every other tool. Uses core's media_handle_upload() — $_FILES is never trusted
 * directly — with a MIME allow-list (images + PDF for certificates).
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * /media endpoints.
 */
class Yazan_REST_Media {

	/** Allowed upload MIME types. */
	const ALLOWED = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif', 'application/pdf' );

	/**
	 * Register routes. Hook: rest_api_init.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$ns = Yazan_Dashboard_Auth::NS;

		register_rest_route(
			$ns,
			'/media',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'index' ),
					'permission_callback' => Yazan_Dashboard_Auth::require_cap( 'upload_files' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'upload' ),
					'permission_callback' => Yazan_Dashboard_Auth::require_cap( 'upload_files' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/media/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'destroy' ),
				'permission_callback' => Yazan_Dashboard_Auth::require_cap( 'upload_files' ),
			)
		);
	}

	/**
	 * GET /media — recent library images for the picker.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function index( WP_REST_Request $request ) {
		$per_page = max( 1, min( 80, absint( $request->get_param( 'per_page' ) ?: 40 ) ) );
		$page     = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
		$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );

		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => $per_page,
				'paged'          => $page,
				's'              => $search,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = self::attachment_payload( $post->ID );
		}

		return new WP_REST_Response(
			array(
				'items'       => $items,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
				'page'        => $page,
			),
			200
		);
	}

	/**
	 * POST /media — upload a file into the Media Library.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function upload( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new WP_Error( 'yazan_no_file', __( 'No file was uploaded.', 'yazan' ), array( 'status' => 400 ) );
		}

		// Validate declared type against our allow-list before touching the filesystem.
		$check = wp_check_filetype_and_ext( $files['file']['tmp_name'], $files['file']['name'] );
		$type  = $check['type'] ? $check['type'] : ( isset( $files['file']['type'] ) ? sanitize_mime_type( $files['file']['type'] ) : '' );
		if ( ! in_array( $type, self::ALLOWED, true ) ) {
			return new WP_Error( 'yazan_bad_type', __( 'That file type is not allowed.', 'yazan' ), array( 'status' => 415 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		// Restrict the overall upload_mimes to our allow-list for the duration of this call.
		$restrict = static function () {
			return array(
				'jpg|jpeg|jpe' => 'image/jpeg',
				'png'          => 'image/png',
				'webp'         => 'image/webp',
				'gif'          => 'image/gif',
				'avif'         => 'image/avif',
				'pdf'          => 'application/pdf',
			);
		};
		add_filter( 'upload_mimes', $restrict );
		$attachment_id = media_handle_upload( 'file', 0 );
		remove_filter( 'upload_mimes', $restrict );

		if ( is_wp_error( $attachment_id ) ) {
			return new WP_Error( 'yazan_upload_failed', $attachment_id->get_error_message(), array( 'status' => 400 ) );
		}

		Yazan_Dashboard_Audit::log( 'media.upload', 'media', $attachment_id );

		return new WP_REST_Response( self::attachment_payload( $attachment_id ), 201 );
	}

	/**
	 * DELETE /media/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function destroy( WP_REST_Request $request ) {
		$id = absint( $request['id'] );
		if ( 'attachment' !== get_post_type( $id ) ) {
			return new WP_Error( 'yazan_not_found', __( 'Attachment not found.', 'yazan' ), array( 'status' => 404 ) );
		}
		$force  = wc_string_to_bool( $request->get_param( 'force' ) );
		$result = wp_delete_attachment( $id, $force );
		if ( ! $result ) {
			return new WP_Error( 'yazan_delete_failed', __( 'Could not delete the file.', 'yazan' ), array( 'status' => 500 ) );
		}

		Yazan_Dashboard_Audit::log( 'media.delete', 'media', $id );

		return new WP_REST_Response( array( 'deleted' => true, 'id' => $id ), 200 );
	}

	/**
	 * Normalised attachment representation.
	 *
	 * @param int $id Attachment id.
	 * @return array
	 */
	private static function attachment_payload( $id ) {
		$id = absint( $id );
		return array(
			'id'    => $id,
			'url'   => wp_get_attachment_url( $id ),
			'thumb' => wp_get_attachment_image_url( $id, 'thumbnail' ),
			'medium' => wp_get_attachment_image_url( $id, 'medium' ),
			'title' => get_the_title( $id ),
			'mime'  => get_post_mime_type( $id ),
			'alt'   => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
		);
	}
}
