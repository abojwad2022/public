<?php
/**
 * Modified Date Lock Handler.
 *
 * @package SureRank\Inc\Functions
 * @since 1.7.5
 */

namespace SureRank\Inc\Functions;

use SureRank\Inc\Traits\Get_Instance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Enables filter-based control over preserving WordPress modified timestamps.
 */
class Modified_Date_Lock {
	use Get_Instance;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'wp_insert_post_data', [ $this, 'maybe_preserve_modified_date' ], 10, 4 );
	}

	/**
	 * Preserve post_modified values on updates when the SureRank filter opts in.
	 *
	 * @param array<string, mixed> $data                Sanitized post data to be inserted.
	 * @param array<string, mixed> $postarr             Raw and sanitized post data.
	 * @param array<string, mixed> $unsanitized_postarr Original unchanged post data.
	 * @param bool                 $update              Whether this is an existing post being updated.
	 * @return array<string, mixed>
	 */
	public function maybe_preserve_modified_date( array $data, array $postarr, array $unsanitized_postarr, bool $update ): array {
		if ( ! $update ) {
			return $data;
		}

		$post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		if ( $post_id <= 0 || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return $data;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return $data;
		}

		/**
		 * Allow developers to freeze modified timestamps for a post update.
		 *
		 * Return true to preserve current database values for `post_modified`
		 * and `post_modified_gmt` during this update.
		 *
		 * @since 1.7.5
		 *
		 * @param bool                 $freeze             Whether to freeze modified timestamps. Default false.
		 * @param int                  $post_id            Post ID being updated.
		 * @param \WP_Post             $post               Current post object from database (pre-update).
		 * @param array<string, mixed> $data               Outgoing sanitized post data.
		 * @param array<string, mixed> $postarr            Post data array.
		 * @param array<string, mixed> $unsanitized_postarr Original unchanged post data.
		 */
		$freeze_modified_date = (bool) apply_filters( 'surerank_freeze_modified_date', false, $post_id, $post, $data, $postarr, $unsanitized_postarr );
		if ( ! $freeze_modified_date ) {
			return $data;
		}

		$data['post_modified']     = $post->post_modified;
		$data['post_modified_gmt'] = $post->post_modified_gmt;

		return $data;
	}
}
