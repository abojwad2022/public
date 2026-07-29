<?php
/**
 * Yazan AI — product context builder.
 *
 * Renders a WooCommerce product into a compact, plain-text brief the model can reason over — name,
 * price, categories, jewelry attributes (resolved to their term names), and any existing copy. Shared
 * by the SEO and marketing pipelines so they describe a product identically. Read-only, HPOS-safe.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_Product → text brief.
 */
class Yazan_AI_Product_Context {

	/**
	 * Summarize a product for a prompt.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function summarize( WC_Product $product ) {
		$lines = array();

		$lines[] = 'Name: ' . wp_strip_all_tags( $product->get_name() );

		$price = $product->get_price();
		if ( '' !== $price ) {
			$lines[] = 'Price: ' . html_entity_decode( wp_strip_all_tags( wc_price( $price ) ), ENT_QUOTES );
		}

		$cats = wp_strip_all_tags( wc_get_product_category_list( $product->get_id(), ', ' ) );
		if ( '' !== $cats ) {
			$lines[] = 'Categories: ' . $cats;
		}

		// Jewelry attributes resolved to their term names via the field registry.
		foreach ( Yazan_Dashboard_Fields::attribute_fields() as $field ) {
			$names = wc_get_product_terms( $product->get_id(), $field['taxonomy'], array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $names ) && ! empty( $names ) ) {
				$lines[] = $field['label'] . ': ' . implode( ', ', array_map( 'wp_strip_all_tags', $names ) );
			}
		}

		$silver_weight = (string) $product->get_meta( Yazan_Dashboard_Fields::META_SILVER_WEIGHT );
		if ( '' !== $silver_weight ) {
			$lines[] = 'Silver weight: ' . sanitize_text_field( $silver_weight );
		}

		$short = wp_strip_all_tags( $product->get_short_description() );
		if ( '' !== $short ) {
			$lines[] = 'Current short description: ' . self::clip( $short, 400 );
		}
		$desc = wp_strip_all_tags( $product->get_description() );
		if ( '' !== $desc ) {
			$lines[] = 'Current description: ' . self::clip( $desc, 800 );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Trim a string to a length on a word boundary.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max chars.
	 * @return string
	 */
	private static function clip( $text, $max ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
		if ( strlen( $text ) <= $max ) {
			return $text;
		}
		return rtrim( substr( $text, 0, $max ) ) . '…';
	}
}
