<?php
/**
 * Yazan Dashboard — taxonomy TERM management (Phase 2).
 *
 * CRUD for product categories, tags, the `collection` taxonomy, and the terms of any global
 * `pa_*` attribute. The taxonomy is always validated against an allow-list built from what
 * WooCommerce actually registers, so a caller can never reach an unrelated taxonomy.
 *
 * Guard rails:
 *  - The store's default product category cannot be deleted (WooCommerce needs it).
 *  - A category cannot be re-parented under itself or one of its own descendants.
 *  - Deleting a term reports how many products used it, so the UI can warn first.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * /terms endpoints.
 */
class Yazan_REST_Terms {

	/** Capability for managing product taxonomy terms (Shop Manager has this). */
	const CAP = 'manage_product_terms';

	/**
	 * Taxonomies this controller is allowed to touch.
	 *
	 * @return string[]
	 */
	public static function allowed_taxonomies() {
		$allowed = array( 'product_cat', 'product_tag', 'product_shipping_class' );

		if ( class_exists( 'Yazan_Core_Taxonomies' ) ) {
			$allowed[] = Yazan_Core_Taxonomies::COLLECTION;
		}
		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			$allowed[] = wc_attribute_taxonomy_name( $attribute->attribute_name );
		}
		return $allowed;
	}

	/**
	 * Register routes. Hook: rest_api_init.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$ns   = Yazan_Dashboard_Auth::NS;
		$perm = Yazan_Dashboard_Auth::require_cap( self::CAP );

		register_rest_route(
			$ns,
			'/terms/(?P<taxonomy>[a-z0-9_\-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'index' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create' ),
					'permission_callback' => $perm,
				),
			)
		);

		register_rest_route(
			$ns,
			'/terms/(?P<taxonomy>[a-z0-9_\-]+)/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'destroy' ),
					'permission_callback' => $perm,
				),
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Read                                                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * GET /terms/{taxonomy}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function index( WP_REST_Request $request ) {
		$taxonomy = self::validate_taxonomy( $request['taxonomy'] );
		if ( is_wp_error( $taxonomy ) ) {
			return $taxonomy;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'search'     => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			)
		);
		if ( is_wp_error( $terms ) ) {
			return new WP_Error( 'yazan_query_failed', $terms->get_error_message(), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'taxonomy'     => $taxonomy,
				'hierarchical' => is_taxonomy_hierarchical( $taxonomy ),
				'items'        => array_map( array( __CLASS__, 'payload' ), $terms ),
			),
			200
		);
	}

	/* --------------------------------------------------------------------- */
	/* Create / update                                                        */
	/* --------------------------------------------------------------------- */

	/**
	 * POST /terms/{taxonomy}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create( WP_REST_Request $request ) {
		$taxonomy = self::validate_taxonomy( $request['taxonomy'] );
		if ( is_wp_error( $taxonomy ) ) {
			return $taxonomy;
		}

		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
		if ( '' === trim( $name ) ) {
			return new WP_Error( 'yazan_invalid', __( 'A name is required.', 'yazan' ), array( 'status' => 400 ) );
		}

		$args = array(
			'description' => sanitize_textarea_field( (string) $request->get_param( 'description' ) ),
		);
		$slug = sanitize_title( (string) $request->get_param( 'slug' ) );
		if ( '' !== $slug ) {
			$args['slug'] = $slug;
		}
		if ( is_taxonomy_hierarchical( $taxonomy ) ) {
			$args['parent'] = absint( $request->get_param( 'parent' ) );
		}

		$result = wp_insert_term( $name, $taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'yazan_term_failed',
				'term_exists' === $result->get_error_code()
					? __( 'A term with that name or slug already exists.', 'yazan' )
					: $result->get_error_message(),
				array( 'status' => 400 )
			);
		}

		$term_id = (int) $result['term_id'];
		self::maybe_set_image( $taxonomy, $term_id, $request );

		Yazan_Dashboard_Audit::log( 'term.create', 'term', $term_id, array( 'taxonomy' => $taxonomy, 'name' => $name ) );

		return new WP_REST_Response( self::payload( get_term( $term_id, $taxonomy ) ), 201 );
	}

	/**
	 * PUT /terms/{taxonomy}/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update( WP_REST_Request $request ) {
		$taxonomy = self::validate_taxonomy( $request['taxonomy'] );
		if ( is_wp_error( $taxonomy ) ) {
			return $taxonomy;
		}
		$term_id = absint( $request['id'] );
		$term    = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof WP_Term ) {
			return self::not_found();
		}

		$args = array();
		if ( null !== $request->get_param( 'name' ) ) {
			$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
			if ( '' === trim( $name ) ) {
				return new WP_Error( 'yazan_invalid', __( 'A name is required.', 'yazan' ), array( 'status' => 400 ) );
			}
			$args['name'] = $name;
		}
		if ( null !== $request->get_param( 'slug' ) ) {
			$args['slug'] = sanitize_title( (string) $request->get_param( 'slug' ) );
		}
		if ( null !== $request->get_param( 'description' ) ) {
			$args['description'] = sanitize_textarea_field( (string) $request->get_param( 'description' ) );
		}

		if ( null !== $request->get_param( 'parent' ) && is_taxonomy_hierarchical( $taxonomy ) ) {
			$parent = absint( $request->get_param( 'parent' ) );
			if ( $parent === $term_id ) {
				return new WP_Error( 'yazan_invalid', __( 'A category cannot be its own parent.', 'yazan' ), array( 'status' => 400 ) );
			}
			// Re-parenting under a descendant would create an orphaned loop.
			if ( $parent && in_array( $parent, get_term_children( $term_id, $taxonomy ), true ) ) {
				return new WP_Error(
					'yazan_invalid',
					__( 'A category cannot be moved inside one of its own sub-categories.', 'yazan' ),
					array( 'status' => 400 )
				);
			}
			$args['parent'] = $parent;
		}

		if ( $args ) {
			$result = wp_update_term( $term_id, $taxonomy, $args );
			if ( is_wp_error( $result ) ) {
				return new WP_Error( 'yazan_term_failed', $result->get_error_message(), array( 'status' => 400 ) );
			}
		}

		self::maybe_set_image( $taxonomy, $term_id, $request );

		Yazan_Dashboard_Audit::log( 'term.update', 'term', $term_id, array( 'taxonomy' => $taxonomy ) );

		return new WP_REST_Response( self::payload( get_term( $term_id, $taxonomy ) ), 200 );
	}

	/* --------------------------------------------------------------------- */
	/* Delete                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * DELETE /terms/{taxonomy}/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function destroy( WP_REST_Request $request ) {
		$taxonomy = self::validate_taxonomy( $request['taxonomy'] );
		if ( is_wp_error( $taxonomy ) ) {
			return $taxonomy;
		}
		$term_id = absint( $request['id'] );
		$term    = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof WP_Term ) {
			return self::not_found();
		}

		// WooCommerce always needs a default product category to fall back on.
		if ( 'product_cat' === $taxonomy && (int) get_option( 'default_product_cat' ) === $term_id ) {
			return new WP_Error(
				'yazan_default_term',
				__( 'This is the store\'s default product category and cannot be deleted.', 'yazan' ),
				array( 'status' => 409 )
			);
		}

		$children = is_taxonomy_hierarchical( $taxonomy ) ? get_term_children( $term_id, $taxonomy ) : array();
		$used_by  = (int) $term->count;

		$deleted = wp_delete_term( $term_id, $taxonomy );
		if ( is_wp_error( $deleted ) || ! $deleted ) {
			return new WP_Error( 'yazan_delete_failed', __( 'Could not delete that term.', 'yazan' ), array( 'status' => 500 ) );
		}

		Yazan_Dashboard_Audit::log(
			'term.delete',
			'term',
			$term_id,
			array( 'taxonomy' => $taxonomy, 'name' => $term->name, 'used_by' => $used_by )
		);

		return new WP_REST_Response(
			array(
				'deleted'          => true,
				'id'               => $term_id,
				'products_touched' => $used_by,
				// Children are promoted to the deleted term's parent by WordPress, not removed.
				'children_moved'   => count( $children ),
			),
			200
		);
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Validate the taxonomy against the allow-list.
	 *
	 * @param string $raw Raw taxonomy from the route.
	 * @return string|WP_Error
	 */
	private static function validate_taxonomy( $raw ) {
		$taxonomy = sanitize_key( (string) $raw );
		if ( ! in_array( $taxonomy, self::allowed_taxonomies(), true ) || ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error(
				'yazan_bad_taxonomy',
				__( 'That taxonomy cannot be managed from the dashboard.', 'yazan' ),
				array( 'status' => 400 )
			);
		}
		return $taxonomy;
	}

	/**
	 * Product categories support a thumbnail stored as a term meta attachment id.
	 *
	 * @param string          $taxonomy Taxonomy.
	 * @param int             $term_id  Term id.
	 * @param WP_REST_Request $request  Request.
	 * @return void
	 */
	private static function maybe_set_image( $taxonomy, $term_id, WP_REST_Request $request ) {
		if ( 'product_cat' !== $taxonomy || null === $request->get_param( 'image_id' ) ) {
			return;
		}
		$image_id = absint( $request->get_param( 'image_id' ) );
		if ( $image_id ) {
			update_term_meta( $term_id, 'thumbnail_id', $image_id );
		} else {
			delete_term_meta( $term_id, 'thumbnail_id' );
		}
	}

	/**
	 * Normalised term representation.
	 *
	 * @param WP_Term $term Term.
	 * @return array
	 */
	private static function payload( $term ) {
		$image_id = 'product_cat' === $term->taxonomy ? (int) get_term_meta( $term->term_id, 'thumbnail_id', true ) : 0;

		return array(
			'id'          => (int) $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'parent'      => (int) $term->parent,
			'description' => $term->description,
			'count'       => (int) $term->count,
			'taxonomy'    => $term->taxonomy,
			'image_id'    => $image_id,
			'image_url'   => $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '',
			'is_default'  => 'product_cat' === $term->taxonomy && (int) get_option( 'default_product_cat' ) === (int) $term->term_id,
		);
	}

	/**
	 * @return WP_Error
	 */
	private static function not_found() {
		return new WP_Error( 'yazan_not_found', __( 'Term not found.', 'yazan' ), array( 'status' => 404 ) );
	}
}
