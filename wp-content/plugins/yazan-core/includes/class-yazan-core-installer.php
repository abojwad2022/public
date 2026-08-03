<?php
/**
 * Yazan Core — idempotent structure installer.
 *
 * Creates the product categories, global ring attributes (+ their terms), and collection terms.
 * Everything is additive and safe to re-run: existing terms/attributes are detected and never
 * deleted or overwritten — the installer only fills what is missing. This is why it can run against
 * a live store that already has `pa_stone` / `pa_metal` in use.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Structure installer.
 */
class Yazan_Core_Installer {

	/**
	 * Run the full install. Guarded by the caller for WooCommerce presence + version.
	 *
	 * @return void
	 */
	public static function install() {
		$created = array(
			'cats'        => self::install_categories(),
			'attributes'  => self::install_attributes(),
			'collections' => self::install_collections(),
		);

		// Build the ready-made primary menu (create-once) now that the terms exist.
		Yazan_Core_Menu::build();

		// Ensure the public ring-verification page exists (/verify-ring/).
		Yazan_Core_Verify::ensure_page();

		// Dashboard audit-log table (idempotent via dbDelta).
		if ( class_exists( 'Yazan_Dashboard_Audit' ) ) {
			Yazan_Dashboard_Audit::install_table();
		}

		if ( class_exists( 'Yazan_API_Tokens' ) ) {
			Yazan_API_Tokens::install_table();
		}

		/*
		 * RBAC: tables, permission catalog, default roles, and the one-time backfill that maps the
		 * WordPress roles already in use onto Yazan roles. Also self-installs on `init` (see
		 * Yazan_RBAC_Boot::maybe_install) because this store can be run entirely from /dashboard
		 * without ever loading wp-admin, and this method only fires on admin_init.
		 */
		if ( class_exists( 'Yazan_RBAC_Boot' ) ) {
			Yazan_RBAC_Boot::install();
		}

		// AI generation-log table (idempotent via dbDelta).
		if ( class_exists( 'Yazan_AI_Boot' ) ) {
			Yazan_AI_Boot::install();
		}

		// Attributes may have created new taxonomies — clear WooCommerce's cache so they show in admin.
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( 'wc_attribute_taxonomies' );
		}
		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
		}

		// Collection archive URLs need fresh rewrite rules.
		Yazan_Core_Taxonomies::register();
		flush_rewrite_rules( false );

		set_transient(
			'yazan_core_install_report',
			sprintf(
				/* translators: 1: category count, 2: attribute count, 3: collection count. */
				__( 'Structure ready — %1$d categories, %2$d attributes, %3$d collections ensured.', 'yazan' ),
				$created['cats'],
				$created['attributes'],
				$created['collections']
			),
			60
		);
	}

	/* ------------------------------------------------------------------ */
	/* Product categories                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Category tree: audience is the spine, hero-material the child. Everything finer is an attribute.
	 * `ar` is stored in the term description so the Arabic display name is never lost.
	 *
	 * @return int Number of terms ensured.
	 */
	private static function install_categories() {
		$tree = array(
			array(
				'name'     => "Men's Rings",
				'slug'     => 'mens-rings',
				'ar'       => 'خواتم رجالية',
				'children' => array(
					array( 'name' => "Men's Agate Rings", 'slug' => 'mens-agate-rings', 'ar' => 'خواتم عقيق رجالية' ),
					array( 'name' => "Men's Silver Rings", 'slug' => 'mens-silver-rings', 'ar' => 'خواتم فضة رجالية' ),
				),
			),
			array(
				'name'     => "Women's Rings",
				'slug'     => 'womens-rings',
				'ar'       => 'خواتم نسائية',
				'children' => array(
					array( 'name' => "Women's Agate Rings", 'slug' => 'womens-agate-rings', 'ar' => 'خواتم عقيق نسائية' ),
					array( 'name' => "Women's Silver Rings", 'slug' => 'womens-silver-rings', 'ar' => 'خواتم فضة نسائية' ),
				),
			),
			array( 'name' => 'Unisex Rings', 'slug' => 'unisex-rings', 'ar' => 'خواتم للجنسين', 'children' => array() ),
			array( 'name' => 'New Arrivals', 'slug' => 'new-arrivals', 'ar' => 'وصل حديثاً', 'children' => array() ),
		);

		$count = 0;
		foreach ( $tree as $parent ) {
			$parent_id = self::ensure_term( $parent['name'], $parent['slug'], 'product_cat', 0, $parent['ar'] );
			if ( $parent_id ) {
				++$count;
			}
			if ( empty( $parent['children'] ) ) {
				continue;
			}
			foreach ( $parent['children'] as $child ) {
				if ( self::ensure_term( $child['name'], $child['slug'], 'product_cat', (int) $parent_id, $child['ar'] ) ) {
					++$count;
				}
			}
		}
		return $count;
	}

	/* ------------------------------------------------------------------ */
	/* Collections taxonomy terms                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Seed the four house collections.
	 *
	 * @return int Number of terms ensured.
	 */
	private static function install_collections() {
		$collections = array(
			array( 'name' => 'Signature Collection', 'slug' => 'signature', 'ar' => 'مجموعة التوقيع' ),
			array( 'name' => 'Heritage Collection', 'slug' => 'heritage', 'ar' => 'مجموعة التراث' ),
			array( 'name' => 'Royal Collection', 'slug' => 'royal', 'ar' => 'المجموعة الملكية' ),
			array( 'name' => 'Limited Edition', 'slug' => 'limited-edition', 'ar' => 'إصدار محدود' ),
		);

		$count = 0;
		foreach ( $collections as $c ) {
			if ( self::ensure_term( $c['name'], $c['slug'], Yazan_Core_Taxonomies::COLLECTION, 0, $c['ar'] ) ) {
				++$count;
			}
		}
		return $count;
	}

	/* ------------------------------------------------------------------ */
	/* Global product attributes                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * The professional ring attribute set. Slugs map to WooCommerce taxonomies as `pa_{slug}`.
	 * `pa_stone` and `pa_metal` already power the child-theme eyebrow, so their slugs are fixed.
	 * All are created for filtering (never as variations) — Yazan's stones are one-of-one.
	 *
	 * @return int Number of attributes ensured.
	 */
	private static function install_attributes() {
		$attributes = array(
			'stone'         => array(
				'label' => 'Agate Type',
				'terms' => array(
					'Red Yemeni Aqeeq' => 'red-aqeeq',
					'White Aqeeq'      => 'white-aqeeq',
					'Black Aqeeq'      => 'black-aqeeq',
					'Yellow Aqeeq'     => 'yellow-aqeeq',
					'Blue Aqeeq'       => 'blue-aqeeq',
					'Honey Aqeeq'      => 'honey-aqeeq',
					'Sulaimani Aqeeq'  => 'sulaimani-aqeeq',
				),
			),
			'color'         => array(
				'label' => 'Stone Color',
				'terms' => array(
					'Red'   => 'red',
					'White' => 'white',
					'Black' => 'black',
					'Blue'  => 'blue',
					'Yellow' => 'yellow',
					'Brown' => 'brown',
					'Multi' => 'multi',
				),
			),
			'origin'        => array(
				'label' => 'Stone Origin',
				'terms' => array(
					'Yemen — Sana\'a'  => 'yemen-sanaa',
					'Yemen — Highlands' => 'yemen-highlands',
				),
			),
			'metal'         => array(
				'label' => 'Metal',
				'terms' => array(
					'Sterling Silver 925' => 'silver-925',
					'Silver 925 + Gold'   => 'silver-gold',
					'Oxidized Silver'     => 'oxidized-silver',
				),
			),
			'silver-purity' => array(
				'label' => 'Silver Purity',
				'terms' => array(
					'925' => '925',
					'950' => '950',
				),
			),
			'shape'         => array(
				'label' => 'Stone Shape',
				'terms' => array(
					'Oval'     => 'oval',
					'Round'    => 'round',
					'Cushion'  => 'cushion',
					'Emerald'  => 'emerald',
					'Cabochon' => 'cabochon',
					'Marquise' => 'marquise',
					'Freeform' => 'freeform',
				),
			),
			'carat'         => array(
				'label' => 'Stone Weight',
				'terms' => array(
					'Under 8 ct' => 'under-8ct',
					'8–12 ct'    => '8-12ct',
					'12–18 ct'   => '12-18ct',
					'Over 18 ct' => 'over-18ct',
				),
			),
			'size'          => array(
				'label' => 'Ring Size',
				'terms' => array(
					'US 6'  => 'us-6',
					'US 7'  => 'us-7',
					'US 8'  => 'us-8',
					'US 9'  => 'us-9',
					'US 10' => 'us-10',
					'US 11' => 'us-11',
					'US 12' => 'us-12',
					'US 13' => 'us-13',
				),
			),
			'style'         => array(
				'label' => 'Design Style',
				'terms' => array(
					'Signet'    => 'signet',
					'Solitaire' => 'solitaire',
					'Statement' => 'statement',
					'Minimal'   => 'minimal',
					'Vintage'   => 'vintage',
					'Band'      => 'band',
				),
			),
			'condition'     => array(
				'label' => 'Condition',
				'terms' => array(
					'New'           => 'new',
					'One of One'    => 'one-of-one',
					'Made to Order' => 'made-to-order',
				),
			),
			'rarity'        => array(
				'label' => 'Rarity',
				'terms' => array(
					'One of One' => 'one-of-one',
					'Limited'    => 'limited',
					'Signature'  => 'signature',
				),
			),
		);

		$count = 0;
		foreach ( $attributes as $slug => $data ) {
			$taxonomy = self::ensure_attribute( $slug, $data['label'] );
			if ( ! $taxonomy ) {
				continue;
			}
			++$count;
			foreach ( $data['terms'] as $name => $term_slug ) {
				self::ensure_term( $name, $term_slug, $taxonomy, 0, '' );
			}
		}
		return $count;
	}

	/**
	 * Ensure a global attribute exists and return its taxonomy name (e.g. `pa_stone`).
	 * Registers the taxonomy in-request so terms can be inserted immediately after creation.
	 *
	 * @param string $slug  Attribute slug without the `pa_` prefix.
	 * @param string $label Human-readable attribute name.
	 * @return string|false Taxonomy name on success, false on failure.
	 */
	private static function ensure_attribute( $slug, $label ) {
		if ( ! function_exists( 'wc_attribute_taxonomy_id_by_name' ) || ! function_exists( 'wc_create_attribute' ) ) {
			return false;
		}

		$exists = wc_attribute_taxonomy_id_by_name( $slug );
		if ( ! $exists ) {
			$result = wc_create_attribute(
				array(
					'name'         => $label,
					'slug'         => $slug,
					'type'         => 'select',
					'order_by'     => 'menu_order',
					'has_archives' => false,
				)
			);
			if ( is_wp_error( $result ) ) {
				return false;
			}
		}

		$taxonomy = wc_attribute_taxonomy_name( $slug );

		// Newly created attribute taxonomies are not registered until WC's next init; register now
		// so wp_insert_term() below has a valid taxonomy in this same request.
		if ( ! taxonomy_exists( $taxonomy ) ) {
			register_taxonomy(
				$taxonomy,
				array( 'product' ),
				array(
					'hierarchical' => false,
					'show_ui'      => false,
					'query_var'    => true,
					'rewrite'      => false,
				)
			);
		}

		return $taxonomy;
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Create a term if it does not already exist (matched by slug within its taxonomy).
	 *
	 * @param string $name        Term display name.
	 * @param string $slug        Term slug.
	 * @param string $taxonomy    Taxonomy name.
	 * @param int    $parent      Parent term id (0 for top level).
	 * @param string $description Optional description (used to carry the Arabic name).
	 * @return int|false Term id on success/existing, false on failure.
	 */
	private static function ensure_term( $name, $slug, $taxonomy, $parent = 0, $description = '' ) {
		$existing = get_term_by( 'slug', $slug, $taxonomy );
		if ( $existing instanceof WP_Term ) {
			return (int) $existing->term_id;
		}

		$args = array(
			'slug'   => $slug,
			'parent' => (int) $parent,
		);
		if ( '' !== $description ) {
			$args['description'] = $description;
		}

		$result = wp_insert_term( $name, $taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			// If it raced/exists, fall back to the existing id.
			$term = get_term_by( 'slug', $slug, $taxonomy );
			return $term instanceof WP_Term ? (int) $term->term_id : false;
		}
		return (int) $result['term_id'];
	}
}
