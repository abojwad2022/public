<?php
/**
 * Yazan AI — product gallery manager.
 *
 * Three modes per product: manual (use uploaded images only), ai (generate N luxury gallery images
 * image-to-image from the product's real photo), off (skip entirely). The AI path is deliberately
 * REVIEW-BEFORE-ATTACH — it generates into the media library and returns the images for the owner to
 * curate; it never auto-publishes, and it never invents a different ring (it works from the real
 * featured photo). Honest validation replaces guessing when data or an image-capable provider is
 * missing. Read/curate only — attaching to the gallery goes through the normal products controller.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gallery planning + AI generation.
 */
class Yazan_AI_Gallery {

	/** Valid gallery modes. */
	const MODES = array( 'manual', 'ai', 'off' );

	/** Max AI images per product. */
	const MAX_IMAGES = 8;

	/**
	 * Dry-run: validate the requested gallery configuration and describe what would happen. No images
	 * are generated. Returns the spec's normalized output.
	 *
	 * @param array $input product_id, mode, count, prompt.
	 * @return array
	 */
	public static function plan( array $input ) {
		$product = wc_get_product( absint( $input['product_id'] ?? 0 ) );
		if ( ! $product instanceof WC_Product ) {
			return self::fail( 'not_found', __( 'Product not found.', 'yazan' ) );
		}

		$mode   = self::mode( $input['mode'] ?? '', $product );
		$count  = self::count( $input['count'] ?? 0, $product );
		$prompt = sanitize_textarea_field( (string) ( $input['prompt'] ?? '' ) );

		$errors = array();
		$plan   = array();
		$status = 'ready';

		if ( 'off' === $mode ) {
			$status = 'skipped';
			$plan   = array( 'note' => __( 'Gallery generation is disabled for this product.', 'yazan' ) );
		} elseif ( 'manual' === $mode ) {
			$status  = 'manual';
			$gallery = $product->get_gallery_image_ids();
			$plan    = array( 'uploaded' => count( $gallery ) );
			if ( empty( $gallery ) ) {
				$errors[] = __( 'Manual mode: no gallery images have been uploaded yet.', 'yazan' );
			}
		} else { // ai
			$featured = $product->get_image_id();
			$prov     = Yazan_AI_Router::image_provider();
			if ( ! $featured ) {
				$errors[] = __( 'AI mode generates from the main product photo — set a featured image first.', 'yazan' );
			}
			if ( ! $prov ) {
				$errors[] = __( 'No image-capable provider is configured. AI gallery needs OpenAI (gpt-image-1) or Gemini (image) with a saved key.', 'yazan' );
			}
			if ( $featured && $prov ) {
				$status = 'ready';
				$plan   = array(
					'provider'        => $prov['provider'],
					'model'           => $prov['model'],
					'source_image_id' => (int) $featured,
					'to_generate'     => $count,
				);
			} else {
				$status = 'invalid';
			}
		}

		return array(
			'ok'                => empty( $errors ),
			'mode'              => $mode,
			'count'             => $count,
			'status'            => $status,
			'plan'              => $plan,
			'prompt'            => $prompt,
			'validation_errors' => $errors,
		);
	}

	/**
	 * Generate the AI gallery (image-to-image) and sideload the results for review. Does NOT attach
	 * them to the product gallery — the owner curates, then attaches via the products controller.
	 *
	 * @param array $input product_id, count, prompt.
	 * @return array
	 */
	public static function generate( array $input ) {
		// Product is OPTIONAL: the AI gallery can be generated straight from an uploaded main photo
		// (media_id) before a draft product exists. When a product is in focus it grounds the prompt,
		// parents the sideloaded images, and is where the ledger row is attributed.
		$product = wc_get_product( absint( $input['product_id'] ?? 0 ) );
		if ( ! $product instanceof WC_Product ) {
			$product = null;
		}

		// Source image (image-to-image): an explicit media_id wins; otherwise the product's featured photo.
		$media_id  = absint( $input['media_id'] ?? 0 );
		$source_id = $media_id ? $media_id : ( $product ? (int) $product->get_image_id() : 0 );
		if ( ! $source_id ) {
			return self::fail( 'no_source', __( 'Select a main product photo first — the AI gallery is generated from it.', 'yazan' ) );
		}

		// Ordered fallback chain of image-capable providers — walked until one succeeds, so a rate-limited
		// or over-budget provider transparently yields to the next (mirrors the text chain).
		$chain = Yazan_AI_Router::image_chain();
		if ( empty( $chain ) ) {
			return self::fail( 'no_image_provider', __( 'No image-capable provider is configured. Add OpenAI (gpt-image-1) or Gemini (image) in AI Settings.', 'yazan' ) );
		}

		$gate = Yazan_AI_Budget::check();
		if ( is_wp_error( $gate ) ) {
			return self::fail( $gate->get_error_code(), $gate->get_error_message() );
		}

		$src = self::attachment_data_uri( $source_id );
		if ( ! $src ) {
			return self::fail( 'no_source', __( 'The product photo could not be read.', 'yazan' ) );
		}

		// Provider-independent — computed once, reused for every attempt in the chain.
		$count  = self::count( $input['count'] ?? 0, $product );
		$prompt = self::build_prompt( $product, (string) ( $input['prompt'] ?? '' ) );

		$last_code = '';
		$tried     = array();
		foreach ( $chain as $prov ) {
			$tried[] = ucfirst( (string) $prov['provider'] );
			$adapter = Yazan_AI_Router::provider( $prov['provider'] );
			$api_key = Yazan_AI_Secrets::get( $prov['provider'] );
			$started = microtime( true );

			try {
				$images = $adapter->generate_image( array( 'prompt' => $prompt, 'image' => $src, 'count' => $count ), $prov['model'], $api_key );
			} catch ( Yazan_AI_Exception $e ) {
				// Log this provider's failure and fall through to the next image-capable provider.
				self::log( 'error', $prov, $product, 0, $e->error_code(), $started );
				$last_code = $e->error_code();
				continue;
			}

			$parent = $product ? $product->get_id() : 0;
			$alt    = ( $product ? $product->get_name() . ' — ' : '' ) . 'YAZAN AI gallery';
			$saved  = array();
			foreach ( $images as $img ) {
				$res = Yazan_AI_Media::sideload_base64( $img['b64'], $img['mime'], $parent, $alt );
				if ( ! is_wp_error( $res ) ) {
					$saved[] = $res;
				}
			}

			Yazan_AI_Budget::tick();
			self::log( 'ok', $prov, $product, count( $saved ), '', $started );

			if ( empty( $saved ) ) {
				return self::fail( 'sideload_failed', __( 'The generated images could not be saved to the media library.', 'yazan' ) );
			}

			return array(
				'ok'                => true,
				'mode'              => 'ai',
				'count'             => count( $saved ),
				'status'            => 'generated',
				'images'            => $saved, // [{id,url}] — for review, NOT yet attached to the gallery.
				'prompt'            => $prompt,
				'provider'          => $prov['provider'],
				'model'             => $prov['model'],
				'validation_errors' => array(),
				'note'              => __( 'These are model-rendered from your product photo. Review and attach only the ones you approve.', 'yazan' ),
			);
		}

		// Every image provider in the chain failed — report honestly which were tried.
		$list = implode( ', ', $tried );
		$msg  = 'rate_limited' === $last_code
			? sprintf(
				/* translators: %s: comma-separated provider names that were tried. */
				__( 'Image generation is rate-limited or out of quota on every configured provider (tried: %s). Try again later, or add another image-capable key (OpenAI gpt-image-1 or Gemini) in AI Settings.', 'yazan' ),
				$list
			)
			: sprintf(
				/* translators: %s: comma-separated provider names that were tried. */
				__( 'Image generation failed on every configured provider (tried: %s). Please try again.', 'yazan' ),
				$list
			);
		return self::fail( $last_code ? $last_code : 'image_failed', $msg );
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Resolve the mode: explicit input if valid, else the product's stored mode, else 'manual'.
	 *
	 * @param string     $input   Requested mode.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private static function mode( $input, WC_Product $product ) {
		$input = sanitize_key( (string) $input );
		if ( in_array( $input, self::MODES, true ) ) {
			return $input;
		}
		$stored = (string) $product->get_meta( Yazan_Dashboard_Fields::META_GALLERY_MODE );
		return in_array( $stored, self::MODES, true ) ? $stored : 'off';
	}

	/**
	 * Resolve the image count (1..MAX_IMAGES): explicit input, else stored, else 3.
	 *
	 * @param mixed      $input   Requested count.
	 * @param WC_Product $product Product.
	 * @return int
	 */
	private static function count( $input, $product = null ) {
		$n = absint( $input );
		if ( ! $n && $product instanceof WC_Product ) {
			$n = absint( $product->get_meta( Yazan_Dashboard_Fields::META_GALLERY_COUNT ) );
		}
		if ( ! $n ) {
			$n = 3;
		}
		return max( 1, min( self::MAX_IMAGES, $n ) );
	}

	/**
	 * Build the consistent luxury visual direction, grounded in the product's real attributes.
	 *
	 * @param WC_Product $product Product.
	 * @param string     $owner   Optional owner prompt template.
	 * @return string
	 */
	private static function build_prompt( $product, $owner ) {
		$facets = array();
		if ( $product instanceof WC_Product ) {
			foreach ( array( 'pa_stone', 'pa_metal', 'pa_color' ) as $tax ) {
				$names = wc_get_product_terms( $product->get_id(), $tax, array( 'fields' => 'names' ) );
				if ( ! is_wp_error( $names ) && ! empty( $names ) ) {
					$facets[] = $names[0];
				}
			}
		}
		$subject = $facets ? implode( ', ', array_map( 'wp_strip_all_tags', $facets ) ) : 'Yemeni agate and sterling-silver ring';

		$base = 'Luxury e-commerce product photography of THIS exact ring (' . $subject . '). '
			. 'Reproduce the same stone, setting and silverwork faithfully — do not alter or invent the design. '
			. 'Soft premium studio lighting, clean minimal neutral background, sharp focus on the stone and metal, '
			. 'elegant high-end jewelry presentation, consistent visual direction across the set.';

		$owner = trim( (string) $owner );
		if ( '' !== $owner ) {
			$base .= ' ' . sanitize_textarea_field( $owner );
		}
		return $base;
	}

	/**
	 * Read an attachment as a data URI (source for image-to-image).
	 *
	 * @param int $attachment_id Attachment id.
	 * @return string|null
	 */
	private static function attachment_data_uri( $attachment_id ) {
		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! file_exists( $path ) ) {
			return null;
		}
		$mime = get_post_mime_type( $attachment_id );
		if ( ! $mime || 0 !== strpos( $mime, 'image/' ) ) {
			return null;
		}
		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $bytes ) {
			return null;
		}
		return 'data:' . $mime . ';base64,' . base64_encode( $bytes );
	}

	/**
	 * Log a gallery generation to the ledger (module 'gallery').
	 */
	private static function log( $status, $prov, $product, $count, $error_code, $started ) {
		$duration = (int) round( ( microtime( true ) - $started ) * 1000 );
		Yazan_AI_Log::record(
			array(
				'module'      => 'gallery',
				'task'        => 'gallery.generate',
				'object_type' => 'product',
				'object_id'   => $product instanceof WC_Product ? $product->get_id() : 0,
				'provider'    => is_array( $prov ) ? ( $prov['provider'] ?? '' ) : '',
				'model'       => is_array( $prov ) ? ( $prov['model'] ?? '' ) : '',
				'status'      => $status,
				'error_code'  => $error_code,
				'tokens_in'   => 0,
				'tokens_out'  => $count, // reuse as image count for a rough activity signal
				'cost_usd'    => 0.0,     // image cost not modeled in the token price table
				'duration_ms' => $duration,
				'request_id'  => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : '',
			)
		);
	}

	/**
	 * Failure result.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @return array
	 */
	private static function fail( $code, $message ) {
		return array(
			'ok'                => false,
			'mode'              => '',
			'count'             => 0,
			'status'            => 'error',
			'plan'              => array(),
			'images'            => array(),
			'prompt'            => '',
			'validation_errors' => array( (string) $message ),
			'error'             => array( 'code' => sanitize_key( $code ), 'message' => (string) $message ),
		);
	}
}
