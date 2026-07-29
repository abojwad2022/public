<?php
/**
 * Yazan AI — product pipeline (image → full listing).
 *
 * The flagship admin feature: hand it a product photo (and any hints the owner types) and it returns a
 * complete, on-brand, bilingual draft — title, short + long description, brand story, tags, SEO block,
 * and classified jewelry attributes CHOSEN FROM the store's real `pa_*` terms (so they resolve to term
 * ids, not free text). Nothing is saved here; the pipeline returns a suggestion the dashboard shows for
 * review, then the existing products controller performs the write through {@see Yazan_Dashboard_Fields}.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Photo-to-listing generator.
 */
class Yazan_AI_Product {

	/**
	 * Generate a listing draft from an image + optional hints.
	 *
	 * @param array $input image (data-uri|url, required), hints (array), language, object_id (product id).
	 * @return array{ok:bool,suggestion:array,resolved:array,meta:array,error:?array}
	 */
	public static function generate( array $input ) {
		$image = (string) ( $input['image'] ?? '' );
		if ( '' === $image ) {
			return self::fail( 'missing_image', __( 'A product image is required.', 'yazan' ) );
		}

		$lang       = self::language( $input['language'] ?? null );
		$choices    = Yazan_AI_Fields_Writer::attribute_choices();
		$categories = Yazan_AI_Fields_Writer::category_choices();

		$user = self::build_prompt( $choices, $categories, (array) ( $input['hints'] ?? array() ), $lang, (string) ( $input['instructions'] ?? '' ) );

		$envelope = Yazan_AI_Gateway::run(
			array(
				'system'   => Yazan_AI_Prompts::system( 'product.generate', $lang ),
				'messages' => array(
					array( 'role' => 'user', 'content' => $user, 'images' => array( $image ) ),
				),
				'json'     => true,
				// The comprehensive listing (11 sections, bilingual) is a large JSON payload, and
				// "thinking" models (e.g. Gemini flash) spend hundreds of tokens reasoning before the
				// answer — so give generous headroom or the JSON truncates (MAX_TOKENS) and fails to parse.
				'max_tokens' => 5120,
			),
			array(
				'task'        => 'product.generate',
				'module'      => 'product',
				'object_type' => 'product',
				'object_id'   => absint( $input['object_id'] ?? 0 ),
				'capability'  => 'vision',
			)
		);

		if ( empty( $envelope['ok'] ) ) {
			return self::fail_from_envelope( $envelope );
		}
		$suggestion = is_array( $envelope['json'] ) ? $envelope['json'] : array();
		if ( empty( $suggestion ) ) {
			return self::fail( 'unparsable', __( 'The AI response could not be read. Please try again.', 'yazan' ), $envelope );
		}

		// Resolve the model's attribute + category labels to real term ids for the editor to pre-apply.
		$attr_labels          = isset( $suggestion['attributes'] ) && is_array( $suggestion['attributes'] ) ? $suggestion['attributes'] : array();
		$resolved             = Yazan_AI_Fields_Writer::resolve( $attr_labels );
		$resolved['category'] = Yazan_AI_Fields_Writer::resolve_category( (string) ( $suggestion['category'] ?? '' ) );

		return array(
			'ok'         => true,
			'suggestion' => $suggestion,
			'resolved'   => $resolved,
			'meta'       => self::meta( $envelope ),
			'error'      => null,
		);
	}

	/* --------------------------------------------------------------------- */
	/* Prompt                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * Build the user instruction, including the allowed attribute choices and the output schema.
	 *
	 * @param array  $choices      Attribute choices from the field resolver.
	 * @param array  $categories   Store product-category names for grounding.
	 * @param array  $hints        Owner-provided hints.
	 * @param string $lang         Output language.
	 * @param string $instructions Per-generation owner instructions (optional).
	 * @return string
	 */
	private static function build_prompt( array $choices, array $categories, array $hints, $lang, $instructions = '' ) {
		$lines   = array();
		$lines[] = 'Analyze the ring in the attached photograph and produce a complete YAZAN product listing.';
		$lines[] = 'CRITICAL RULE: keep FACTUAL fields strictly grounded in what the photo/data actually show — '
			. 'never claim a visual detail the image does not support, and never invent gemological facts. If any '
			. 'detail is uncertain or cannot be determined from the image, STATE THAT UNCERTAINTY PLAINLY (in '
			. 'observations) rather than guessing. The CREATIVE fields are your premium, on-brand copy. Keep the '
			. 'two clearly separated.';
		$lines[] = '';

		$instructions = trim( (string) $instructions );
		if ( '' !== $instructions ) {
			$lines[] = 'OWNER INSTRUCTIONS FOR THIS LISTING (highest priority — follow them, especially for the description tone/length):';
			$lines[] = sanitize_textarea_field( $instructions );
			$lines[] = '';
		}
		$lines[] = 'Classify each attribute below by choosing EXACTLY ONE value from its allowed list. '
			. 'If the photo does not show enough to decide, use an empty string for that attribute — never guess a value that is not listed.';
		$lines[] = '';
		$lines[] = 'ALLOWED ATTRIBUTES (key → allowed values):';
		foreach ( $choices as $c ) {
			$vals    = ! empty( $c['choices'] ) ? implode( ' | ', $c['choices'] ) : '(no terms defined)';
			$lines[] = sprintf( '- %s (%s): %s', $c['key'], $c['label'], $vals );
		}
		$lines[] = '';
		$lines[] = 'ALLOWED CATEGORIES (choose the single best fit for "category", or "" if unsure): '
			. ( $categories ? implode( ' | ', $categories ) : '(none defined)' );

		if ( ! empty( $hints ) ) {
			$lines[] = '';
			$lines[] = 'OWNER HINTS (use where relevant, do not contradict the photo):';
			foreach ( $hints as $k => $v ) {
				if ( is_scalar( $v ) && '' !== trim( (string) $v ) ) {
					$lines[] = sprintf( '- %s: %s', sanitize_key( $k ), sanitize_text_field( (string) $v ) );
				}
			}
		}

		$dual = ( 'both' === $lang );
		$str  = $dual ? '{ "en": "…", "ar": "…" }' : '"…"';

		$lines[] = '';
		$lines[] = 'Return ONLY a JSON object with EXACTLY these keys:';
		$lines[] = '{';
		$lines[] = '  /* ── FACTUAL — only from the image/data, never invented ── */';
		$lines[] = '  "observations": "what is visibly true in the photo; if a detail (e.g. stone origin, exact weight) is not determinable from the image, say so plainly here instead of guessing",';
		$lines[] = '  "attributes": { ' . self::keys_hint( $choices ) . ' },  // each value from the allowed lists, or ""';
		$lines[] = '  "confidence": { "<attribute_key>": 0.0-1.0 },';
		$lines[] = '  "category": "one of the ALLOWED CATEGORIES, or empty",';
		$lines[] = '  /* ── CREATIVE — premium, on-brand copy ── */';
		$lines[] = '  "name_suggestions": ["…", "…", "…"],   // 2–3 refined product-name options (English)';
		$lines[] = '  "short_description": ' . $str . ',';
		$lines[] = '  "description_html": ' . $str . ',       // 2–4 short paragraphs, simple <p> tags allowed';
		$lines[] = '  "story": ' . $str . ',                  // heritage micro-story, 2–3 sentences';
		$lines[] = '  "product_highlights": ["…", "…", "…"], // 3–5 concise selling points (English)';
		$lines[] = '  "customer_appeal_angle": ' . $str . ', // who it is for and why they will love it, 1–2 sentences';
		$lines[] = '  "seo": { "title": ' . $str . ', "meta_description": ' . $str . ' },';
		$lines[] = '  "keywords": ["…"],                       // 4–8 buyer search terms (English)';
		$lines[] = '  "tags": ["…"],                           // 3–7 concise product tags, distinct from keywords (English)';
		$lines[] = '  "marketing": { "instagram": ' . $str . ', "email_subject": ' . $str . ', "ad": ' . $str . ' }';
		$lines[] = '}';

		return implode( "\n", $lines );
	}

	/**
	 * Comma-separated attribute keys for the schema hint.
	 *
	 * @param array $choices Choices.
	 * @return string
	 */
	private static function keys_hint( array $choices ) {
		$keys = array();
		foreach ( $choices as $c ) {
			$keys[] = '"' . $c['key'] . '": "…"';
		}
		return implode( ', ', $keys );
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Resolve the requested output language, defaulting to the store setting.
	 *
	 * @param string|null $lang Requested language.
	 * @return string 'both'|'en'|'ar'.
	 */
	public static function language( $lang ) {
		$lang = sanitize_key( (string) $lang );
		if ( in_array( $lang, array( 'both', 'en', 'ar' ), true ) ) {
			return $lang;
		}
		return (string) Yazan_AI_Settings::get( 'language', 'both' );
	}

	/**
	 * Envelope meta for the UI.
	 *
	 * @param array $envelope Gateway envelope.
	 * @return array
	 */
	public static function meta( array $envelope ) {
		return array(
			'provider'   => $envelope['provider'] ?? '',
			'model'      => $envelope['model'] ?? '',
			'usage'      => $envelope['usage'] ?? array(),
			'cached'     => ! empty( $envelope['cached'] ),
			'request_id' => $envelope['request_id'] ?? '',
		);
	}

	/**
	 * Build a failure result.
	 *
	 * @param string $code     Error code.
	 * @param string $message  Message.
	 * @param array  $envelope Optional envelope for meta.
	 * @return array
	 */
	private static function fail( $code, $message, array $envelope = array() ) {
		return array(
			'ok'         => false,
			'suggestion' => array(),
			'resolved'   => array( 'ids' => array(), 'unresolved' => array() ),
			'meta'       => $envelope ? self::meta( $envelope ) : array(),
			'error'      => array( 'code' => sanitize_key( $code ), 'message' => (string) $message ),
		);
	}

	/**
	 * Translate a gateway error envelope into a pipeline failure.
	 *
	 * @param array $envelope Gateway envelope.
	 * @return array
	 */
	private static function fail_from_envelope( array $envelope ) {
		$err = $envelope['error'] ?? array( 'code' => 'error', 'message' => __( 'AI request failed.', 'yazan' ) );
		return self::fail( $err['code'] ?? 'error', $err['message'] ?? '', $envelope );
	}
}
