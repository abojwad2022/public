<?php
/**
 * Yazan Core — ring authentication / certificate lookup.
 *
 * Portable, presentation-free logic for the public verification page (/verify-ring/): given a serial
 * number stored on a product as `_yz_serial`, resolve the certificate data for that ring. Scoped to
 * AUTHENTICITY only — stone, silver, origin, craft — never ownership (no register / transfer / owner
 * data is stored or returned). The child theme renders this data; if this plugin is inactive the
 * theme degrades gracefully.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serial → certificate resolver + /verify-ring/ page & route provisioning.
 */
class Yazan_Core_Verify {

	/** Product meta key holding the ring's serial number. */
	const SERIAL_META = '_yz_serial';

	/** Slug of the public verification page. */
	const PAGE_SLUG = 'verify-ring';

	/** Public query var carrying the serial on the pretty route /verify-ring/{serial}/. */
	const QUERY_VAR = 'yz_serial';

	/**
	 * Normalise a raw serial: uppercase, trim, keep only A–Z 0–9 and hyphen, cap length.
	 * Returns '' for anything that cannot be a serial (so callers can short-circuit).
	 *
	 * @param string $raw Raw user input.
	 * @return string
	 */
	public static function clean_serial( $raw ) {
		$s = strtoupper( trim( (string) wp_unslash( $raw ) ) );
		$s = preg_replace( '/[^A-Z0-9\-]/', '', $s );
		return $s ? substr( $s, 0, 40 ) : '';
	}

	/**
	 * Look up a ring by serial and return its public certificate data, or null if not found.
	 *
	 * Read-only, catalogue-visibility aware. No owner/order data is ever touched.
	 *
	 * @param string $serial Serial (raw or cleaned).
	 * @return array|null Certificate data, or null when no published product matches.
	 */
	public static function lookup( $serial ) {
		$serial = self::clean_serial( $serial );
		if ( '' === $serial ) {
			return null;
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- exact-match lookup on an indexed key; unavoidable for serial resolution.
				'meta_query'     => array(
					array(
						'key'     => self::SERIAL_META,
						'value'   => $serial,
						'compare' => '=',
					),
				),
			)
		);

		if ( empty( $query->posts ) ) {
			return null;
		}

		$product = wc_get_product( (int) $query->posts[0] );
		if ( ! $product instanceof WC_Product ) {
			return null;
		}
		$pid = $product->get_id();

		// Certified year: prefer the year encoded in the serial (YZ-925-2026-000NN), else the publish year.
		$year = '';
		if ( preg_match( '/(20\d{2})/', $serial, $m ) ) {
			$year = $m[1];
		} elseif ( $product->get_date_created() ) {
			$year = $product->get_date_created()->date( 'Y' );
		}

		$img_id = $product->get_image_id();

		return array(
			'serial'      => $serial,
			'product_id'  => $pid,
			'name'        => $product->get_name(),
			'permalink'   => get_permalink( $pid ),
			'image'       => $img_id ? wp_get_attachment_image_url( $img_id, 'woocommerce_single' ) : '',
			'stone'       => self::term_name( $pid, 'pa_stone' ),
			'color'       => self::term_name( $pid, 'pa_color' ),
			'metal'       => self::term_name( $pid, 'pa_metal' ),
			'purity'      => self::term_name( $pid, 'pa_silver-purity' ),
			'origin'      => self::term_name( $pid, 'pa_origin', __( 'Yemen', 'yazan' ) ),
			'shape'       => self::term_name( $pid, 'pa_shape' ),
			'carat'       => self::term_name( $pid, 'pa_carat' ),
			'wearer'      => self::term_name( $pid, 'pa_wearer' ),
			'collection'  => self::collection_name( $pid ),
			'one_of_one'  => has_term( 'one-of-one', 'product_tag', $pid ),
			'year'        => $year,
		);
	}

	/**
	 * First term name in a taxonomy for a product, or a fallback.
	 *
	 * @param int    $pid      Product id.
	 * @param string $taxonomy Taxonomy.
	 * @param string $fallback Value when no term is set.
	 * @return string
	 */
	private static function term_name( $pid, $taxonomy, $fallback = '' ) {
		$names = wc_get_product_terms( $pid, $taxonomy, array( 'fields' => 'names' ) );
		return ( ! is_wp_error( $names ) && ! empty( $names ) ) ? $names[0] : $fallback;
	}

	/**
	 * The ring's collection name, or ''.
	 *
	 * Reads the dedicated `collection` taxonomy FIRST — that is what the dashboard writes and the
	 * authoritative source. Only if no collection term is assigned does it fall back to the legacy
	 * convention of a `product_cat` whose slug looks like a collection, so rings catalogued before
	 * the taxonomy existed still resolve.
	 *
	 * Reading product_cat first was wrong: a ring could carry a legacy "signature-collection"
	 * category while its real collection was Heritage, and the public certificate then displayed
	 * the wrong collection.
	 *
	 * @param int $pid Product id.
	 * @return string
	 */
	private static function collection_name( $pid ) {
		if ( taxonomy_exists( Yazan_Core_Taxonomies::COLLECTION ) ) {
			$terms = wc_get_product_terms( $pid, Yazan_Core_Taxonomies::COLLECTION, array() );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				return $terms[0]->name;
			}
		}

		$legacy_slugs = array( 'signature-collection', 'heritage-collection', 'limited-editions' );
		foreach ( wc_get_product_terms( $pid, 'product_cat', array() ) as $term ) {
			if ( in_array( $term->slug, $legacy_slugs, true ) ) {
				return $term->name;
			}
		}
		return '';
	}

	/* --------------------------------------------------------------------- */
	/* Page + pretty route provisioning                                       */
	/* --------------------------------------------------------------------- */

	/**
	 * Ensure the /verify-ring/ page exists (idempotent). Returns its id.
	 *
	 * @return int
	 */
	public static function ensure_page() {
		$existing = get_page_by_path( self::PAGE_SLUG );
		if ( $existing instanceof WP_Post ) {
			return (int) $existing->ID;
		}
		$id = wp_insert_post(
			array(
				'post_title'   => __( 'Verify a Ring', 'yazan' ),
				'post_name'    => self::PAGE_SLUG,
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => __( 'Enter a Yazan serial number to confirm a ring is genuine.', 'yazan' ),
				'comment_status' => 'closed',
			)
		);
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * Register the pretty route /verify-ring/{serial}/ → the page + serial query var.
	 * Hook: init.
	 *
	 * @return void
	 */
	public static function add_rewrite() {
		add_rewrite_rule(
			'^' . self::PAGE_SLUG . '/([A-Za-z0-9\-]+)/?$',
			'index.php?pagename=' . self::PAGE_SLUG . '&' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	/**
	 * Make the serial query var readable via get_query_var().
	 * Hook: query_vars.
	 *
	 * @param string[] $vars Registered query vars.
	 * @return string[]
	 */
	public static function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Resolve the serial for the current request from either the pretty route or a ?yz_serial= /
	 * ?serial= query. Returns '' when none present.
	 *
	 * @return string
	 */
	public static function current_serial() {
		$raw = get_query_var( self::QUERY_VAR );
		if ( '' === $raw && isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only lookup.
			$raw = $_GET[ self::QUERY_VAR ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned below.
		}
		if ( '' === $raw && isset( $_GET['serial'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw = $_GET['serial']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned below.
		}
		return self::clean_serial( $raw );
	}
}

/**
 * Global convenience wrapper so the theme can look up without referencing the class directly.
 *
 * @param string $serial Serial number.
 * @return array|null
 */
function yazan_verify_lookup( $serial ) {
	return class_exists( 'Yazan_Core_Verify' ) ? Yazan_Core_Verify::lookup( $serial ) : null;
}
