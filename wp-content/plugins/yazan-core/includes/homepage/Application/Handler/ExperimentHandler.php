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
use Yazan\Homepage\Domain\Event\DomainEvent;
use Yazan\Homepage\Domain\Port\AuthorizationPort;
use Yazan\Homepage\Domain\Port\ClockPort;
use Yazan\Homepage\Domain\Port\EventDispatcherPort;
use Yazan\Homepage\Domain\Port\HomepageRepositoryPort;
use Yazan\Homepage\Application\Service\ExperimentCsv;
use Yazan\Homepage\Domain\Port\ExperimentRepositoryPort;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A/B test use cases.
 */
final class ExperimentHandler {

	/** @var HomepageRepositoryPort */
	private $documents;

	/** @var ExperimentRepositoryPort */
	private $store;

	/** @var AuthorizationPort */
	private $auth;

	/** @var ClockPort */
	private $clock;

	/**
	 * @var EventDispatcherPort|null
	 *
	 * Optional so an existing caller that built this handler with four arguments still works, but
	 * every route passes it. Without it the A/B lifecycle writes nothing to the audit trail, and
	 * starting a test is one of the largest changes anybody can make from this screen.
	 */
	private $events;

	/**
	 * @param HomepageRepositoryPort   $documents Documents.
	 * @param ExperimentRepositoryPort $store     Experiment storage.
	 * @param AuthorizationPort        $auth      Authorization.
	 * @param ClockPort                $clock     Clock.
	 * @param EventDispatcherPort|null $events    Dispatcher, for the audit trail.
	 */
	public function __construct( HomepageRepositoryPort $documents, ExperimentRepositoryPort $store, AuthorizationPort $auth, ClockPort $clock, EventDispatcherPort $events = null ) {
		$this->documents = $documents;
		$this->store     = $store;
		$this->auth      = $auth;
		$this->clock     = $clock;
		$this->events    = $events;
	}

	/**
	 * Raise an audit event, if anyone is listening.
	 *
	 * @param string      $name   Event name.
	 * @param DocumentKey $key    Control document.
	 * @param array       $before State before, or an empty array.
	 * @param array       $after  State after, or an empty array.
	 * @return void
	 */
	private function announce( $name, DocumentKey $key, array $before = array(), array $after = array() ) {
		if ( ! $this->events ) {
			return;
		}

		// Old value and new value, both named, because that is what the audit screen prints and
		// what "the split was changed" is useless without.
		$this->events->dispatch(
			DomainEvent::document(
				$name,
				$key->value(),
				array(
					'old' => $before,
					'new' => $after,
				)
			)
		);
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
			return array( 'experiment' => null, 'results' => array(), 'uplift' => null, 'daily' => array() );
		}

		$labels = $this->labels( $experiment );
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
			'daily'      => ExperimentResult::by_day(
				$this->store->daily( $key->value(), $experiment->started_at() ),
				array_keys( $labels ),
				$labels
			),
		);
	}

	/**
	 * The run as a CSV file, for whoever wants to do their own arithmetic.
	 *
	 * Returned as text through the normal REST route rather than streamed from a separate endpoint:
	 * a download URL of its own would need its own authentication, and a second way into the same
	 * data is a second place for it to be got at. The browser turns this into a file.
	 *
	 * Reading needs `homepage.view`, the same as looking at the numbers on screen — but it is
	 * audited, because a copy of the shop's conversion data leaving the site is worth a line in the
	 * log even when the person was entitled to it.
	 *
	 * @param DocumentKey $key Control document.
	 * @return array{filename:string,csv:string,rows:int}
	 * @throws SectionNotFound When there is no experiment to export.
	 */
	public function export( DocumentKey $key ) {
		$this->auth->require_permission( 'homepage.view' );

		$experiment = $this->require_experiment( $key );
		$labels     = $this->labels( $experiment );

		$days = ExperimentResult::by_day(
			$this->store->daily( $key->value(), $experiment->started_at() ),
			array_keys( $labels ),
			$labels
		);

		$today = (string) wp_date( 'Y-m-d', $this->clock->now() );

		$this->announce(
			DomainEvent::EXPERIMENT_EXPORTED,
			$key,
			array(),
			array(
				'variant' => $experiment->variant()->value(),
				'days'    => count( $days ),
			)
		);

		return array(
			'filename' => ExperimentCsv::filename( $key->value(), $today ),
			'csv'      => ExperimentCsv::build( $days ),
			'rows'     => count( $days ),
		);
	}

	/**
	 * Arm labels for a report: the control is named for what it is, the variant by its title.
	 *
	 * @param Experiment $experiment Experiment.
	 * @return array<string,string>
	 */
	private function labels( Experiment $experiment ) {
		return array(
			Experiment::CONTROL             => __( 'Current layout', 'yazan' ),
			$experiment->variant()->value() => $this->title_of( $experiment->variant() ),
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

		$this->announce(
			DomainEvent::EXPERIMENT_CONFIGURED,
			$key,
			$existing ? $existing->to_array() : array(),
			$experiment->to_array()
		);

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

		$this->announce( DomainEvent::EXPERIMENT_STARTED, $key, $experiment->to_array(), $started->to_array() );

		return $started->to_array();
	}

	/**
	 * @param DocumentKey $key Control.
	 * @return array
	 */
	public function stop( DocumentKey $key ) {
		$this->auth->require_permission( 'homepage.experiment' );

		$running = $this->require_experiment( $key );
		$stopped = $running->stop();
		$this->store->save( $stopped );

		$this->announce( DomainEvent::EXPERIMENT_STOPPED, $key, $running->to_array(), $stopped->to_array() );

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

		$existing = $this->store->get( $key );
		$totals   = $existing ? $this->store->totals( $key->value(), $existing->started_at() ) : array();

		$this->store->remove( $key );
		$this->store->clear( $key->value() );

		// The numbers go with it, and they are not recoverable. The audit row carries the totals
		// that were destroyed, so "we deleted a test that had 4,000 views" is answerable later.
		$this->announce(
			DomainEvent::EXPERIMENT_REMOVED,
			$key,
			array(
				'experiment' => $existing ? $existing->to_array() : array(),
				'totals'     => $totals,
			),
			array()
		);

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

		// Counted BEFORE the copy: after it, "old" and "new" would both be the new value, which is
		// the classic way an audit trail ends up recording nothing.
		$replaced = count( (array) $control->live_sections() );

		$sections = array();

		foreach ( (array) $variant->live_sections() as $row ) {
			$sections[] = Section::from_array( (array) $row );
		}

		$control->replace_sections( SectionCollection::of( $sections ), 'ab_promote', false );

		$this->documents->save( $control );

		// Published through the normal path: same revision, same audit row, same cache purge.
		$result = $publish->handle( $key, 'Promoted the A/B variant', $control->version()->value() );

		$this->store->save( $experiment->stop() );

		// The publish is already audited by PublishHandler; this row records the DECISION — which
		// challenger won and against what — which the publish row alone does not say.
		$this->announce(
			DomainEvent::EXPERIMENT_PROMOTED,
			$key,
			array( 'sections' => $replaced ),
			array(
				'variant'  => $experiment->variant()->value(),
				'sections' => count( $sections ),
			)
		);

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
