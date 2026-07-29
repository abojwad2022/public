<?php
/**
 * Yazan Dashboard — jewelry & authentication field registry.
 *
 * The single source of truth mapping the dashboard's Jewelry / Authenticity fields onto REAL
 * WooCommerce storage, so the storefront and {@see Yazan_Core_Verify} keep reading the same data:
 *
 *  - "Agate Type / Origin / Color", "Metal / Silver Purity", "Ring Size", "Rarity", "Stone Weight /
 *    Shape" are WooCommerce GLOBAL ATTRIBUTES (`pa_*` taxonomies). Editing them here assigns the same
 *    object terms the theme eyebrow, badges, and certificate already consume.
 *  - "Collection" is the `collection` taxonomy registered by {@see Yazan_Core_Taxonomies}.
 *  - "Serial" reuses the existing `_yz_serial` meta ({@see Yazan_Core_Verify::SERIAL_META}).
 *  - "Silver Weight", "Craftsmanship", "QR Code", "Digital Certificate", "Verification Status" are the
 *    genuinely-new fields, stored as post meta registered below.
 *
 * This class only KNOWS the mapping and reads/writes it. The REST controller orchestrates save order.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Field registry + read/write helpers for the Yazan-specific product data.
 */
class Yazan_Dashboard_Fields {

	/** Meta: silver weight in grams (free text, e.g. "8.4 g"). */
	const META_SILVER_WEIGHT = '_yz_silver_weight';

	/** Meta: craftsmanship / making notes (multi-line). */
	const META_CRAFTSMANSHIP = '_yz_craftsmanship';

	/** Meta: attachment id of the generated verify QR image. */
	const META_QR = '_yz_qr_code';

	/** Meta: attachment id of the digital certificate (image/PDF). */
	const META_CERTIFICATE = '_yz_certificate_id';

	/** Meta: verification status enum. */
	const META_STATUS = '_yz_verification_status';

	/** Allowed verification statuses. */
	const STATUSES = array( 'draft', 'certified', 'retired' );

	/** Meta: gallery mode (manual|ai|off) — see {@see Yazan_AI_Gallery}. */
	const META_GALLERY_MODE = '_yz_gallery_mode';

	/** Meta: number of AI gallery images to generate. */
	const META_GALLERY_COUNT = '_yz_gallery_image_count';

	/** Meta: optional AI gallery prompt template. */
	const META_GALLERY_PROMPT = '_yz_gallery_ai_prompt';

	/** Allowed gallery modes. */
	const GALLERY_MODES = array( 'manual', 'ai', 'off' );

	/**
	 * Jewelry taxonomy fields. Each maps a dashboard field key to a `pa_*` attribute taxonomy.
	 * `group` drives UI grouping only. Order here is the order shown in the panel.
	 *
	 * @return array<int,array{key:string,label:string,taxonomy:string,group:string}>
	 */
	public static function attribute_fields() {
		return array(
			array( 'key' => 'agate_type',    'label' => 'Agate Type',    'taxonomy' => 'pa_stone',         'group' => 'agate' ),
			array( 'key' => 'agate_origin',  'label' => 'Agate Origin',  'taxonomy' => 'pa_origin',        'group' => 'agate' ),
			array( 'key' => 'agate_color',   'label' => 'Agate Color',   'taxonomy' => 'pa_color',         'group' => 'agate' ),
			array( 'key' => 'stone_weight',  'label' => 'Stone Weight',  'taxonomy' => 'pa_carat',         'group' => 'agate' ),
			array( 'key' => 'stone_shape',   'label' => 'Stone Shape',   'taxonomy' => 'pa_shape',         'group' => 'agate' ),
			array( 'key' => 'silver_type',   'label' => 'Silver Type',   'taxonomy' => 'pa_metal',         'group' => 'silver' ),
			array( 'key' => 'silver_purity', 'label' => 'Silver Purity', 'taxonomy' => 'pa_silver-purity', 'group' => 'silver' ),
			array( 'key' => 'ring_size',     'label' => 'Ring Size',     'taxonomy' => 'pa_size',          'group' => 'ring' ),
			array( 'key' => 'rarity',        'label' => 'Rarity Level',  'taxonomy' => 'pa_rarity',        'group' => 'ring' ),
		);
	}

	/** The `collection` taxonomy (handled separately from `pa_*` attributes). */
	const COLLECTION_TAX = 'collection';

	/**
	 * Register the new post meta so it is documented, single-valued, and edit-guarded.
	 * Hook: init (after WooCommerce is available). Serial is registered here too for completeness.
	 *
	 * @return void
	 */
	public static function register_meta() {
		$auth = static function () {
			return current_user_can( 'edit_products' );
		};

		$defs = array(
			Yazan_Core_Verify::SERIAL_META => 'string',
			self::META_SILVER_WEIGHT       => 'string',
			self::META_CRAFTSMANSHIP       => 'string',
			self::META_QR                  => 'integer',
			self::META_CERTIFICATE         => 'integer',
			self::META_STATUS              => 'string',
			self::META_GALLERY_MODE        => 'string',
			self::META_GALLERY_COUNT       => 'integer',
			self::META_GALLERY_PROMPT      => 'string',
		);

		foreach ( $defs as $key => $type ) {
			register_post_meta(
				'product',
				$key,
				array(
					'type'          => $type,
					'single'        => true,
					'show_in_rest'  => false, // Exposed only through the guarded yazan/v1 controllers.
					'auth_callback' => $auth,
				)
			);
		}
	}

	/* --------------------------------------------------------------------- */
	/* Read                                                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * Assemble the Yazan field values for a product, shaped for the dashboard editor.
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	public static function read( WC_Product $product ) {
		$pid  = $product->get_id();
		$attr = array();

		foreach ( self::attribute_fields() as $field ) {
			$attr[ $field['key'] ] = self::first_term_id( $pid, $field['taxonomy'] );
		}

		return array(
			'attributes'          => $attr,
			'collection'          => self::first_term_id( $pid, self::COLLECTION_TAX ),
			'serial'              => (string) $product->get_meta( Yazan_Core_Verify::SERIAL_META ),
			'silver_weight'       => (string) $product->get_meta( self::META_SILVER_WEIGHT ),
			'craftsmanship'       => (string) $product->get_meta( self::META_CRAFTSMANSHIP ),
			'verification_status' => self::status_or_default( $product ),
			'qr_id'               => (int) $product->get_meta( self::META_QR ),
			'qr_url'              => self::attachment_url( (int) $product->get_meta( self::META_QR ) ),
			'certificate_id'      => (int) $product->get_meta( self::META_CERTIFICATE ),
			'certificate_url'     => self::attachment_url( (int) $product->get_meta( self::META_CERTIFICATE ) ),
			'gallery_mode'        => self::gallery_mode_or_default( $product ),
			'gallery_image_count' => (int) ( $product->get_meta( self::META_GALLERY_COUNT ) ?: 3 ),
			'gallery_ai_prompt'   => (string) $product->get_meta( self::META_GALLERY_PROMPT ),
		);
	}

	/**
	 * Normalise the stored status to an allowed value (defaults to 'certified' when a serial exists,
	 * else 'draft' — matches the storefront's "Certified when serial present" behaviour).
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private static function status_or_default( WC_Product $product ) {
		$stored = (string) $product->get_meta( self::META_STATUS );
		if ( in_array( $stored, self::STATUSES, true ) ) {
			return $stored;
		}
		return $product->get_meta( Yazan_Core_Verify::SERIAL_META ) ? 'certified' : 'draft';
	}

	/**
	 * Stored gallery mode normalised to an allowed value (defaults to 'manual').
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private static function gallery_mode_or_default( WC_Product $product ) {
		$stored = (string) $product->get_meta( self::META_GALLERY_MODE );
		// Default = 'off' (no gallery) until the owner explicitly chooses a mode.
		return in_array( $stored, self::GALLERY_MODES, true ) ? $stored : 'off';
	}

	/* --------------------------------------------------------------------- */
	/* Write                                                                  */
	/* --------------------------------------------------------------------- */

	/**
	 * Stage the Yazan meta onto the product object (call BEFORE $product->save()).
	 * Only keys present in $data are touched, so partial updates are safe.
	 *
	 * @param WC_Product $product Product being saved.
	 * @param array      $data    Sanitized jewelry payload from the controller.
	 * @return void
	 */
	public static function stage_meta( WC_Product $product, array $data ) {
		if ( array_key_exists( 'serial', $data ) ) {
			$serial = Yazan_Core_Verify::clean_serial( $data['serial'] );
			if ( '' === $serial ) {
				$product->delete_meta_data( Yazan_Core_Verify::SERIAL_META );
			} else {
				$product->update_meta_data( Yazan_Core_Verify::SERIAL_META, $serial );
			}
		}
		if ( array_key_exists( 'silver_weight', $data ) ) {
			$product->update_meta_data( self::META_SILVER_WEIGHT, sanitize_text_field( $data['silver_weight'] ) );
		}
		if ( array_key_exists( 'craftsmanship', $data ) ) {
			$product->update_meta_data( self::META_CRAFTSMANSHIP, sanitize_textarea_field( $data['craftsmanship'] ) );
		}
		if ( array_key_exists( 'verification_status', $data ) ) {
			$status = in_array( $data['verification_status'], self::STATUSES, true ) ? $data['verification_status'] : 'draft';
			$product->update_meta_data( self::META_STATUS, $status );
		}
		if ( array_key_exists( 'qr_id', $data ) ) {
			$product->update_meta_data( self::META_QR, absint( $data['qr_id'] ) );
		}
		if ( array_key_exists( 'certificate_id', $data ) ) {
			$product->update_meta_data( self::META_CERTIFICATE, absint( $data['certificate_id'] ) );
		}
		if ( array_key_exists( 'gallery_mode', $data ) ) {
			$m = sanitize_key( (string) $data['gallery_mode'] );
			$product->update_meta_data( self::META_GALLERY_MODE, in_array( $m, self::GALLERY_MODES, true ) ? $m : 'manual' );
		}
		if ( array_key_exists( 'gallery_image_count', $data ) ) {
			$product->update_meta_data( self::META_GALLERY_COUNT, max( 1, min( 8, absint( $data['gallery_image_count'] ) ) ) );
		}
		if ( array_key_exists( 'gallery_ai_prompt', $data ) ) {
			$product->update_meta_data( self::META_GALLERY_PROMPT, sanitize_textarea_field( (string) $data['gallery_ai_prompt'] ) );
		}
	}

	/**
	 * Merge the jewelry taxonomy selections into an existing WC_Product_Attribute set. Returns the
	 * merged array to pass to $product->set_attributes(). Non-jewelry attributes are preserved as-is;
	 * jewelry taxonomies are authoritative from $data['attributes'] when that key is present.
	 *
	 * @param array $existing        Current attributes keyed by taxonomy/name (from $product->get_attributes()).
	 * @param array $jewelry_termids Map of field key => term id (0 = clear) from the sanitized payload.
	 * @return array<string,WC_Product_Attribute>
	 */
	public static function merge_attributes( array $existing, array $jewelry_termids ) {
		$by_key = array();
		foreach ( self::attribute_fields() as $field ) {
			$by_key[ $field['key'] ] = $field['taxonomy'];
		}

		foreach ( $jewelry_termids as $key => $term_id ) {
			if ( ! isset( $by_key[ $key ] ) ) {
				continue;
			}
			$taxonomy = $by_key[ $key ];
			$term_id  = absint( $term_id );

			if ( ! $term_id ) {
				unset( $existing[ $taxonomy ] ); // Cleared selection removes the attribute.
				continue;
			}

			$attribute = new WC_Product_Attribute();
			$attribute->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
			$attribute->set_name( $taxonomy );
			$attribute->set_options( array( $term_id ) );
			$attribute->set_visible( true );
			$attribute->set_variation( false );
			$existing[ $taxonomy ] = $attribute;
		}

		return $existing;
	}

	/**
	 * Assign the `collection` term after save (it is a taxonomy, not a product attribute).
	 *
	 * @param int      $product_id Product id (must exist).
	 * @param int|null $term_id    Collection term id, 0/null clears.
	 * @return void
	 */
	public static function assign_collection( $product_id, $term_id ) {
		$term_id = absint( $term_id );
		if ( ! taxonomy_exists( self::COLLECTION_TAX ) ) {
			return;
		}
		wp_set_object_terms( (int) $product_id, $term_id ? array( $term_id ) : array(), self::COLLECTION_TAX, false );
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * First assigned term id for a product in a taxonomy, or 0.
	 *
	 * @param int    $pid      Product id.
	 * @param string $taxonomy Taxonomy.
	 * @return int
	 */
	private static function first_term_id( $pid, $taxonomy ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}
		$ids = wc_get_product_terms( $pid, $taxonomy, array( 'fields' => 'ids' ) );
		return ( ! is_wp_error( $ids ) && ! empty( $ids ) ) ? (int) $ids[0] : 0;
	}

	/**
	 * Safe attachment URL or ''.
	 *
	 * @param int $id Attachment id.
	 * @return string
	 */
	private static function attachment_url( $id ) {
		$id = absint( $id );
		if ( ! $id ) {
			return '';
		}
		$url = wp_get_attachment_url( $id );
		return $url ? $url : '';
	}
}
