<?php
/**
 * Get post SEO ability.
 *
 * @package SureRank\Inc\Abilities\Content
 */

namespace SureRank\Inc\Abilities\Content;

use SureRank\Inc\Abilities\Ability_Base;
use SureRank\Inc\API\Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Retrieves SureRank SEO data for a post object.
 */
class Get_Post_Seo extends Ability_Base {
	/**
	 * Constructor.
	 *
	 * @since 1.7.5
	 */
	public function __construct() {
		$this->id          = 'surerank/get-post-seo';
		$this->label       = __( 'Get SureRank Post SEO', 'surerank' );
		$this->description = __( 'Retrieve SureRank SEO settings and inherited defaults for a post, page, or custom post type item.', 'surerank' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.7.5
	 */
	public function get_annotations() {
		return [
			'readonly'      => true,
			'destructive'   => false,
			'idempotent'    => true,
			'priority'      => 1.0,
			'openWorldHint' => false,
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.7.5
	 */
	public function get_input_schema() {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id'   => [
					'type'        => 'integer',
					'description' => __( 'The target post ID.', 'surerank' ),
				],
				'post_type' => [
					'type'        => 'string',
					'description' => __( 'The target post type slug.', 'surerank' ),
				],
			],
			'required'             => [ 'post_id', 'post_type' ],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.7.5
	 */
	public function get_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'data'           => [ 'type' => 'object' ],
				'global_default' => [ 'type' => 'object' ],
			],
		];
	}

	/**
	 * Execute ability.
	 *
	 * @since 1.7.5
	 * @param array<string, mixed> $input Ability input payload.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( $input ) {
		$post_id   = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$post_type = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : '';

		if ( ! $post_id || empty( $post_type ) ) {
			return new \WP_Error(
				'surerank_invalid_post_target',
				__( 'A valid post ID and post type are required.', 'surerank' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! post_type_exists( $post_type ) ) {
			return new \WP_Error(
				'surerank_invalid_post_type',
				__( 'The provided post type slug does not exist.', 'surerank' ),
				[ 'status' => 400 ]
			);
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error(
				'surerank_post_not_found',
				__( 'The provided post ID does not exist.', 'surerank' ),
				[ 'status' => 404 ]
			);
		}

		if ( $post_type !== $post->post_type ) {
			return new \WP_Error(
				'surerank_post_type_mismatch',
				__( 'The provided post type does not match the post ID.', 'surerank' ),
				[ 'status' => 400 ]
			);
		}

		return Post::get_post_data_by_id( $post_id, $post_type, false );
	}
}
