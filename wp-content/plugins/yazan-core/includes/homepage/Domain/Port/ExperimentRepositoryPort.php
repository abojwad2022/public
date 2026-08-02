<?php
/**
 * Where A/B configuration and counters live, as far as the use cases are concerned.
 *
 * This port was missing, and its absence was not cosmetic. `ExperimentHandler` named the concrete
 * `Infrastructure\Persistence\ExperimentStore` in its constructor, so an application use case
 * depended directly on a class that needs `$wpdb` — the one dependency direction this module is
 * built to forbid. The practical cost showed up immediately: the handler could not be tested at
 * all without a database, which is why starting, stopping and deleting a test had no tests and,
 * as it turned out, no audit trail either. Nobody could see the gap because nobody could run the
 * code.
 *
 * Declaring the port costs one file and inverts the arrow.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Port;

use Yazan\Homepage\Domain\Experiment\Experiment;
use Yazan\Homepage\Domain\Document\DocumentKey;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Experiment persistence boundary.
 */
interface ExperimentRepositoryPort {

	/**
	 * The experiment whose control is this document.
	 *
	 * @param DocumentKey $key Control document.
	 * @return Experiment|null
	 */
	public function get( DocumentKey $key );

	/**
	 * Every stored experiment, keyed by control document.
	 *
	 * @return array<string,Experiment>
	 */
	public function all();

	/**
	 * @param Experiment $experiment Experiment.
	 * @return void
	 */
	public function save( Experiment $experiment );

	/**
	 * @param DocumentKey $key Control document.
	 * @return void
	 */
	public function remove( DocumentKey $key );

	/**
	 * @param string $control Control document key.
	 * @param string $arm     Arm.
	 * @param string $date    Y-m-d in site time.
	 * @return void
	 */
	public function record_view( $control, $arm, $date );

	/**
	 * @param string $control Control document key.
	 * @param string $arm     Arm.
	 * @param string $date    Y-m-d in site time.
	 * @param float  $revenue Order total.
	 * @return void
	 */
	public function record_order( $control, $arm, $date, $revenue );

	/**
	 * Totals per arm since a moment.
	 *
	 * @param string   $control Control document key.
	 * @param int|null $since   UTC timestamp, or null for everything.
	 * @return array<string,array{views:int,orders:int,revenue:float}>
	 */
	public function totals( $control, $since = null );

	/**
	 * The same numbers, one row per arm per day, oldest first.
	 *
	 * @param string   $control Control document key.
	 * @param int|null $since   UTC timestamp, or null for everything.
	 * @return array<int,array{date:string,arm:string,views:int,orders:int,revenue:float}>
	 */
	public function daily( $control, $since = null );

	/**
	 * Discard an experiment's counters.
	 *
	 * @param string $control Control document key.
	 * @return void
	 */
	public function clear( $control );
}
