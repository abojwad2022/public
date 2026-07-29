<?php
/**
 * Best-effort HTML/OpenGraph scraper for manual content verification.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Social;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extracts the signals the manual verifier needs from a fetched post page: a
 * required hashtag, a required mention/link, the author handle, and the post date.
 * Public social pages expose these via OpenGraph/meta tags, JSON-LD, or inline text;
 * every method is a defensive best-effort and returns a null/false when unsure so a
 * missing signal never falsely "passes".
 */
final class ContentScraper {

	/**
	 * Whether the page contains the given hashtag (with or without a leading #).
	 *
	 * @param string $html HTML.
	 * @param string $tag  Hashtag.
	 * @return bool
	 */
	public function has_hashtag( string $html, string $tag ): bool {
		$tag = ltrim( trim( $tag ), '#' );
		if ( '' === $tag ) {
			return true; // No requirement.
		}
		return (bool) preg_match( '/#' . preg_quote( $tag, '/' ) . '\b/iu', $html );
	}

	/**
	 * Whether the page contains the required mention (an @handle) or link (a URL/string).
	 *
	 * @param string $html    HTML.
	 * @param string $mention Mention or link.
	 * @return bool
	 */
	public function has_mention( string $html, string $mention ): bool {
		$mention = trim( $mention );
		if ( '' === $mention ) {
			return true; // No requirement.
		}
		if ( str_starts_with( $mention, '@' ) ) {
			$handle = ltrim( $mention, '@' );
			return (bool) preg_match( '/@' . preg_quote( $handle, '/' ) . '\b/iu', $html );
		}
		// Otherwise treat it as a literal substring (URL or brand string).
		$needle = strtolower( $this->strip_scheme( $mention ) );
		return '' !== $needle && str_contains( strtolower( $html ), $needle );
	}

	/**
	 * The post's author handle from OpenGraph/JSON-LD, or ''.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public function author_handle( string $html ): string {
		// JSON-LD author.name / author.alternateName.
		if ( preg_match( '/"author"\s*:\s*\{[^}]*?"(?:alternateName|name)"\s*:\s*"([^"]+)"/i', $html, $m ) ) {
			return $this->clean_handle( $m[1] );
		}
		// og:title often is "Name (@handle) • ...".
		$title = $this->meta_content( $html, 'og:title' );
		if ( '' !== $title && preg_match( '/@([A-Za-z0-9._]+)/', $title, $m ) ) {
			return $this->clean_handle( $m[1] );
		}
		// meta[name=author].
		if ( preg_match( '/<meta[^>]+name=["\']author["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m ) ) {
			return $this->clean_handle( $m[1] );
		}
		return '';
	}

	/**
	 * The post's published timestamp (UTC epoch), or null.
	 *
	 * @param string $html HTML.
	 * @return int|null
	 */
	public function post_date( string $html ): ?int {
		$patterns = array(
			'/<meta[^>]+property=["\']article:published_time["\'][^>]+content=["\']([^"\']+)["\']/i',
			'/<meta[^>]+property=["\']og:updated_time["\'][^>]+content=["\']([^"\']+)["\']/i',
			'/"datePublished"\s*:\s*"([^"]+)"/i',
			'/"uploadDate"\s*:\s*"([^"]+)"/i',
		);
		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $html, $m ) ) {
				$ts = strtotime( $m[1] );
				if ( false !== $ts ) {
					return $ts;
				}
			}
		}
		return null;
	}

	/**
	 * Read an OpenGraph/meta content value, or ''.
	 *
	 * @param string $html     HTML.
	 * @param string $property Property, e.g. "og:title".
	 * @return string
	 */
	public function meta_content( string $html, string $property ): string {
		$prop = preg_quote( $property, '/' );
		if ( preg_match( '/<meta[^>]+(?:property|name)=["\']' . $prop . '["\'][^>]+content=["\']([^"\']*)["\']/i', $html, $m ) ) {
			return html_entity_decode( $m[1], ENT_QUOTES );
		}
		return '';
	}

	/**
	 * Normalise a handle (lowercase, strip @ and surrounding punctuation).
	 *
	 * @param string $handle Handle.
	 * @return string
	 */
	private function clean_handle( string $handle ): string {
		return strtolower( ltrim( trim( $handle ), '@' ) );
	}

	/**
	 * Strip scheme + leading www from a URL for substring matching.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function strip_scheme( string $value ): string {
		$value = preg_replace( '#^https?://#i', '', $value );
		return preg_replace( '/^www\./i', '', (string) $value );
	}
}
