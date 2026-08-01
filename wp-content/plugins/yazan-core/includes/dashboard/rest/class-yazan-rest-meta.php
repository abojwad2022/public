<?php
/**
 * Yazan Dashboard — reference-data REST controller (yazan/v1).
 *
 * One read-only endpoint that hands the SPA everything it needs to render filters and the editor's
 * select boxes: product categories, tags, the `pa_*` attributes + their terms (incl. which ones are
 * the Yazan jewelry fields), collections, shipping/tax classes, product types & statuses.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * /meta/taxonomies endpoint + a lightweight /stats endpoint for the dashboard home.
 */
class Yazan_REST_Meta {

	/**
	 * Register routes. Hook: rest_api_init.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$ns = Yazan_Dashboard_Auth::NS;

		register_rest_route(
			$ns,
			'/meta/taxonomies',
			// Shared reference data (categories, attributes, statuses) that MetaProvider blocks the
			// whole SPA on, so it is gated on plain dashboard access rather than a module.
			Yazan_REST_Guard::args( WP_REST_Server::READABLE, array( __CLASS__, 'taxonomies' ), 'dashboard.access' )
		);

		register_rest_route(
			$ns,
			'/stats',
			Yazan_REST_Guard::args( WP_REST_Server::READABLE, array( __CLASS__, 'stats' ), 'dashboard.view' )
		);
	}

	/**
	 * GET /meta/taxonomies.
	 *
	 * @return WP_REST_Response
	 */
	public static function taxonomies() {
		return new WP_REST_Response(
			array(
				'categories'      => self::terms_tree( 'product_cat' ),
				'tags'            => self::terms_flat( 'product_tag' ),
				'collections'     => self::terms_flat( Yazan_Dashboard_Fields::COLLECTION_TAX ),
				'attributes'      => self::attributes_with_terms(),
				'jewelry_fields'  => Yazan_Dashboard_Fields::attribute_fields(),
				'shipping_classes' => self::terms_flat( 'product_shipping_class' ),
				'tax_classes'     => self::tax_classes(),
				'product_types'   => wc_get_product_types(),
				'statuses'        => array(
					'publish' => __( 'Published', 'yazan' ),
					'draft'   => __( 'Draft', 'yazan' ),
					'pending' => __( 'Pending', 'yazan' ),
					'private' => __( 'Private', 'yazan' ),
				),
				'stock_statuses'  => array(
					'instock'     => __( 'In stock', 'yazan' ),
					'outofstock'  => __( 'Out of stock', 'yazan' ),
					'onbackorder' => __( 'On backorder', 'yazan' ),
				),
				'verification_statuses' => Yazan_Dashboard_Fields::STATUSES,
				'order_statuses'  => self::order_statuses(),
				'currency'        => array(
					'code'     => get_woocommerce_currency(),
					'symbol'   => html_entity_decode( get_woocommerce_currency_symbol() ),
					'position' => get_option( 'woocommerce_currency_pos' ),
					'decimals' => wc_get_price_decimals(),
				),
			),
			200
		);
	}

	/**
	 * GET /stats — headline counts for the dashboard home.
	 *
	 * @return WP_REST_Response
	 */
	public static function stats() {
		$counts = (array) wp_count_posts( 'product' );
		return new WP_REST_Response(
			array(
				'products'   => array(
					'publish' => isset( $counts['publish'] ) ? (int) $counts['publish'] : 0,
					'draft'   => isset( $counts['draft'] ) ? (int) $counts['draft'] : 0,
					'pending' => isset( $counts['pending'] ) ? (int) $counts['pending'] : 0,
				),
				'outofstock' => self::product_count_by_stock( 'outofstock' ),
				'categories' => (int) wp_count_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) ),
			),
			200
		);
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Flat term list.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return array<int,array{id:int,name:string,slug:string,count:int}>
	 */
	private static function terms_flat( $taxonomy ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}
		$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		return array_map(
			static function ( $t ) {
				return array( 'id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => (int) $t->count );
			},
			$terms
		);
	}

	/**
	 * Hierarchical term list (adds parent id).
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return array
	 */
	private static function terms_tree( $taxonomy ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}
		$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		return array_map(
			static function ( $t ) {
				return array(
					'id'     => (int) $t->term_id,
					'name'   => $t->name,
					'slug'   => $t->slug,
					'parent' => (int) $t->parent,
					'count'  => (int) $t->count,
				);
			},
			$terms
		);
	}

	/**
	 * Global attributes with their terms, tagged with whether they are a Yazan jewelry field.
	 *
	 * @return array
	 */
	private static function attributes_with_terms() {
		$jewelry_taxes = wp_list_pluck( Yazan_Dashboard_Fields::attribute_fields(), 'taxonomy' );
		$out           = array();

		foreach ( wc_get_attribute_taxonomies() as $tax ) {
			$taxonomy = wc_attribute_taxonomy_name( $tax->attribute_name );
			$out[]    = array(
				'id'       => (int) $tax->attribute_id,
				'name'     => $tax->attribute_label,
				'taxonomy' => $taxonomy,
				'slug'     => $tax->attribute_name,
				'jewelry'  => in_array( $taxonomy, $jewelry_taxes, true ),
				'terms'    => self::terms_flat( $taxonomy ),
			);
		}
		return $out;
	}

	/**
	 * Order statuses keyed WITHOUT the `wc-` prefix, which is the form the REST layer and
	 * WC_Order::get_status() both use.
	 *
	 * @return array<string,string>
	 */
	private static function order_statuses() {
		$out = array();
		foreach ( wc_get_order_statuses() as $key => $label ) {
			$out[ str_replace( 'wc-', '', $key ) ] = $label;
		}
		return $out;
	}

	/**
	 * WooCommerce tax classes as {slug,name}.
	 *
	 * @return array
	 */
	private static function tax_classes() {
		$classes = array( array( 'slug' => '', 'name' => __( 'Standard', 'yazan' ) ) );
		foreach ( WC_Tax::get_tax_classes() as $name ) {
			$classes[] = array( 'slug' => sanitize_title( $name ), 'name' => $name );
		}
		return $classes;
	}

	/**
	 * Count products in a given stock status.
	 *
	 * @param string $status Stock status.
	 * @return int
	 */
	private static function product_count_by_stock( $status ) {
		$ids = wc_get_products(
			array(
				'stock_status' => $status,
				'status'       => 'publish',
				'limit'        => -1,
				'return'       => 'ids',
			)
		);
		return is_array( $ids ) ? count( $ids ) : 0;
	}
}
