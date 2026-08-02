<?php
/**
 * Decides which homepage a visitor gets, and counts what happened.
 *
 * This is the one place in the module that serves different HTML to different visitors, and the
 * module's own rule until now was that it must never do that — a full-page cache stores the first
 * response and hands it to everyone, so one visitor's variant becomes everybody's. Device-based
 * hiding was refused for exactly this reason.
 *
 * An A/B test cannot be done any other way, so the rule is not bent, it is paid for: EVERY request
 * that takes part is served uncached (`DONOTCACHEPAGE` + `nocache_headers()`), including the very
 * first one, before the visitor has been assigned anything. A test therefore costs real server
 * work, which is the honest price and a good reason to run it for a week rather than a quarter.
 *
 * Two exclusions, both deliberate:
 *
 *   · Staff. Anyone who may open the Homepage Manager is never enrolled — the people who reload
 *     the homepage twenty times to check their work would otherwise be the largest cohort in it.
 *   · Requests that cannot carry state (no cookie support, a REST or cron context). They see the
 *     control and are not counted, rather than being counted as a view nobody could convert.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Presentation\Render;

use Yazan\Homepage\Domain\Document\DocumentKey;
use Yazan\Homepage\Domain\Experiment\Experiment;
use Yazan\Homepage\Infrastructure\Bootstrap\ServiceFactory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Variant assignment for the front page.
 */
final class ExperimentRunner {

	/** Cookie prefix. The control document key is appended. */
	const COOKIE = 'yz_ab_';

	/** How long a visitor stays in the arm they were given. */
	const TTL_DAYS = 30;

	/** @var array{experiment:Experiment,arm:string}|null This request's enrolment. */
	private static $current = null;

	/**
	 * Which document should render, given the one that was asked for.
	 *
	 * @param DocumentKey $requested The document the page would otherwise render.
	 * @return DocumentKey
	 */
	public static function resolve( DocumentKey $requested ) {
		self::$current = null;

		$experiment = ServiceFactory::experiments()->get( $requested );

		if ( ! $experiment || ! $experiment->is_running() ) {
			return $requested;
		}

		// Staff never take part — see the note at the top.
		if ( ServiceFactory::auth()->can( 'homepage.view' ) ) {
			return $requested;
		}

		if ( ! self::can_carry_state() ) {
			return $requested;
		}

		$arm = self::remembered( $experiment );

		if ( null === $arm ) {
			$arm = $experiment->assign( self::roll() );
			self::remember( $experiment, $arm );
		}

		self::uncacheable();

		self::$current = array(
			'experiment' => $experiment,
			'arm'        => $arm,
		);

		ServiceFactory::experiments()->record_view(
			$experiment->control()->value(),
			$arm,
			self::today()
		);

		return $experiment->document_for( $arm );
	}

	/**
	 * The arm this request belongs to, for anything downstream that needs it.
	 *
	 * @return array{control:string,arm:string}|null
	 */
	public static function current() {
		if ( ! self::$current ) {
			return null;
		}

		return array(
			'control' => self::$current['experiment']->control()->value(),
			'arm'     => self::$current['arm'],
		);
	}

	/**
	 * Record a purchase against the arm stored on the order.
	 *
	 * Read from the ORDER, not from the cookie: the order may be paid minutes later on a gateway
	 * return, or by a webhook with no cookies at all. Whatever arm the basket was built in is the
	 * one that earned the money.
	 *
	 * @param string $stamp   "control-key|arm" as stored on the order.
	 * @param float  $revenue Order total.
	 * @param string $date    Y-m-d.
	 * @return void
	 */
	public static function record_order( $stamp, $revenue, $date ) {
		$parts = explode( '|', (string) $stamp, 2 );

		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
			return;
		}

		ServiceFactory::experiments()->record_order( $parts[0], $parts[1], $date, (float) $revenue );
	}

	/**
	 * What to stamp on an order created during this request.
	 *
	 * The homepage is where an arm is ASSIGNED; the checkout is a different request, minutes or
	 * hours later, and nothing on it recomputes that assignment. The first version of this read
	 * `self::$current` — which is only ever set while the front page renders — so every real order
	 * was stamped with an empty string and the report showed zero conversions for both arms while
	 * the visitor counters climbed. Caught by a live test order, not by a unit test: the two halves
	 * were each correct on their own.
	 *
	 * The cookie is the durable record. Read it here.
	 *
	 * @return string "control-key|arm", or empty when this visitor is not in a test.
	 */
	public static function stamp() {
		$current = self::current();

		if ( $current ) {
			return $current['control'] . '|' . $current['arm'];
		}

		foreach ( ServiceFactory::experiments()->all() as $control => $experiment ) {
			if ( ! $experiment->is_running() ) {
				continue;
			}

			$name = self::COOKIE . $control;

			if ( empty( $_COOKIE[ $name ] ) ) {
				continue;
			}

			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised here.
			$arm = sanitize_key( wp_unslash( $_COOKIE[ $name ] ) );

			// A cookie naming an arm this experiment no longer has is stale — an order must not be
			// credited to a layout that is no longer in the comparison.
			if ( $experiment->recognises( $arm ) ) {
				return $control . '|' . $arm;
			}
		}

		return '';
	}

	/**
	 * The arm remembered from a previous visit, if it is still one of this experiment's arms.
	 *
	 * @param Experiment $experiment Experiment.
	 * @return string|null
	 */
	private static function remembered( Experiment $experiment ) {
		$name = self::COOKIE . $experiment->control()->value();

		if ( empty( $_COOKIE[ $name ] ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised on the next line.
		$value = sanitize_key( wp_unslash( $_COOKIE[ $name ] ) );

		// A cookie naming a variant this experiment no longer uses is stale, not authoritative.
		return $experiment->recognises( $value ) ? $value : null;
	}

	/**
	 * @param Experiment $experiment Experiment.
	 * @param string     $arm        Arm.
	 * @return void
	 */
	private static function remember( Experiment $experiment, $arm ) {
		if ( headers_sent() ) {
			// Assignment happens on template_redirect, long before output — but if some other
			// plugin has already flushed, setting a cookie would emit a warning and change
			// nothing. Silently skipping means this visitor is simply re-rolled next time.
			return;
		}

		setcookie(
			self::COOKIE . $experiment->control()->value(),
			$arm,
			array(
				'expires'  => time() + ( self::TTL_DAYS * DAY_IN_SECONDS ),
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				// Not readable by scripts: nothing in the browser needs it, and a cookie the page
				// can rewrite is a cookie that can be used to pick your own variant.
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);

		// So THIS request behaves like the next one rather than re-rolling itself.
		$_COOKIE[ self::COOKIE . $experiment->control()->value() ] = $arm;
	}

	/**
	 * A number 0-99.
	 *
	 * `wp_rand` rather than `rand`: the split has to be even, and PHP's default generator is
	 * seeded per process on some setups — which would send whole batches of visitors the same way.
	 *
	 * @return int
	 */
	private static function roll() {
		return (int) wp_rand( 0, 99 );
	}

	/**
	 * Can this request hold an assignment at all?
	 *
	 * @return bool
	 */
	private static function can_carry_state() {
		if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		return ! is_admin();
	}

	/**
	 * Take this response out of every cache we can reach.
	 *
	 * @return void
	 */
	private static function uncacheable() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		nocache_headers();
	}

	/**
	 * Today in the SITE's timezone, so a day in the report matches a day in the shop's reports.
	 *
	 * @return string
	 */
	private static function today() {
		return (string) wp_date( 'Y-m-d' );
	}
}
