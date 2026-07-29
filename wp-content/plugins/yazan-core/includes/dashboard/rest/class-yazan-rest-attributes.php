<?php
/**
 * Yazan Dashboard — global product ATTRIBUTE definitions (Phase 2).
 *
 * Manages the `pa_*` attribute definitions themselves (Agate Type, Ring Size, …) as opposed to
 * their terms, which live in Yazan_REST_Terms.
 *
 * Deleting an attribute destroys every term under it and unlinks it from every product, so it
 * requires `manage_woocommerce` (matching wp-admin) and reports its term/product usage first so
 * the UI can warn. Jewelry attributes the dashboard depends on are protected from deletion.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * /attributes endpoints.
 */
class Yazan_REST_Attributes {

	/** WooCommerce requires this capability to manage attribute definitions. */
	const CAP = 'manage_woocommerce';

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
			'/attributes',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'index' ),
					'permission_callback' => Yazan_Dashboard_Auth::require_cap( 'edit_products' ),
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
			'/attributes/(?P<id>\d+)',
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

	/**
	 * GET /attributes — definitions with their terms.
	 *
	 * @return WP_REST_Response
	 */
	public static function index() {
		$jewelry = wp_list_pluck( Yazan_Dashboard_Fields::attribute_fields(), 'taxonomy' );
		$out     = array();

		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			$taxonomy = wc_attribute_taxonomy_name( $attribute->attribute_name );
			$terms    = taxonomy_exists( $taxonomy )
				? get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) )
				: array();

			$out[] = array(
				'id'        => (int) $attribute->attribute_id,
				'name'      => $attribute->attribute_label,
				'slug'      => $attribute->attribute_name,
				'taxonomy'  => $taxonomy,
				'type'      => $attribute->attribute_type,
				'order_by'  => $attribute->attribute_orderby,
				'public'    => (bool) $attribute->attribute_public,
				'jewelry'   => in_array( $taxonomy, $jewelry, true ),
				'terms'     => is_wp_error( $terms ) ? array() : array_map(
					static function ( $t ) {
						return array( 'id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => (int) $t->count );
					},
					$terms
				),
			);
		}

		return new WP_REST_Response( array( 'items' => $out ), 200 );
	}

	/**
	 * POST /attributes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create( WP_REST_Request $request ) {
		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
		if ( '' === trim( $name ) ) {
			return new WP_Error( 'yazan_invalid', __( 'An attribute name is required.', 'yazan' ), array( 'status' => 400 ) );
		}

		$slug = sanitize_title( (string) ( $request->get_param( 'slug' ) ?: $name ) );
		if ( wc_attribute_taxonomy_id_by_name( $slug ) ) {
			return new WP_Error( 'yazan_exists', __( 'An attribute with that slug already exists.', 'yazan' ), array( 'status' => 400 ) );
		}
		// WooCommerce stores the slug in a column that caps out at 28 characters (pa_ + 28).
		if ( strlen( $slug ) > 28 ) {
			return new WP_Error( 'yazan_invalid', __( 'The attribute slug must be 28 characters or fewer.', 'yazan' ), array( 'status' => 400 ) );
		}

		$result = wc_create_attribute(
			array(
				'name'         => $name,
				'slug'         => $slug,
				'type'         => self::clean_type( $request->get_param( 'type' ) ),
				'order_by'     => self::clean_orderby( $request->get_param( 'order_by' ) ),
				'has_archives' => (bool) $request->get_param( 'public' ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'yazan_attribute_failed', $result->get_error_message(), array( 'status' => 400 ) );
		}

		self::flush_caches();
		Yazan_Dashboard_Audit::log( 'attribute.create', 'attribute', (int) $result, array( 'name' => $name, 'slug' => $slug ) );

		return new WP_REST_Response( self::find( (int) $result ), 201 );
	}

	/**
	 * PUT /attributes/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update( WP_REST_Request $request ) {
		$id      = absint( $request['id'] );
		$current = self::find( $id );
		if ( ! $current ) {
			return self::not_found();
		}

		$name = null !== $request->get_param( 'name' )
			? sanitize_text_field( (string) $request->get_param( 'name' ) )
			: $current['name'];
		if ( '' === trim( $name ) ) {
			return new WP_Error( 'yazan_invalid', __( 'An attribute name is required.', 'yazan' ), array( 'status' => 400 ) );
		}

		$result = wc_update_attribute(
			$id,
			array(
				'name'         => $name,
				// Changing the slug renames the taxonomy and would orphan every product link,
				// so the slug is intentionally immutable here.
				'slug'         => $current['slug'],
				'type'         => null !== $request->get_param( 'type' ) ? self::clean_type( $request->get_param( 'type' ) ) : $current['type'],
				'order_by'     => null !== $request->get_param( 'order_by' ) ? self::clean_orderby( $request->get_param( 'order_by' ) ) : $current['order_by'],
				'has_archives' => null !== $request->get_param( 'public' ) ? (bool) $request->get_param( 'public' ) : $current['public'],
			)
		);
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'yazan_attribute_failed', $result->get_error_message(), array( 'status' => 400 ) );
		}

		self::flush_caches();
		Yazan_Dashboard_Audit::log( 'attribute.update', 'attribute', $id, array( 'name' => $name ) );

		return new WP_REST_Response( self::find( $id ), 200 );
	}

	/**
	 * DELETE /attributes/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function destroy( WP_REST_Request $request ) {
		$id      = absint( $request['id'] );
		$current = self::find( $id );
		if ( ! $current ) {
			return self::not_found();
		}

		// The jewelry panel and /verify-ring/ certificate read these directly — removing one would
		// silently break both, so they are protected.
		if ( $current['jewelry'] ) {
			return new WP_Error(
				'yazan_protected',
				sprintf(
					/* translators: %s: attribute label. */
					__( '“%s” is a Yazan jewelry field used by the product editor and the verification certificate, so it cannot be deleted.', 'yazan' ),
					$current['name']
				),
				array( 'status' => 409 )
			);
		}

		$term_count = count( $current['terms'] );
		$deleted    = wc_delete_attribute( $id );
		if ( ! $deleted ) {
			return new WP_Error( 'yazan_delete_failed', __( 'Could not delete that attribute.', 'yazan' ), array( 'status' => 500 ) );
		}

		self::flush_caches();
		Yazan_Dashboard_Audit::log(
			'attribute.delete',
			'attribute',
			$id,
			array( 'name' => $current['name'], 'terms_removed' => $term_count )
		);

		return new WP_REST_Response( array( 'deleted' => true, 'id' => $id, 'terms_removed' => $term_count ), 200 );
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Look up one attribute in the shape index() returns.
	 *
	 * @param int $id Attribute id.
	 * @return array|null
	 */
	private static function find( $id ) {
		foreach ( self::index()->get_data()['items'] as $item ) {
			if ( (int) $item['id'] === (int) $id ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Attribute taxonomies are cached hard by WooCommerce; new ones stay invisible without this.
	 *
	 * @return void
	 */
	private static function flush_caches() {
		delete_transient( 'wc_attribute_taxonomies' );
		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
		}
	}

	/**
	 * @param mixed $value Raw type.
	 * @return string
	 */
	private static function clean_type( $value ) {
		$type  = sanitize_key( (string) $value );
		$types = array_keys( wc_get_attribute_types() );
		return in_array( $type, $types, true ) ? $type : 'select';
	}

	/**
	 * @param mixed $value Raw order_by.
	 * @return string
	 */
	private static function clean_orderby( $value ) {
		$orderby = sanitize_key( (string) $value );
		return in_array( $orderby, array( 'menu_order', 'name', 'name_num', 'id' ), true ) ? $orderby : 'menu_order';
	}

	/**
	 * @return WP_Error
	 */
	private static function not_found() {
		return new WP_Error( 'yazan_not_found', __( 'Attribute not found.', 'yazan' ), array( 'status' => 404 ) );
	}
}
