<?php
/**
 * Starting, stopping, reading and settling an A/B test.
 *
 * The one operation worth reading twice is `promote()`: it copies the WINNING document's published
 * sections into the control's draft and publishes them, then stops the test. Copying rather than
 * re-pointing means the control document stays the one thing the site renders — no permanent
 * dependency on a layout somebody may later delete — and going through the normal publish path
 * means the promotion gets a revision, an audit row and a cache purge like any other publish.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Application\Handler;

use Yazan\Homepage\Domain\Document\DocumentKey;
use Yazan\Homepage\Domain\Document\SectionCollection;
use Yazan\Homepage\Domain\Section\Section;
use Yazan\Homepage\Domain\Exception\SectionNotFound;
use Yazan\Homepage\Domain\Exception\ValidationFailed;
use Yazan\Homepage\Domain\Experiment\Experiment;
use Yazan\Homepage\Domain\Experiment\ExperimentResult;
use Yazan\Homepage\Domain\Port\AuthorizationPort;
use Yazan\Homepage\Domain\Port\ClockPort;
use Yazan\Homepage\Domain\Port\HomepageRepositoryPort;
use Yazan\Homepage\Infrastructure\Persistence\ExperimentStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A/B test use cases.
 */
final class ExperimentHandler {

	/** @var HomepageRepositoryPort */
	private $documents;

	/** @var ExperimentStore */
	private $store;

	/** @var AuthorizationPort */
	private $auth;

	/** @var ClockPort */
	private $clock;

	/**
	 * @param HomepageRepositoryPort $documents Documents.
	 * @param ExperimentStore        $store     Experiment storage.
	 * @param AuthorizationPort      $auth      Authorization.
	 * @param ClockPort              $clock     Clock.
	 */
	public function __construct( HomepageRepositoryPort $documents, ExperimentStore $store, AuthorizationPort $auth, ClockPort $clock ) {
		$this->documents = $documents;
		$this->store     = $store;
		$this->auth      = $auth;
		$this->clock     = $clock;
	}

	/**
	 * The experiment on a document, with its numbers.
	 *
	 * @param DocumentKey $key Control document.
	 * @return array
	 */
	public function read( DocumentKey $key ) {
		$this->auth->require_permission( 'homepage.view' );

		$experiment = $this->store->get( $key );

		if ( ! $experiment ) {
			return array( 'experiment' => null, 'results' => array(), 'uplift' => null );
		}

		$labels = array(
			Experiment::CONTROL             => __( 'Current layout', 'yazan' ),
			$experiment->variant()->value() => $this->title_of( $experiment->variant() ),
		);

		$totals = $this->store->totals( $key->value(), $experiment->started_at() );

		// Both arms always appear, even at zero. An arm missing from a report reads as "no data
		// yet" when it may mean "nobody has ever been sent there".
		foreach ( array_keys( $labels ) as $arm ) {
			if ( ! isset( $totals[ $arm ] ) ) {
				$totals[ $arm ] = array( 'views' => 0, 'orders' => 0, 'revenue' => 0.0 );
			}
		}

		$summary = ExperimentResult::summarise( $totals, $labels );

		return array(
			'experiment' => $experiment->to_array(),
			'results'    => $summary,
			'uplift'     => ExperimentResult::uplift( $summary ),
			'min_views'  => ExperimentResult::MIN_VIEWS,
		);
	}

	/**
	 * Create or reconfigure a test.
	 *
	 * @param DocumentKey $key     Control.
	 * @param string      $variant Variant document key.
	 * @param int         $split   Percentage to the variant.
	 * @return array
	 * @throws SectionNotFound When the variant does not exist.
	 * @throws ValidationFailed When the variant has nothing published.
	 */
	public function save( DocumentKey $key, $variant, $split ) {
		$this->auth->require_permission( 'homepage.experiment' );

		$variant_key = DocumentKey::from( (string) $variant );
		$document    = $this->documents->get( $variant_key );

		if ( ! $document->id() ) {
			throw new SectionNotFound( __( 'That layout does not exist.', 'yazan' ) );
		}

		if ( ! $document->has_live_content() ) {
			// A variant with nothing published renders the theme's own homepage, so half the
			// visitors would be in a test against a page that is not the one being proposed.
			throw new ValidationFailed( __( 'Publish the variant layout before testing it.', 'yazan' ) );
		}

		$existing = $this->store->get( $key );
		$changed  = ! $existing || $existing->variant()->value() !== $variant_key->value();

		$experiment = new Experiment( $key, $variant_key, $split, $existing ? $existing->is_running() : false, $existing ? $existing->started_at() : null );

		if ( $changed ) {
			// A different challenger is a different test. Keeping the old counts would read the
			// new variant's numbers on top of the previous one's.
			$this->store->clear( $key->value() );
		}

		$this->store->save( $experiment );

		return $experiment->to_array();
	}

	/**
	 * @param DocumentKey $key Control.
	 * @return array
	 * @throws SectionNotFound When there is nothing to start.
	 */
	public function start( DocumentKey $key ) {
		$this->auth->require_permission( 'homepage.experiment' );

		$experiment = $this->require_experiment( $key );

		if ( ! $experiment->started_at() ) {
			// Only a first start clears; restarting after a pause keeps the numbers, because the
			// same two layouts are still being compared.
			$this->store->clear( $key->value() );
		}

		$started = $experiment->start( $this->clock->now() );
		$this->store->save( $started );

		return $started->to_array();
	}

	/**
	 * @param DocumentKey $key Control.
	 * @return array
	 */
	public function stop( DocumentKey $key ) {
		$this->auth->require_permission( 'homepage.experiment' );

		$stopped = $this->require_experiment( $key )->stop();
		$this->store->save( $stopped );

		return $stopped->to_array();
	}

	/**
	 * Remove the test and its numbers.
	 *
	 * @param DocumentKey $key Control.
	 * @return array
	 */
	public function remove( DocumentKey $key ) {
		$this->auth->require_permission( 'homepage.experiment' );

		$this->store->remove( $key );
		$this->store->clear( $key->value() );

		return array( 'removed' => true );
	}

	/**
	 * Make the variant the real homepage, and end the test.
	 *
	 * @param DocumentKey $key      Control.
	 * @param PublishHandler $publish Publish use case, for the revision and the audit trail.
	 * @return array
	 * @throws ValidationFailed When the variant has nothing to promote.
	 */
	public function promote( DocumentKey $key, PublishHandler $publish ) {
		$this->auth->require_permission( 'homepage.experiment' );
		$this->auth->require_permission( 'homepage.publish' );

		$experiment = $this->require_experiment( $key );
		$variant    = $this->documents->get( $experiment->variant() );

		if ( ! $variant->has_live_content() ) {
			throw new ValidationFailed( __( 'The variant has nothing published to promote.', 'yazan' ) );
		}

		$control = $this->documents->get( $key );

		$sections = array();

		foreach ( (array) $variant->live_sections() as $row ) {
			$sections[] = Section::from_array( (array) $row );
		}

		$control->replace_sections( SectionCollection::of( $sections ), 'ab_promote', false );

		$this->documents->save( $control );

		// Published through the normal path: same revision, same audit row, same cache purge.
		$result = $publish->handle( $key, 'Promoted the A/B variant', $control->version()->value() );

		$this->store->save( $experiment->stop() );

		return array(
			'promoted' => $experiment->variant()->value(),
			'publish'  => $result,
		);
	}

	/**
	 * @param DocumentKey $key Control.
	 * @return Experiment
	 * @throws SectionNotFound When none is configured.
	 */
	private function require_experiment( DocumentKey $key ) {
		$experiment = $this->store->get( $key );

		if ( ! $experiment ) {
			throw new SectionNotFound( __( 'No experiment is set up for this layout.', 'yazan' ) );
		}

		return $experiment;
	}

	/**
	 * @param DocumentKey $key Document.
	 * @return string
	 */
	private function title_of( DocumentKey $key ) {
		$document = $this->documents->get( $key );
		$title    = $document->title();

		return '' !== $title ? $title : $key->value();
	}
}
