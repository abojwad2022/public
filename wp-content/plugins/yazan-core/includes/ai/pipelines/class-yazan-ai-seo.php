<?php
/**
 * Yazan AI — SEO pipeline.
 *
 * Turns an existing product into elegant, search-ready SEO assets (title tag, meta description, focus
 * keywords, image alt text, search-friendly copy, tags, slug, and GROUNDED internal-link suggestions
 * chosen from real related products) without keyword-stuffing. Reads the product through WooCommerce
 * CRUD (HPOS-safe) and returns a suggestion for review — it does not write. The store's SEO plugin
 * (SureRank) or the products controller consumes the result.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product → SEO metadata.
 */
class Yazan_AI_SEO {

	/**
	 * Generate SEO metadata for a product.
	 *
	 * @param array $input product_id (required), language.
	 * @return array{ok:bool,suggestion:array,meta:array,error:?array}
	 */
	public static function generate( array $input ) {
		$product = wc_get_product( absint( $input['product_id'] ?? 0 ) );
		if ( ! $product instanceof WC_Product ) {
			return self::fail( 'not_found', __( 'Product not found.', 'yazan' ) );
		}

		$lang = Yazan_AI_Product::language( $input['language'] ?? null );
		$dual = ( 'both' === $lang );
		$str  = $dual ? '{ "en": "…", "ar": "…" }' : '"…"';

		$context = Yazan_AI_Product_Context::summarize( $product );
		$related = self::related_products( $product );

		$related_block = '';
		if ( $related ) {
			$lines = array( 'RELATED PRODUCTS (choose internal links ONLY from these ids — never invent a product):' );
			foreach ( $related as $r ) {
				$lines[] = sprintf( '- id=%d | %s', $r['id'], $r['name'] );
			}
			$related_block = "\n\n" . implode( "\n", $lines );
		}

		$user  = "Write luxury-grade SEO assets for this YAZAN ring. Keep the title tag under ~60 characters and "
			. "the meta description under ~155 characters; be enticing and human, never stuff keywords.\n\n"
			. "PRODUCT:\n" . $context . $related_block . "\n\n"
			. "Return ONLY JSON:\n"
			. '{ "title": ' . $str . ', '
			. '"meta_description": ' . $str . ', '
			. '"focus_keywords": ["…"], '            // 3–6 keywords, buyer search intent
			. '"alt_text": ' . $str . ', '            // concise, descriptive image alt text
			. '"search_copy": ' . $str . ', '         // one short search-friendly paragraph
			. '"tags": ["…"], '
			. '"slug": "lowercase-hyphenated-english-slug", '
			. '"internal_links": [ { "product_id": 0, "anchor": ' . $str . ' } ] }';

		$envelope = Yazan_AI_Gateway::run(
			array(
				'system'   => Yazan_AI_Prompts::system( 'seo.generate', $lang ),
				'messages' => array( array( 'role' => 'user', 'content' => $user ) ),
				'json'     => true,
				// Headroom for "thinking" models so the JSON isn't truncated (see product pipeline note).
				'max_tokens' => 2560,
			),
			array(
				'task'        => 'seo.generate',
				'module'      => 'seo',
				'object_type' => 'product',
				'object_id'   => $product->get_id(),
				'capability'  => 'text',
			)
		);

		$result = self::finish( $envelope );
		// Ground the internal links: keep only ids the model was actually given, resolve to real URLs.
		if ( ! empty( $result['ok'] ) ) {
			$result['suggestion']['internal_links'] = self::resolve_internal_links( $result['suggestion']['internal_links'] ?? array(), $related );
		}
		return $result;
	}

	/**
	 * Candidate related products for internal-link suggestions: same stone or same category, published,
	 * in-stock, not hidden, excluding the product itself.
	 *
	 * @param WC_Product $product Product.
	 * @param int        $limit   Max candidates.
	 * @return array<int,array{id:int,name:string}>
	 */
	private static function related_products( WC_Product $product, $limit = 6 ) {
		$pid = $product->get_id();

		$or = array( 'relation' => 'OR' );
		$stone = wc_get_product_terms( $pid, 'pa_stone', array( 'fields' => 'ids' ) );
		if ( ! is_wp_error( $stone ) && ! empty( $stone ) ) {
			$or[] = array( 'taxonomy' => 'pa_stone', 'field' => 'term_id', 'terms' => $stone );
		}
		$cats = $product->get_category_ids();
		if ( ! empty( $cats ) ) {
			$or[] = array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cats );
		}
		if ( count( $or ) < 2 ) {
			return array(); // Nothing to relate on.
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => (int) $limit,
				'post__not_in'   => array( $pid ),
				'fields'         => 'ids',
				'orderby'        => 'menu_order date',
				'order'          => 'ASC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				'tax_query'      => array(
					'relation' => 'AND',
					$or,
					array( 'taxonomy' => 'product_visibility', 'field' => 'name', 'terms' => array( 'exclude-from-catalog', 'outofstock' ), 'operator' => 'NOT IN' ),
				),
			)
		);

		$out = array();
		foreach ( $query->posts as $rid ) {
			$p = wc_get_product( $rid );
			if ( $p instanceof WC_Product && $p->is_in_stock() ) {
				$out[] = array( 'id' => (int) $rid, 'name' => wp_strip_all_tags( $p->get_name() ) );
			}
		}
		return $out;
	}

	/**
	 * Validate the model's internal-link suggestions against the real candidate set and resolve URLs.
	 * Drops any id the model was not given (so it can never invent a link).
	 *
	 * @param mixed $links     Model output for internal_links.
	 * @param array $candidates Candidate related products.
	 * @return array<int,array{product_id:int,name:string,url:string,anchor:mixed}>
	 */
	private static function resolve_internal_links( $links, array $candidates ) {
		$by_id = array();
		foreach ( $candidates as $c ) {
			$by_id[ (int) $c['id'] ] = $c['name'];
		}

		$out = array();
		foreach ( (array) $links as $link ) {
			if ( ! is_array( $link ) ) {
				continue;
			}
			$id = absint( $link['product_id'] ?? 0 );
			if ( ! isset( $by_id[ $id ] ) ) {
				continue; // Not a product we offered — never trust an invented id.
			}
			$out[] = array(
				'product_id' => $id,
				'name'       => $by_id[ $id ],
				'url'        => get_permalink( $id ),
				'anchor'     => $link['anchor'] ?? '',
			);
		}
		return $out;
	}

	/**
	 * Shape the final result from a gateway envelope.
	 *
	 * @param array $envelope Envelope.
	 * @return array
	 */
	private static function finish( array $envelope ) {
		if ( empty( $envelope['ok'] ) ) {
			$err = $envelope['error'] ?? array();
			return self::fail( $err['code'] ?? 'error', $err['message'] ?? __( 'AI request failed.', 'yazan' ), $envelope );
		}
		$json = is_array( $envelope['json'] ) ? $envelope['json'] : array();
		if ( empty( $json ) ) {
			return self::fail( 'unparsable', __( 'The AI response could not be read.', 'yazan' ), $envelope );
		}
		return array( 'ok' => true, 'suggestion' => $json, 'meta' => Yazan_AI_Product::meta( $envelope ), 'error' => null );
	}

	/**
	 * Failure result.
	 *
	 * @param string $code     Code.
	 * @param string $message  Message.
	 * @param array  $envelope Optional envelope.
	 * @return array
	 */
	private static function fail( $code, $message, array $envelope = array() ) {
		return array(
			'ok'         => false,
			'suggestion' => array(),
			'meta'       => $envelope ? Yazan_AI_Product::meta( $envelope ) : array(),
			'error'      => array( 'code' => sanitize_key( $code ), 'message' => (string) $message ),
		);
	}
}
