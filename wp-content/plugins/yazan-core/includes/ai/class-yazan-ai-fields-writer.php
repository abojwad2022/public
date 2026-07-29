<?php
/**
 * Yazan AI — jewelry field resolver.
 *
 * The bridge between what the model SAYS and what WooCommerce STORES. The AI returns attribute values
 * as human labels ("Red Yemeni Aqeeq"); the store keeps them as `pa_*` term ids. This class:
 *
 *  - exposes the allowed choices per attribute so the pipeline can GROUND the model (choose from real
 *    terms instead of inventing values), and
 *  - resolves the model's chosen labels back to term ids, matching by slug, exact name, then a loose
 *    contains — so the result can be handed straight to the existing products controller, which writes
 *    through {@see Yazan_Dashboard_Fields}. Nothing here writes to the database directly; the tested
 *    save path is reused verbatim.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve AI attribute labels to Yazan jewelry term ids.
 */
class Yazan_AI_Fields_Writer {

	/**
	 * Allowed term choices for every Yazan attribute field, shaped for the prompt and the UI.
	 *
	 * @return array<int,array{key:string,label:string,taxonomy:string,choices:string[]}>
	 */
	public static function attribute_choices() {
		$out = array();
		foreach ( Yazan_Dashboard_Fields::attribute_fields() as $field ) {
			$choices = array();
			if ( taxonomy_exists( $field['taxonomy'] ) ) {
				$terms = get_terms(
					array(
						'taxonomy'   => $field['taxonomy'],
						'hide_empty' => false,
					)
				);
				if ( ! is_wp_error( $terms ) ) {
					foreach ( $terms as $term ) {
						$choices[] = $term->name;
					}
				}
			}
			$out[] = array(
				'key'      => $field['key'],
				'label'    => $field['label'],
				'taxonomy' => $field['taxonomy'],
				'choices'  => $choices,
			);
		}
		return $out;
	}

	/**
	 * Resolve a map of { field_key => label } into { field_key => term_id }, plus a list of labels
	 * that could not be matched (so the UI can flag them for manual selection).
	 *
	 * @param array $labels field_key => chosen label string.
	 * @return array{ids:array<string,int>,unresolved:array<int,array{key:string,value:string}>}
	 */
	public static function resolve( array $labels ) {
		$by_key = array();
		foreach ( Yazan_Dashboard_Fields::attribute_fields() as $field ) {
			$by_key[ $field['key'] ] = $field['taxonomy'];
		}

		$ids        = array();
		$unresolved = array();

		foreach ( $labels as $key => $value ) {
			$key = sanitize_key( $key );
			if ( ! isset( $by_key[ $key ] ) ) {
				continue;
			}
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}

			$term_id = self::match_term( $by_key[ $key ], $value );
			if ( $term_id ) {
				$ids[ $key ] = $term_id;
			} else {
				$unresolved[] = array( 'key' => $key, 'value' => sanitize_text_field( $value ) );
			}
		}

		return array( 'ids' => $ids, 'unresolved' => $unresolved );
	}

	/* --------------------------------------------------------------------- */
	/* Category (product_cat) grounding                                       */
	/* --------------------------------------------------------------------- */

	/** The product category taxonomy. */
	const CATEGORY_TAX = 'product_cat';

	/**
	 * The store's product-category names, for grounding the model's category suggestion and the UI.
	 * Uncategorized is excluded so the AI never "suggests" the fallback bucket.
	 *
	 * @return array<int,string>
	 */
	public static function category_choices() {
		if ( ! taxonomy_exists( self::CATEGORY_TAX ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => self::CATEGORY_TAX,
				'hide_empty' => false,
				'exclude'    => array( (int) get_option( 'default_product_cat', 0 ) ),
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		$names = array();
		foreach ( $terms as $term ) {
			if ( 'uncategorized' !== $term->slug ) {
				$names[] = $term->name;
			}
		}
		return $names;
	}

	/**
	 * Resolve a model category label to a real product_cat term id (0 when unmatched/empty).
	 *
	 * @param string $label Category name from the model.
	 * @return int
	 */
	public static function resolve_category( $label ) {
		$label = trim( (string) $label );
		if ( '' === $label ) {
			return 0;
		}
		return self::match_term( self::CATEGORY_TAX, $label );
	}

	/**
	 * Best-effort term match within a taxonomy: exact slug, exact name (ci), then contains (ci).
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $value    Label from the model.
	 * @return int Term id or 0.
	 */
	private static function match_term( $taxonomy, $value ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		// 1) Exact slug.
		$by_slug = get_term_by( 'slug', sanitize_title( $value ), $taxonomy );
		if ( $by_slug instanceof WP_Term ) {
			return (int) $by_slug->term_id;
		}

		// 2) Exact name (case-insensitive).
		$by_name = get_term_by( 'name', $value, $taxonomy );
		if ( $by_name instanceof WP_Term ) {
			return (int) $by_name->term_id;
		}

		// 3) Loose contains, comparing normalized names.
		$needle = self::normalize( $value );
		$terms  = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) ) {
			return 0;
		}
		foreach ( $terms as $term ) {
			$hay = self::normalize( $term->name );
			if ( '' !== $needle && ( false !== strpos( $hay, $needle ) || false !== strpos( $needle, $hay ) ) ) {
				return (int) $term->term_id;
			}
		}
		return 0;
	}

	/**
	 * Lowercase + strip non-alphanumerics for loose comparison.
	 *
	 * @param string $s Input.
	 * @return string
	 */
	private static function normalize( $s ) {
		return preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $s ) );
	}
}
