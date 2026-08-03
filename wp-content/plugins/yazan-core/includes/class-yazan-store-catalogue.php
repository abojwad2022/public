<?php
/**
 * Tenancy for the catalogue — the entities WordPress and WooCommerce own.
 *
 * THE PROBLEM THIS SOLVES, AND THE ONE IT CREATES
 * ----------------------------------------------
 * `wp_posts` cannot take a `store_id` column: altering it breaks core upgrades, `WP_Query`, and
 * every plugin that reads it. So a product's tenancy has to come from outside the row.
 *
 * This uses a TAXONOMY, and the reasoning is worth restating because a term relationship looks like
 * a heavier answer than a meta key and is not:
 *
 *   1. `wp_term_relationships` is a real, indexed join table. `wp_postmeta` is a key/value bag.
 *   2. `WP_Query` supports `tax_query` natively — so Elementor, SureRank, the variation swatches and
 *      every other consumer of a product query inherit the scoping FOR FREE. A meta key would have
 *      to be threaded through each of them by hand.
 *   3. A product can belong to MORE THAN ONE STORE. Post meta forces one store per product, and the
 *      first time two brands share an SKU that becomes a migration.
 *
 * ⚠️ AND THE HONEST COST, recorded in docs/multi-store/02-TARGET-ARCHITECTURE.md §8.1 and repeated
 * here so nobody has to go looking for it:
 *
 *      "WooCommerce entities are scoped by CONVENTION, not by CONSTRAINT. One forgotten filter is a
 *       leak with no error. This is the biggest tax of the decision."
 *
 * Every query path below is a filter someone had to remember. The isolation suite exists because
 * remembering is not a security control.
 *
 * WHY NOT `wp_yazan_store_object_map`
 * -----------------------------------
 * That table was created for this job in an earlier phase and never used — zero readers, zero
 * writers, and the class its docblock names was never written. It models the same membership idea,
 * but reaching it requires a hand-written JOIN in every query path, and nothing outside our own code
 * would ever consult it. The taxonomy gives the same semantics and takes Elementor and friends with
 * it. The table is superseded; see docs/multi-store/14-API.md.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Partitions the WooCommerce catalogue by store.
 */
class Yazan_Store_Catalogue {

	/**
	 * The tenancy taxonomy.
	 *
	 * Deliberately NOT `public`, NOT in the UI, NOT in REST, NOT rewritten. It is infrastructure —
	 * a shopper must never see a `/yazan-store/jewelry/` archive, and an editor must never be able
	 * to move a product between tenants by ticking a box in the sidebar.
	 */
	const TAXONOMY = 'yazan_store';

	/** Order meta carrying the store. */
	const ORDER_META = '_yazan_store_id';

	/** Option marking the one-time catalogue backfill as done. */
	const BACKFILL_OPTION = 'yazan_catalogue_backfilled';

	/**
	 * Term id per store, resolved once per request.
	 *
	 * @var array<int,int>
	 */
	private static $terms = array();

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function register() {
		self::register_taxonomy();

		/*
		 * The five product query paths from the target architecture. They are separate hooks rather
		 * than one because WooCommerce reaches products four different ways and only one of them is
		 * an ordinary WP_Query.
		 */
		add_action( 'pre_get_posts', array( __CLASS__, 'scope_query' ) );
		add_filter( 'woocommerce_product_data_store_cpt_get_products_query', array( __CLASS__, 'scope_wc_query' ), 10, 2 );
		add_filter( 'woocommerce_shortcode_products_query', array( __CLASS__, 'scope_args' ) );
		add_filter( 'woocommerce_product_related_posts_query', array( __CLASS__, 'scope_related' ), 10, 3 );
		add_filter( 'woocommerce_rest_product_object_query', array( __CLASS__, 'scope_args' ) );

		// A product created in a store belongs to it. Without this, new products join no store and
		// vanish from every one of them.
		add_action( 'save_post_product', array( __CLASS__, 'stamp_product' ), 10, 3 );

		// Orders carry the store in meta — see the note on `store_of_order()`.
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'stamp_order' ) );
		add_action( 'woocommerce_new_order', array( __CLASS__, 'stamp_new_order' ) );
		add_filter( 'woocommerce_order_query_args', array( __CLASS__, 'scope_order_args' ) );
	}

	/**
	 * Register the tenancy taxonomy.
	 *
	 * @return void
	 */
	public static function register_taxonomy() {
		if ( taxonomy_exists( self::TAXONOMY ) ) {
			return;
		}

		register_taxonomy(
			self::TAXONOMY,
			array( 'product' ),
			array(
				'label'             => __( 'Store', 'yazan' ),
				'hierarchical'      => false,
				'public'            => false,
				'publicly_queryable' => false,
				'show_ui'           => false,
				'show_admin_column' => false,
				'show_in_rest'      => false,
				'show_in_nav_menus' => false,
				'query_var'         => false,
				'rewrite'           => false,
			)
		);
	}

	/**
	 * The term id representing a store, created on first use.
	 *
	 * @param int|null $store_id Store, or null for the current one.
	 * @return int Term id, or 0 when the taxonomy is unavailable.
	 */
	public static function term_for( $store_id = null ) {
		$store = null === $store_id ? Yazan_DB::store_id() : (int) $store_id;

		if ( isset( self::$terms[ $store ] ) ) {
			return self::$terms[ $store ];
		}

		if ( ! taxonomy_exists( self::TAXONOMY ) ) {
			return 0;
		}

		$slug = 'store-' . $store;
		$term = get_term_by( 'slug', $slug, self::TAXONOMY );

		if ( ! $term ) {
			$created = wp_insert_term( $slug, self::TAXONOMY, array( 'slug' => $slug ) );
			$term_id = is_wp_error( $created ) ? 0 : (int) $created['term_id'];
		} else {
			$term_id = (int) $term->term_id;
		}

		self::$terms[ $store ] = $term_id;

		return $term_id;
	}

	/**
	 * Whether this request should be scoped at all.
	 *
	 * Two exemptions, both deliberate:
	 *
	 *   - WP-CLI and cron, where there is no host to resolve from and the caller may legitimately be
	 *     operating across the whole platform (a backup, a migration, a report).
	 *   - A platform super admin in wp-admin, who administers every tenant and would otherwise find
	 *     half the catalogue missing with no explanation.
	 *
	 * @return bool
	 */
	private static function should_scope() {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
			return false;
		}

		if ( is_admin() && class_exists( 'Yazan_Permissions' ) && Yazan_Permissions::is_platform_super() ) {
			return false;
		}

		/**
		 * Filter whether the catalogue is scoped for this request.
		 *
		 * @param bool $scope Whether to apply the store predicate.
		 */
		return (bool) apply_filters( 'yazan_scope_catalogue', true );
	}

	/**
	 * The tax_query clause for the active store.
	 *
	 * @return array
	 */
	private static function clause() {
		return array(
			'taxonomy' => self::TAXONOMY,
			'field'    => 'term_id',
			'terms'    => array( self::term_for() ),
			'operator' => 'IN',
		);
	}

	/**
	 * Scope an ordinary WP_Query over products.
	 *
	 * @param WP_Query $query Query.
	 * @return void
	 */
	public static function scope_query( $query ) {
		if ( ! $query instanceof WP_Query || ! self::should_scope() ) {
			return;
		}

		$types = (array) $query->get( 'post_type' );

		if ( ! in_array( 'product', $types, true ) && ! in_array( 'product_variation', $types, true ) ) {
			return;
		}

		$tax_query   = (array) $query->get( 'tax_query' );
		$tax_query[] = self::clause();

		$query->set( 'tax_query', $tax_query );
	}

	/**
	 * Scope `wc_get_products()`.
	 *
	 * @param array $query     WP_Query args WooCommerce built.
	 * @param array $query_vars The caller's arguments.
	 * @return array
	 */
	public static function scope_wc_query( $query, $query_vars ) {
		unset( $query_vars );

		if ( ! self::should_scope() ) {
			return $query;
		}

		$query['tax_query']   = isset( $query['tax_query'] ) ? (array) $query['tax_query'] : array();
		$query['tax_query'][] = self::clause();

		return $query;
	}

	/**
	 * Scope any argument array that accepts `tax_query`.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public static function scope_args( $args ) {
		if ( ! self::should_scope() ) {
			return $args;
		}

		$args = (array) $args;

		$args['tax_query']   = isset( $args['tax_query'] ) ? (array) $args['tax_query'] : array();
		$args['tax_query'][] = self::clause();

		return $args;
	}

	/**
	 * Scope the related-products query.
	 *
	 * This one is raw SQL built by WooCommerce, so the predicate is appended as a subquery rather
	 * than a `tax_query` — there is no argument array to add to.
	 *
	 * @param string $sql        The assembled query.
	 * @param int    $product_id Product.
	 * @param array  $args       Args.
	 * @return string
	 */
	public static function scope_related( $sql, $product_id, $args ) {
		unset( $product_id, $args );

		if ( ! self::should_scope() ) {
			return $sql;
		}

		global $wpdb;

		$term_id = self::term_for();

		if ( ! $term_id ) {
			return $sql;
		}

		$predicate = $wpdb->prepare(
			"p.ID IN ( SELECT tr.object_id FROM {$wpdb->term_relationships} tr"
			. " INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id"
			. ' WHERE tt.term_id = %d )',
			$term_id
		);

		// WooCommerce always emits a WHERE in this query; anchoring on it keeps the edit surgical.
		return str_replace( 'WHERE ', 'WHERE ' . $predicate . ' AND ', $sql );
	}

	/**
	 * Give a newly saved product its store.
	 *
	 * @param int     $post_id Product id.
	 * @param WP_Post $post    Post.
	 * @param bool    $update  Whether this was an update.
	 * @return void
	 */
	public static function stamp_product( $post_id, $post, $update ) {
		unset( $post );

		if ( $update && self::store_of_product( $post_id ) ) {
			return;
		}

		$term_id = self::term_for();

		if ( $term_id ) {
			wp_set_object_terms( (int) $post_id, array( $term_id ), self::TAXONOMY, true );
		}
	}

	/**
	 * The stores a product belongs to.
	 *
	 * @param int $product_id Product.
	 * @return int[] Store ids.
	 */
	public static function store_of_product( $product_id ) {
		$terms = wp_get_object_terms( (int) $product_id, self::TAXONOMY, array( 'fields' => 'slugs' ) );

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$stores = array();

		foreach ( (array) $terms as $slug ) {
			if ( 0 === strpos( (string) $slug, 'store-' ) ) {
				$stores[] = (int) substr( (string) $slug, 6 );
			}
		}

		return $stores;
	}

	/* ---------------------------------------------------------------------- ORDERS */

	/**
	 * The store an order belongs to.
	 *
	 * ⚠️ EVERY ORDER READ GOES THROUGH THIS ONE FUNCTION, on purpose. `wp_wc_orders` belongs to
	 * WooCommerce, which runs `dbDelta` and its own migrations against it, so the store lives in
	 * `wp_wc_orders_meta` for now. Putting the read behind a single function means the day a real
	 * column becomes available, this is a one-function swap rather than a search-and-replace.
	 *
	 * @param WC_Order|int $order Order or id.
	 * @return int
	 */
	public static function store_of_order( $order ) {
		$order = is_numeric( $order ) ? wc_get_order( (int) $order ) : $order;

		if ( ! $order instanceof WC_Order ) {
			return 0;
		}

		return (int) ( $order->get_meta( self::ORDER_META ) ?: 1 );
	}

	/**
	 * Stamp an order being created at checkout.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public static function stamp_order( $order ) {
		if ( $order instanceof WC_Order && ! $order->get_meta( self::ORDER_META ) ) {
			$order->update_meta_data( self::ORDER_META, Yazan_DB::store_id() );
		}
	}

	/**
	 * Stamp an order created anywhere else — admin, REST, an importer.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public static function stamp_new_order( $order_id ) {
		$order = wc_get_order( (int) $order_id );

		if ( ! $order instanceof WC_Order || $order->get_meta( self::ORDER_META ) ) {
			return;
		}

		$order->update_meta_data( self::ORDER_META, Yazan_DB::store_id() );
		$order->save();
	}

	/**
	 * Scope `wc_get_orders()`.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public static function scope_order_args( $args ) {
		if ( ! self::should_scope() ) {
			return $args;
		}

		$args = (array) $args;

		/*
		 * Orders written before this phase have no meta at all, so store 1 must match BOTH the
		 * stamped value and its absence — otherwise the migration would hide every historical order
		 * from the store that owns them.
		 */
		if ( 1 === Yazan_DB::store_id() ) {
			$args['meta_query'] = isset( $args['meta_query'] ) ? (array) $args['meta_query'] : array();

			$args['meta_query'][] = array(
				'relation' => 'OR',
				array(
					'key'   => self::ORDER_META,
					'value' => 1,
				),
				array(
					'key'     => self::ORDER_META,
					'compare' => 'NOT EXISTS',
				),
			);

			return $args;
		}

		$args['meta_query']   = isset( $args['meta_query'] ) ? (array) $args['meta_query'] : array();
		$args['meta_query'][] = array(
			'key'   => self::ORDER_META,
			'value' => Yazan_DB::store_id(),
		);

		return $args;
	}

	/* ---------------------------------------------------------------------- BACKFILL */

	/**
	 * Give every existing product the store-1 term, once.
	 *
	 * A product with NO store term is invisible in every store — which is the safe direction for a
	 * missed row (a visible outage rather than a silent leak), but it is still an outage, so the
	 * backfill is idempotent and re-runnable.
	 *
	 * @return int Products stamped.
	 */
	public static function backfill() {
		if ( ! taxonomy_exists( self::TAXONOMY ) ) {
			return 0;
		}

		$term_id = self::term_for( 1 );

		if ( ! $term_id ) {
			return 0;
		}

		$stamped = 0;

		// Untangling this from the scoping filters matters: the query that finds unstamped products
		// must not itself be scoped, or it would find nothing.
		add_filter( 'yazan_scope_catalogue', '__return_false', 99 );

		$ids = get_posts(
			array(
				'post_type'      => array( 'product' ),
				'post_status'    => 'any',
				'posts_per_page' => 2000,
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy' => self::TAXONOMY,
						'operator' => 'NOT EXISTS',
					),
				),
			)
		);

		foreach ( (array) $ids as $id ) {
			wp_set_object_terms( (int) $id, array( $term_id ), self::TAXONOMY, true );
			++$stamped;
		}

		remove_filter( 'yazan_scope_catalogue', '__return_false', 99 );

		update_option( self::BACKFILL_OPTION, gmdate( 'c' ), false );

		return $stamped;
	}

	/**
	 * Run the backfill once per installation.
	 *
	 * @return void
	 */
	public static function maybe_backfill() {
		if ( get_option( self::BACKFILL_OPTION ) ) {
			return;
		}

		self::backfill();
	}
}
