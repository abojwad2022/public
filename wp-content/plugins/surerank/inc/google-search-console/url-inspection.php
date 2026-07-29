<?php
/**
 * URL Inspection
 *
 * Wraps the Google Search Console URL Inspection API and normalizes the
 * response into the five indexing states the SureRank meta box renders.
 * Also owns the post/term meta cache layer.
 *
 * @since 1.7.5
 * @package SureRank
 */

namespace SureRank\Inc\GoogleSearchConsole;

use SureRank\Inc\Frontend\Crawl_Optimization;
use SureRank\Inc\Traits\Get_Instance;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Url_Inspection
 *
 * @since 1.7.5
 */
class Url_Inspection {

	use Get_Instance;

	/**
	 * Cache key stored against post/term meta.
	 */
	public const META_KEY = 'surerank_url_inspection';

	/**
	 * Cache freshness in seconds (12 hours).
	 */
	public const FRESH_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Search Console URL Inspection endpoint.
	 */
	private const ENDPOINT = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';

	/**
	 * Register the cached inspection result as protected meta on every
	 * public post type and every public taxonomy.
	 *
	 * @since 1.7.5
	 * @return void
	 */
	public static function register_meta_keys(): void {
		$args = [
			'type'          => 'string',
			'single'        => true,
			'show_in_rest'  => false,
			'auth_callback' => '__return_false',
		];

		foreach ( get_post_types( [ 'public' => true ] ) as $post_type ) {
			register_post_meta( $post_type, self::META_KEY, $args );
		}

		foreach ( get_taxonomies( [ 'public' => true ] ) as $taxonomy ) {
			register_term_meta( $taxonomy, self::META_KEY, $args );
		}
	}

	/**
	 * Inspect a post URL — handles cache get/set + freshness.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $refresh Force cache bypass.
	 * @return array{ok:bool, payload:array<string, mixed>, code?:string}
	 * @since 1.7.5
	 */
	public function get_for_post( int $post_id, bool $refresh = false ): array {
		$url = $this->resolve_post_url( $post_id );
		if ( '' === $url ) {
			return $this->error_response( 'no_permalink', __( 'No public URL available yet (draft or invalid object).', 'surerank' ) );
		}
		return $this->get_cached_or_fetch( 'post', $post_id, $url, $refresh );
	}

	/**
	 * Inspect a term URL — handles cache get/set + freshness.
	 *
	 * @param int  $term_id Term ID.
	 * @param bool $refresh Force cache bypass.
	 * @return array{ok:bool, payload:array<string, mixed>, code?:string}
	 * @since 1.7.5
	 */
	public function get_for_term( int $term_id, bool $refresh = false ): array {
		$url = $this->resolve_term_url( $term_id );
		if ( '' === $url ) {
			return $this->error_response( 'no_permalink', __( 'No public URL available yet (draft or invalid object).', 'surerank' ) );
		}
		return $this->get_cached_or_fetch( 'term', $term_id, $url, $refresh );
	}

	/**
	 * Whether the selected GSC property matches the current WordPress site.
	 *
	 * Inspection results are only meaningful when the connected property
	 * actually covers this site. If the user connected GSC but selected a
	 * different property (different domain), inspection is suppressed so
	 * stale or irrelevant data can't surface.
	 *
	 * @return bool
	 * @since 1.7.5
	 */
	public static function selected_site_matches_current(): bool {
		$selected = (string) Auth::get_instance()->get_credentials( 'site_url' );
		if ( '' === $selected ) {
			return false;
		}
		return self::normalize_site_url( $selected ) === self::normalize_site_url( (string) get_site_url() );
	}

	/**
	 * Normalize a Search Console / WP site URL for comparison.
	 *
	 * Strips an `sc-domain:` prefix (domain properties), otherwise strips
	 * scheme/`www.`/trailing slashes so a property like
	 * `https://www.example.com/` matches a WP install at `https://example.com`.
	 *
	 * @param string $url Raw URL or `sc-domain:` property value.
	 * @return string Normalized comparable string.
	 * @since 1.7.5
	 */
	public static function normalize_site_url( string $url ): string {
		if ( 0 === strpos( $url, 'sc-domain:' ) ) {
			return rtrim( substr( $url, strlen( 'sc-domain:' ) ), '/' );
		}
		$stripped = (string) preg_replace( '#^https?://#i', '', $url );
		$stripped = (string) preg_replace( '#^www\.#i', '', $stripped );
		return rtrim( $stripped, '/' );
	}

	/**
	 * Map a Search Console inspection response into the five SureRank states.
	 *
	 * @param array<string, mixed> $response Raw response from inspect API.
	 * @return array<string, mixed> Normalized payload.
	 * @since 1.7.5
	 */
	protected function normalize( array $response ): array {
		// API shape: { inspectionResult: { indexStatusResult: { ... } } }.
		$result       = $response['inspectionResult'] ?? [];
		$index_result = $result['indexStatusResult'] ?? [];
		$verdict      = (string) ( $index_result['verdict'] ?? '' );
		$coverage     = (string) ( $index_result['coverageState'] ?? '' );
		$indexing_st  = (string) ( $index_result['indexingState'] ?? '' );
		$last_crawl   = (string) ( $index_result['lastCrawlTime'] ?? '' );

		return [
			'status'        => $this->resolve_status( $verdict, $coverage, $indexing_st ),
			'coverageState' => $coverage,
			'verdict'       => $verdict,
			'indexingState' => $indexing_st,
			'lastCrawlTime' => $last_crawl,
			'checked_at'    => time(),
		];
	}

	/**
	 * Cache-aware fetch — returns fresh cache when within TTL, otherwise
	 * calls Google and persists.
	 *
	 * @param string $kind    'post' or 'term'.
	 * @param int    $object  Post or term ID.
	 * @param string $url     URL to inspect.
	 * @param bool   $refresh Force cache bypass.
	 * @return array{ok:bool, payload:array<string, mixed>, code?:string}
	 * @since 1.7.5
	 */
	private function get_cached_or_fetch( string $kind, int $object, string $url, bool $refresh ): array {
		$cached = $this->read_cache( $kind, $object );

		if ( $cached && ! $refresh && $this->is_fresh( $cached ) ) {
			$cached['fresh'] = true;
			return [
				'ok'      => true,
				'payload' => $cached,
			];
		}

		$result = $this->call_api( $url );

		if ( isset( $result['error'] ) && $result['error'] ) {
			$payload = [
				'message'    => $result['message'] ?? __( 'Unable to fetch indexing status.', 'surerank' ),
				'error_code' => $result['code'] ?? 'unknown_error',
			];
			if ( $cached ) {
				$payload          = array_merge( $cached, $payload );
				$payload['stale'] = true;
			}
			return [
				'ok'      => false,
				'payload' => $payload,
				'code'    => (string) ( $result['code'] ?? 'unknown_error' ),
			];
		}

		$this->write_cache( $kind, $object, $result );
		$result['fresh'] = false;
		return [
			'ok'      => true,
			'payload' => $result,
		];
	}

	/**
	 * Read cached inspection result.
	 *
	 * @param string $kind   'post' or 'term'.
	 * @param int    $object Object ID.
	 * @return array<string, mixed>|null
	 * @since 1.7.5
	 */
	private function read_cache( string $kind, int $object ): ?array {
		$cached = 'term' === $kind
			? get_term_meta( $object, self::META_KEY, true )
			: get_post_meta( $object, self::META_KEY, true );
		return is_array( $cached ) && ! empty( $cached ) ? $cached : null;
	}

	/**
	 * Persist inspection result to meta.
	 *
	 * @param string               $kind   'post' or 'term'.
	 * @param int                  $object Object ID.
	 * @param array<string, mixed> $result Result payload.
	 * @return void
	 * @since 1.7.5
	 */
	private function write_cache( string $kind, int $object, array $result ): void {
		if ( 'term' === $kind ) {
			update_term_meta( $object, self::META_KEY, $result );
		} else {
			update_post_meta( $object, self::META_KEY, $result );
		}
	}

	/**
	 * Whether the cache entry is within the freshness TTL.
	 *
	 * @param array<string, mixed> $cached Cached payload.
	 * @return bool
	 * @since 1.7.5
	 */
	private function is_fresh( array $cached ): bool {
		return isset( $cached['checked_at'] )
			&& ( time() - (int) $cached['checked_at'] ) < self::FRESH_TTL;
	}

	/**
	 * Call the Search Console URL Inspection API for the given URL.
	 *
	 * @param string $url Fully-qualified URL to inspect.
	 * @return array<string, mixed> Normalized result or error payload from GoogleConsole::call_api().
	 * @since 1.7.5
	 */
	private function call_api( string $url ): array {
		$site_url = (string) Auth::get_instance()->get_credentials( 'site_url' );

		if ( '' === $site_url ) {
			return [
				'error'   => true,
				'message' => __( 'No Search Console property selected.', 'surerank' ),
				'code'    => 'no_site_selected',
			];
		}

		$body = [
			'inspectionUrl' => $url,
			'siteUrl'       => $site_url,
			'languageCode'  => substr( get_locale(), 0, 2 ),
		];

		$response = GoogleConsole::get_instance()->call_api( self::ENDPOINT, 'POST', $body );

		if ( isset( $response['error'] ) && $response['error'] ) {
			return $response;
		}

		return $this->normalize( $response );
	}

	/**
	 * Resolve one of the five SureRank indexing statuses.
	 *
	 * Driven primarily off `coverageState` — Google guarantees the wording
	 * matches what's shown inside the Search Console UI, so it's the most
	 * stable signal across API versions.
	 *
	 * @param string $verdict     indexStatusResult.verdict (PASS|PARTIAL|FAIL|NEUTRAL).
	 * @param string $coverage    indexStatusResult.coverageState.
	 * @param string $indexing_st indexStatusResult.indexingState.
	 * @return string One of: indexed | crawled_not_indexed | discovered_not_indexed | noindex | other_not_indexed.
	 * @since 1.7.5
	 */
	private function resolve_status( string $verdict, string $coverage, string $indexing_st ): string {
		if ( in_array( $indexing_st, [ 'BLOCKED_BY_META_TAG', 'BLOCKED_BY_HTTP_HEADER' ], true ) ) {
			return 'noindex';
		}

		if ( 'PASS' === $verdict ) {
			return 'indexed';
		}

		if ( 0 === strpos( $coverage, 'Crawled' ) ) {
			return 'crawled_not_indexed';
		}

		if ( 0 === strpos( $coverage, 'Discovered' ) ) {
			return 'discovered_not_indexed';
		}

		return 'other_not_indexed';
	}

	/**
	 * Resolve a post's public URL (published posts only).
	 *
	 * @param int $post_id Post ID.
	 * @return string Permalink, or empty string when unavailable.
	 * @since 1.7.5
	 */
	private function resolve_post_url( int $post_id ): string {
		if ( $post_id <= 0 ) {
			return '';
		}
		if ( 'publish' !== get_post_status( $post_id ) ) {
			return '';
		}
		$permalink = get_permalink( $post_id );
		return is_string( $permalink ) ? $permalink : '';
	}

	/**
	 * Resolve a term's public URL, mirroring SureRank's canonical when
	 * `surerank_remove_category_base` filter is active.
	 *
	 * @param int $term_id Term ID.
	 * @return string Term link, or empty string when unavailable.
	 * @since 1.7.5
	 */
	private function resolve_term_url( int $term_id ): string {
		if ( $term_id <= 0 ) {
			return '';
		}
		$term_link = get_term_link( $term_id );
		if ( is_wp_error( $term_link ) ) {
			return '';
		}

		$term = get_term( $term_id );
		if ( $term instanceof WP_Term
			&& 'category' === $term->taxonomy
			&& apply_filters( 'surerank_remove_category_base', false )
		) {
			$crawl     = Crawl_Optimization::get_instance();
			$term_link = $crawl->remove_category_base_from_links( $term_link, $term, $term->taxonomy );
		}

		return (string) $term_link;
	}

	/**
	 * Shape a non-API error response (e.g. missing permalink).
	 *
	 * @param string $code    Stable error code for the JS layer.
	 * @param string $message Human-readable message.
	 * @return array{ok:bool, payload:array<string, mixed>, code:string}
	 * @since 1.7.5
	 */
	private function error_response( string $code, string $message ): array {
		return [
			'ok'      => false,
			'payload' => [
				'message'    => $message,
				'error_code' => $code,
			],
			'code'    => $code,
		];
	}
}
