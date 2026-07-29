<?php
/**
 * Manual submission verifier (Method 2).
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Social;

use Yazan\Rewards\Core\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verifies a customer-submitted post URL by the six manual checks: the URL exists,
 * account ownership (the post's author matches the customer's linked/declared handle),
 * the required hashtag, the required mention/link, date validity (within the window),
 * and duplicate content (the URL wasn't submitted before). A duplicate or a missing
 * URL is a hard reject; otherwise the submission is auto-approved ONLY when every
 * requested check clearly passes (and auto-approve is enabled), else it is queued for
 * admin review — so an imperfect scrape never silently rejects a genuine post.
 */
final class ManualVerifier {

	/**
	 * @param UrlFetcher       $fetcher SSRF-guarded fetcher.
	 * @param ContentScraper   $scraper HTML scraper.
	 * @param SocialRepository $repo    Social actions repo (duplicate check).
	 * @param Settings         $settings Settings.
	 */
	public function __construct(
		private UrlFetcher $fetcher,
		private ContentScraper $scraper,
		private SocialRepository $repo,
		private Settings $settings
	) {}

	/**
	 * Run the manual checks.
	 *
	 * @param array $submission { url, exclude_id }.
	 * @param array $context    { handle, required_hashtag, required_mention, window_days }.
	 * @return array{verdict:string,method:string,checks:array<string,mixed>,reasons:string[]}
	 */
	public function verify( array $submission, array $context ): array {
		$url    = (string) ( $submission['url'] ?? '' );
		$checks = array(
			'url_exists' => null,
			'ownership'  => null,
			'hashtag'    => null,
			'mention'    => null,
			'date'       => null,
			'duplicate'  => false,
		);

		// (6) Duplicate content — the same post URL already submitted (any customer).
		$hash                = $this->repo->url_hash( $url );
		$checks['duplicate'] = $this->repo->has_url_hash( $hash, (int) ( $submission['exclude_id'] ?? 0 ) );
		if ( $checks['duplicate'] ) {
			return $this->result( 'rejected', $checks, array( 'duplicate' ) );
		}

		$handle  = strtolower( (string) ( $context['handle'] ?? '' ) );
		$tag     = (string) ( $context['required_hashtag'] ?? '' );
		$mention = (string) ( $context['required_mention'] ?? '' );
		$window  = (int) ( $context['window_days'] ?? 0 );

		if ( ! $this->settings->get( 'social.verify.manual_fetch', true ) ) {
			// Fetching disabled — record nothing verifiable and queue for admin.
			return $this->result( 'pending', $checks, array( 'manual_review' ) );
		}

		// (1) URL exists.
		$fetch               = $this->fetcher->fetch( $url );
		$checks['url_exists'] = $fetch['ok'];
		if ( ! $fetch['ok'] ) {
			return $this->result( 'rejected', $checks, array( 'url_missing' ) );
		}
		$html = $fetch['body'];

		// (2) Account ownership.
		if ( '' !== $handle ) {
			$author             = $this->scraper->author_handle( $html );
			$checks['ownership'] = '' !== $author ? ( $author === $handle ) : null;
		}
		// (3) Required hashtag.
		if ( '' !== $tag ) {
			$checks['hashtag'] = $this->scraper->has_hashtag( $html, $tag );
		}
		// (4) Required mention / link.
		if ( '' !== $mention ) {
			$checks['mention'] = $this->scraper->has_mention( $html, $mention );
		}
		// (5) Date validity.
		if ( $window > 0 ) {
			$date = $this->scraper->post_date( $html );
			if ( null !== $date ) {
				$checks['date'] = $date >= ( time() - $window * DAY_IN_SECONDS );
			}
		}

		// Verdict: auto-approve only when every REQUESTED check clearly passes.
		$requested_ok =
			( '' === $tag || true === $checks['hashtag'] ) &&
			( '' === $mention || true === $checks['mention'] ) &&
			( '' === $handle || true === $checks['ownership'] ) &&
			( $window <= 0 || true === $checks['date'] );

		$auto = (bool) $this->settings->get( 'social.verify.auto_approve', true );
		if ( $auto && true === $checks['url_exists'] && $requested_ok ) {
			return $this->result( 'approved', $checks, array() );
		}
		return $this->result( 'pending', $checks, array( 'manual_review' ) );
	}

	/**
	 * Shape a result.
	 *
	 * @param string $verdict Verdict.
	 * @param array  $checks  Check results.
	 * @param array  $reasons Reasons.
	 * @return array{verdict:string,method:string,checks:array,reasons:array}
	 */
	private function result( string $verdict, array $checks, array $reasons ): array {
		return array(
			'verdict' => $verdict,
			'method'  => 'manual',
			'checks'  => $checks,
			'reasons' => $reasons,
		);
	}
}
