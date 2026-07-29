<?php
/**
 * Yazan AI — marketing pipeline.
 *
 * Produces premium, on-brand marketing content for a product across many channels (Instagram caption
 * + story, hashtags, Facebook ad, Google Ads headlines/descriptions, email, promo, launch, retargeting)
 * in the requested language(s), with platform-appropriate tone. The owner selects which channels to
 * generate. Read-only; returns a suggestion the owner copies into their channel of choice.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product → marketing copy.
 */
class Yazan_AI_Marketing {

	/** Channels the pipeline can produce (the owner selects a subset). */
	const CHANNELS = array( 'instagram', 'story', 'facebook_ad', 'google_ads', 'email', 'promo', 'launch', 'retargeting' );

	/** Sensible default selection when none is specified. */
	const DEFAULT_CHANNELS = array( 'instagram', 'facebook_ad', 'google_ads', 'email' );

	/**
	 * Generate marketing copy for a product.
	 *
	 * @param array $input product_id (required), language, channels (subset of CHANNELS).
	 * @return array{ok:bool,suggestion:array,meta:array,error:?array}
	 */
	public static function generate( array $input ) {
		$product = wc_get_product( absint( $input['product_id'] ?? 0 ) );
		if ( ! $product instanceof WC_Product ) {
			return self::fail( 'not_found', __( 'Product not found.', 'yazan' ) );
		}

		$channels = array_values( array_intersect( self::CHANNELS, (array) ( $input['channels'] ?? self::DEFAULT_CHANNELS ) ) );
		if ( empty( $channels ) ) {
			$channels = self::DEFAULT_CHANNELS;
		}

		$lang = Yazan_AI_Product::language( $input['language'] ?? null );
		$dual = ( 'both' === $lang );
		$str  = $dual ? '{ "en": "…", "ar": "…" }' : '"…"';

		$context = Yazan_AI_Product_Context::summarize( $product );

		// Per-channel schema fragments (only the selected channels are asked for). Google Ads headlines
		// and descriptions are plain string arrays in the chosen language (char limits enforced in-prompt).
		$fragments = array(
			'instagram'   => '"instagram": { "caption": ' . $str . ', "hashtags": ["#…"] }',
			'story'       => '"story": { "text": ' . $str . ' }',
			'facebook_ad' => '"facebook_ad": { "headline": ' . $str . ', "primary_text": ' . $str . ' }',
			'google_ads'  => '"google_ads": { "headlines": ["≤30 chars", "…"], "descriptions": ["≤90 chars", "…"] }',
			'email'       => '"email": { "subject": ' . $str . ', "body": ' . $str . ' }',
			'promo'       => '"promo": { "text": ' . $str . ' }',
			'launch'      => '"launch": { "announcement": ' . $str . ' }',
			'retargeting' => '"retargeting": { "text": ' . $str . ' }',
		);
		$schema = array();
		foreach ( $channels as $ch ) {
			if ( isset( $fragments[ $ch ] ) ) {
				$schema[] = $fragments[ $ch ];
			}
		}

		$user = "Write premium marketing content for this YAZAN ring. Sound luxurious and believable; focus on "
			. "value, heritage, authenticity, and desirability. Avoid exaggerated claims, hype, fake urgency, and "
			. "excessive emojis (one tasteful mark at most). ADAPT the tone to each platform: Instagram is warm and "
			. "visual; a story is a single evocative line; Facebook/Google ads are crisp and benefit-led; email is "
			. "considered; a launch announcement feels like an occasion; a retargeting message is a gentle, "
			. "confident nudge. For Google Ads, each headline must be ≤ 30 characters and each description "
			. "≤ 90 characters, written in the requested language.\n\n"
			. "PRODUCT:\n" . $context . "\n\n"
			. "Return ONLY JSON with these keys:\n{ " . implode( ', ', $schema ) . " }";

		$envelope = Yazan_AI_Gateway::run(
			array(
				'system'   => Yazan_AI_Prompts::system( 'marketing.generate', $lang ),
				'messages' => array( array( 'role' => 'user', 'content' => $user ) ),
				'json'     => true,
				// Headroom for "thinking" models + several channels so the JSON isn't truncated.
				'max_tokens' => 3072,
			),
			array(
				'task'        => 'marketing.generate',
				'module'      => 'marketing',
				'object_type' => 'product',
				'object_id'   => $product->get_id(),
				'capability'  => 'text',
			)
		);

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
