<?php
/**
 * Taxonomy adapter.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Infrastructure\Adapter;

use Yazan\Homepage\Domain\Port\TermQueryPort;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads product categories, collections and tags.
 */
final class WpTermQueryAdapter implements TermQueryPort {

	/** Taxonomies a homepage section may reference. */
	const ALLOWED = array( 'product_cat', 'collection', 'product_tag' );

	/**
	 * @param string $taxonomy Taxonomy.
	 * @param int[]  $ids      Term ids; empty = most-used first.
	 * @param int    $limit    Max terms.
	 * @return array<int,array>
	 */
	public function terms( $taxonomy, array $ids = array(), $limit = 20 ) {
		if ( ! in_array( $taxonomy, self::ALLOWED, true ) ) {
			return array();
		}

		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => max( 1, min( 100, (int) $limit ) ),
		);

		if ( $ids ) {
			$args['include'] = array_map( 'absint', $ids );
			$args['orderby'] = 'include';
		} else {
			$args['orderby'] = 'count';
			$args['order']   = 'DESC';
			$default         = (int) get_option( 'default_product_cat' );
			if ( $default ) {
				$args['exclude'] = array( $default );
			}
		}

		$terms = get_terms( $args );

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$out = array();

		foreach ( $terms as $term ) {
			$link = get_term_link( $term );

			$out[] = array(
				'id'    => (int) $term->term_id,
				'name'  => (string) $term->name,
				'slug'  => (string) $term->slug,
				'url'   => is_wp_error( $link ) ? '' : (string) $link,
				'image' => (int) get_term_meta( $term->term_id, 'thumbnail_id', true ),
				'count' => (int) $term->count,
			);
		}

		return $out;
	}
}
