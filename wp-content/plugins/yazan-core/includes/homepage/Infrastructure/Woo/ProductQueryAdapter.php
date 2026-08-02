<?php
/**
 * WooCommerce query boundary.
 *
 * Components describe WHAT they want (`{ source: 'best_sellers', limit: 4 }`) and this class
 * decides HOW. Each source is a small strategy method, so adding "recommended for this visitor"
 * later is a new branch here — not an edit to any component.
 *
 * Two outputs, deliberately:
 *   query_args() — WP_Query arguments, because the theme's product row builds its own WP_Query.
 *   resolve()    — product data, for the builder's "what will this show?" preview.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Infrastructure\Woo;

use Yazan\Homepage\Domain\Port\ProductQueryPort;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a product-source spec into queries.
 */
final class ProductQueryAdapter implements ProductQueryPort {

	/** Hard ceiling regardless of what a payload asks for. */
	const MAX_LIMIT = 48;

	/** @return string[] */
	public function sources() {
		return array( 'best_sellers', 'latest', 'featured', 'top_rated', 'on_sale', 'category', 'tag', 'attribute', 'manual' );
	}

	/**
	 * WP_Query arguments for a source spec.
	 *
	 * @param array $spec Product query spec.
	 * @return array
	 */
	public function query_args( array $spec ) {
		$source = isset( $spec['source'] ) ? (string) $spec['source'] : 'latest';
		$limit  = max( 1, min( self::MAX_LIMIT, isset( $spec['limit'] ) ? (int) $spec['limit'] : 4 ) );
		$terms  = isset( $spec['terms'] ) ? array_map( 'absint', (array) $spec['terms'] ) : array();
		$ids    = isset( $spec['ids'] ) ? array_map( 'absint', (array) $spec['ids'] ) : array();
		$order  = isset( $spec['order'] ) && 'ASC' === strtoupper( (string) $spec['order'] ) ? 'ASC' : 'DESC';

		$args = array(
			'post_type'           => 'product',
			'post_status'         => 'publish',
			'posts_per_page'      => $limit,
			'ignore_sticky_posts' => true,
			// The homepage never paginates a product row, and counting rows it will not use is
			// the single most expensive part of a WP_Query on a large catalogue.
			'no_found_rows'       => true,
			'order'               => $order,
			'tax_query'           => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'product_visibility',
					'field'    => 'name',
					'terms'    => 'exclude-from-catalog',
					'operator' => 'NOT IN',
				),
			),
		);

		switch ( $source ) {
			case 'best_sellers':
				$args['meta_key'] = 'total_sales'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				$args['orderby']  = 'meta_value_num';
				break;

			case 'top_rated':
				$args['meta_key'] = '_wc_average_rating'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				$args['orderby']  = 'meta_value_num';
				break;

			case 'featured':
				$args['tax_query'][] = array(
					'taxonomy' => 'product_visibility',
					'field'    => 'name',
					'terms'    => 'featured',
					'operator' => 'IN',
				);
				$args['orderby'] = 'date';
				break;

			case 'on_sale':
				$on_sale = function_exists( 'wc_get_product_ids_on_sale' ) ? wc_get_product_ids_on_sale() : array();
				// An empty post__in is IGNORED by WP_Query, which would turn "nothing on sale" into
				// "every product". A sentinel id keeps the empty result empty.
				$args['post__in'] = $on_sale ? $on_sale : array( 0 );
				$args['orderby']  = 'date';
				break;

			case 'category':
			case 'tag':
				if ( $terms ) {
					$args['tax_query'][] = array(
						'taxonomy' => 'category' === $source ? 'product_cat' : 'product_tag',
						'field'    => 'term_id',
						'terms'    => $terms,
					);
				}
				$args['orderby'] = 'date';
				break;

			case 'attribute':
				$taxonomy = isset( $spec['attribute'] ) ? (string) $spec['attribute'] : '';
				if ( $taxonomy && $terms && taxonomy_exists( $taxonomy ) ) {
					$args['tax_query'][] = array(
						'taxonomy' => $taxonomy,
						'field'    => 'term_id',
						'terms'    => $terms,
					);
				}
				$args['orderby'] = 'date';
				break;

			case 'manual':
				$args['post__in'] = $ids ? $ids : array( 0 );
				$args['orderby']  = 'post__in';
				unset( $args['order'] );
				break;

			case 'latest':
			default:
				$args['orderby'] = 'date';
				break;
		}

		if ( isset( $spec['orderby'] ) && 'rand' === $spec['orderby'] ) {
			$args['orderby'] = 'rand';
		}

		return $args;
	}

	/**
	 * Resolve a spec to product data — used by the builder to preview a source.
	 *
	 * @param array $spec Product query spec.
	 * @return array<int,array>
	 */
	public function resolve( array $spec ) {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$query = new \WP_Query( $this->query_args( $spec ) );
		$out   = array();

		foreach ( $query->posts as $post ) {
			$product = wc_get_product( $post );

			if ( ! $product ) {
				continue;
			}

			$out[] = array(
				'id'    => $product->get_id(),
				'name'  => $product->get_name(),
				'sku'   => $product->get_sku(),
				'price' => $product->get_price_html(),
				'url'   => get_permalink( $product->get_id() ),
				'image' => (int) $product->get_image_id(),
			);
		}

		wp_reset_postdata();

		return $out;
	}
}
