<?php
/**
 * A/B test rules.
 *
 * Assignment is pure — it takes a roll rather than calling a random function — so the split can be
 * proved here instead of hoped for in production. Everything a wrong answer would cost is in this
 * file: a visitor changing arm between visits, a stopped test still running, a rate computed from
 * three views and reported as a result.
 */
require __DIR__ . '/wp-stubs.php';
require YAZAN_CORE_DIR . 'includes/homepage/autoload.php';

use Yazan\Homepage\Domain\Document\DocumentKey;
use Yazan\Homepage\Domain\Experiment\Experiment;
use Yazan\Homepage\Domain\Experiment\ExperimentResult;
use Yazan\Homepage\Application\Handler\ExperimentHandler;
use Yazan\Homepage\Application\Service\ExperimentCsv;
use Yazan\Homepage\Domain\Document\HomepageDocument;
use Yazan\Homepage\Domain\Event\DomainEvent;
use Yazan\Homepage\Domain\Exception\Forbidden;
use Yazan\Homepage\Domain\Port\AuthorizationPort;
use Yazan\Homepage\Domain\Port\ClockPort;
use Yazan\Homepage\Domain\Port\EventDispatcherPort;
use Yazan\Homepage\Domain\Port\ExperimentRepositoryPort;
use Yazan\Homepage\Domain\Port\HomepageRepositoryPort;

$pass = 0; $fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok   $label\n"; }
	else { $fail++; echo "  FAIL $label\n"; }
}
function throws_forbidden( callable $fn ) {
	try { $fn(); return false; } catch ( \Throwable $e ) { return $e instanceof Forbidden; }
}

$control = DocumentKey::from( 'default' );
$variant = DocumentKey::from( 'eid' );

echo "\n[1] A test needs two different layouts\n";
$refused = false;
try {
	new Experiment( $control, $control, 50, true );
} catch ( \Yazan\Homepage\Domain\Exception\ValidationFailed $e ) {
	$refused = true;
}
check( 'a layout cannot be tested against itself', $refused );

echo "\n[2] The split is the split\n";
$running = ( new Experiment( $control, $variant, 30 ) )->start( 1000 );

$to_variant = 0;
for ( $roll = 0; $roll < 100; $roll++ ) {
	if ( 'eid' === $running->assign( $roll ) ) {
		$to_variant++;
	}
}
check( '30 means 30 of every 100 rolls', 30 === $to_variant );

$half = ( new Experiment( $control, $variant, 50 ) )->start( 1000 );
$fifty = 0;
for ( $roll = 0; $roll < 100; $roll++ ) {
	if ( 'eid' === $half->assign( $roll ) ) {
		$fifty++;
	}
}
check( '50 means half',              50 === $fifty );
check( 'roll 0 goes to the variant', 'eid' === $half->assign( 0 ) );
check( 'roll 49 goes to the variant','eid' === $half->assign( 49 ) );
check( 'roll 50 stays on control',   Experiment::CONTROL === $half->assign( 50 ) );

echo "\n[3] The edges mean what they say\n";
$none = ( new Experiment( $control, $variant, 0 ) )->start( 1000 );
$all  = ( new Experiment( $control, $variant, 100 ) )->start( 1000 );
check( '0% never sends anyone to the variant', Experiment::CONTROL === $none->assign( 0 ) && Experiment::CONTROL === $none->assign( 99 ) );
check( '100% sends everyone',                  'eid' === $all->assign( 0 ) && 'eid' === $all->assign( 99 ) );

echo "\n[4] A stopped test is stopped for everyone, at once\n";
$stopped = $all->stop();
check( 'a stopped test answers control even at 100%', Experiment::CONTROL === $stopped->assign( 0 ) );
check( 'and knows it is not running',                 ! $stopped->is_running() );

echo "\n[5] A roll outside the range is refused, not wrapped\n";
check( 'negative roll falls back to control', Experiment::CONTROL === $half->assign( -1 ) );
check( 'roll of 100 falls back to control',   Experiment::CONTROL === $half->assign( 100 ) );

echo "\n[6] A returning visitor keeps their arm\n";
check( 'control is recognised',            $half->recognises( Experiment::CONTROL ) );
check( 'this variant is recognised',       $half->recognises( 'eid' ) );
check( 'another layout is NOT recognised', ! $half->recognises( 'ramadan' ) );
check( 'nonsense is not recognised',       ! $half->recognises( 'x' ) );
check( 'control renders the control',      'default' === $half->document_for( Experiment::CONTROL )->value() );
check( 'the variant arm renders it',       'eid' === $half->document_for( 'eid' )->value() );

echo "\n[7] Starting records the moment, restarting does not move it\n";
$fresh = new Experiment( $control, $variant, 50 );
check( 'not started yet', null === $fresh->started_at() );
$first = $fresh->start( 5000 );
check( 'start stamps the time', 5000 === $first->started_at() );
$again = $first->stop()->start( 9000 );
check( 'restarting keeps the original start', 5000 === $again->started_at() );

echo "\n[8] Storage round-trip\n";
$stored = Experiment::from_array( $first->to_array() );
check( 'survives a round-trip', $stored && 'eid' === $stored->variant()->value() && 50 === $stored->split() && $stored->is_running() );
check( 'a row naming no variant is not an experiment', null === Experiment::from_array( array( 'control' => 'default' ) ) );
check( 'a broken row does not throw',                  null === Experiment::from_array( array( 'control' => 'default', 'variant' => 'NOT A KEY!!' ) ) );

echo "\n[9] The split is clamped, never trusted\n";
check( 'over 100 clamps',  100 === ( new Experiment( $control, $variant, 250 ) )->split() );
check( 'under 0 clamps',   0 === ( new Experiment( $control, $variant, -8 ) )->split() );
check( 'with_split clamps',100 === $half->with_split( 900 )->split() );

echo "\n[10] Results arithmetic\n";
$summary = ExperimentResult::summarise(
	array(
		Experiment::CONTROL => array( 'views' => 1000, 'orders' => 20, 'revenue' => 4000.0 ),
		'eid'               => array( 'views' => 1000, 'orders' => 30, 'revenue' => 7500.0 ),
	),
	array( Experiment::CONTROL => 'Current layout', 'eid' => 'Eid' )
);

$by_arm = array();
foreach ( $summary as $row ) { $by_arm[ $row['arm'] ] = $row; }

check( 'control conversion',        2.0 === $by_arm[ Experiment::CONTROL ]['conversion'] );
check( 'variant conversion',        3.0 === $by_arm['eid']['conversion'] );
check( 'revenue per view',          7.5 === $by_arm['eid']['revenue_per_view'] );
check( 'the label travels',         'Eid' === $by_arm['eid']['label'] );
check( 'uplift is 50 percent',      50.0 === ExperimentResult::uplift( $summary ) );

echo "\n[11] No numbers invented from nothing\n";
$empty = ExperimentResult::summarise( array( Experiment::CONTROL => array(), 'eid' => array() ) );
check( 'zero views is a 0.0 rate, not a division by zero', 0.0 === $empty[0]['conversion'] );
check( 'and is flagged as not enough data',                ! $empty[0]['enough_data'] );

$thin = ExperimentResult::summarise(
	array(
		Experiment::CONTROL => array( 'views' => 10, 'orders' => 1 ),
		'eid'               => array( 'views' => 10, 'orders' => 3 ),
	)
);
check( 'a tiny sample reports no uplift', null === ExperimentResult::uplift( $thin ) );

$never = ExperimentResult::summarise(
	array(
		Experiment::CONTROL => array( 'views' => 5000, 'orders' => 0 ),
		'eid'               => array( 'views' => 5000, 'orders' => 40 ),
	)
);
check( 'a control that never converted gives no uplift', null === ExperimentResult::uplift( $never ) );
check( 'one arm alone gives no uplift',                  null === ExperimentResult::uplift( array( $never[0] ) ) );

echo "\n[12] The arm survives into the checkout — the bug a live order caught\n";

/*
 * This is the regression test for the one defect no unit test found: the order stamp used to read
 * the arm from per-request state that only exists while the FRONT PAGE renders. Checkout is a
 * different request, so every real order was stamped empty and the report showed zero conversions
 * for ever, while the visitor counters climbed happily.
 *
 * The cookie is what crosses the gap between the two requests, so that is what this asserts.
 */
$store = new \Yazan\Homepage\Infrastructure\Persistence\ExperimentStore();
$live  = ( new Experiment( $control, $variant, 50 ) )->start( 1000 );
$store->save( $live );

$_COOKIE = array();
check( 'no cookie, no stamp', '' === \Yazan\Homepage\Presentation\Render\ExperimentRunner::stamp() );

$_COOKIE['yz_ab_default'] = 'eid';
check( 'the variant arm is stamped from the cookie', 'default|eid' === \Yazan\Homepage\Presentation\Render\ExperimentRunner::stamp() );

$_COOKIE['yz_ab_default'] = Experiment::CONTROL;
check( 'the control arm is stamped too',            'default|control' === \Yazan\Homepage\Presentation\Render\ExperimentRunner::stamp() );

$_COOKIE['yz_ab_default'] = 'ramadan';
check( 'a cookie naming a layout this test does not use is refused', '' === \Yazan\Homepage\Presentation\Render\ExperimentRunner::stamp() );

$_COOKIE['yz_ab_default'] = 'eid';
$store->save( $live->stop() );
check( 'a stopped test stamps nothing', '' === \Yazan\Homepage\Presentation\Render\ExperimentRunner::stamp() );

$store->remove( $control );
check( 'no experiment, no stamp', '' === \Yazan\Homepage\Presentation\Render\ExperimentRunner::stamp() );
$_COOKIE = array();

echo "\n[13] A stamp only counts when it names both sides\n";
$counted = array();
// record_order() splits the stamp; anything malformed must be dropped rather than half-recorded.
foreach ( array( '', 'default', '|eid', 'default|', 'default|eid' ) as $stamp ) {
	$parts = explode( '|', $stamp, 2 );
	$counted[ $stamp ] = ( 2 === count( $parts ) && '' !== $parts[0] && '' !== $parts[1] );
}
check( 'empty stamp dropped',        false === $counted[''] );
check( 'no arm dropped',             false === $counted['default'] );
check( 'no document dropped',        false === $counted['|eid'] );
check( 'trailing separator dropped', false === $counted['default|'] );
check( 'a complete stamp counts',    true === $counted['default|eid'] );

echo "\n[14] The run, day by day\n";

/*
 * The table has always stored per-day rows; nothing read them that way until now. What matters
 * here is that a day with one arm missing still prints both — a gap has to look like a gap and
 * not like a shorter table.
 */
$series = ExperimentResult::by_day(
	array(
		array( 'date' => '2026-07-03', 'arm' => 'eid',     'views' => 40, 'orders' => 2, 'revenue' => 500.0 ),
		array( 'date' => '2026-07-01', 'arm' => 'control', 'views' => 10, 'orders' => 1, 'revenue' => 120.5 ),
		array( 'date' => '2026-07-01', 'arm' => 'eid',     'views' => 12, 'orders' => 0, 'revenue' => 0.0 ),
	),
	array( 'control', 'eid' ),
	array( 'control' => 'Current layout', 'eid' => 'Eid' )
);

check( 'one entry per day',            2 === count( $series ) );
check( 'oldest first, whatever order they arrived in', '2026-07-01' === $series[0]['date'] && '2026-07-03' === $series[1]['date'] );
check( 'both arms on a day that had both',  10 === $series[0]['arms']['control']['views'] && 12 === $series[0]['arms']['eid']['views'] );
check( 'a missing arm shows as zero, not as an absent row', isset( $series[1]['arms']['control'] ) && 0 === $series[1]['arms']['control']['views'] );
check( 'the label travels into the day rows', 'Eid' === $series[1]['arms']['eid']['label'] );
check( 'money is rounded, not truncated',    120.5 === $series[0]['arms']['control']['revenue'] );

$twice = ExperimentResult::by_day(
	array(
		array( 'date' => '2026-07-01', 'arm' => 'eid', 'views' => 5, 'orders' => 1, 'revenue' => 10.0 ),
		array( 'date' => '2026-07-01', 'arm' => 'eid', 'views' => 7, 'orders' => 2, 'revenue' => 5.0 ),
	),
	array( 'eid' )
);
check( 'a repeated day/arm row adds rather than overwrites', 12 === $twice[0]['arms']['eid']['views'] && 15.0 === $twice[0]['arms']['eid']['revenue'] );

$junk = ExperimentResult::by_day(
	array( array( 'date' => '', 'arm' => 'eid' ), array( 'arm' => '' ), array() ),
	array( 'eid' )
);
check( 'rows with no day or no arm are dropped, not counted as one', array() === $junk );
check( 'nothing in, nothing out',                                    array() === ExperimentResult::by_day( array(), array( 'eid' ) ) );

echo "\n[15] The exported file opens correctly somewhere else\n";

$csv = ExperimentCsv::build( $series );
$rows = explode( "\r\n", trim( $csv ) );

check( 'a UTF-8 BOM, so Excel does not mangle an Arabic title', 0 === strpos( $csv, "\xEF\xBB\xBF" ) );
check( 'CRLF line endings',                                    false !== strpos( $csv, "\r\n" ) );
check( 'a header row',      'date,arm,layout,views,orders,revenue' === substr( $rows[0], 3 ) );
check( 'one line per day per arm', 5 === count( $rows ) ); // header + 2 days x 2 arms
check( 'money always has two decimals', false !== strpos( $csv, '120.50' ) );

$risky = ExperimentCsv::build(
	array(
		array(
			'date' => '2026-07-01',
			'arms' => array(
				'eid' => array( 'label' => '=cmd|/c calc', 'views' => 1, 'orders' => 0, 'revenue' => 0 ),
				'x'   => array( 'label' => 'Summer, "big" sale', 'views' => 1, 'orders' => 0, 'revenue' => 0 ),
			),
		),
	)
);

check( 'a formula in a layout title is neutralised', false !== strpos( $risky, "'=cmd|/c calc" ) );
check( 'a comma is quoted',                          false !== strpos( $risky, '"Summer, ""big"" sale"' ) );
check( 'and its quotes are doubled',                 false === strpos( $risky, 'Summer, "big" sale,' ) );

check( 'the filename says what it is',        'ab-default-2026-07-01.csv' === ExperimentCsv::filename( 'default', '2026-07-01' ) );
check( 'a hostile key cannot escape the name', 'ab-etcpasswd-2026-07-01.csv' === ExperimentCsv::filename( '../etc/passwd', '2026-07-01' ) );
check( 'an empty key still produces a file',   'ab-homepage-2026-07-01.csv' === ExperimentCsv::filename( '', '2026-07-01' ) );

echo "\n[16] Every A/B decision leaves a line in the audit log\n";

/*
 * This is the gap the port opened up. ExperimentHandler used to name the concrete store, which
 * needs $wpdb, so nothing here could be built without a database — and because it could not be
 * built, nobody noticed that starting, stopping and DELETING a test wrote nothing to the audit
 * trail at all. Deleting one destroys its numbers permanently.
 */

final class MemoryExperiments implements ExperimentRepositoryPort {
	public $rows  = array();
	public $stats = array();
	public $cleared = 0;
	public function get( DocumentKey $key ) { return $this->rows[ $key->value() ] ?? null; }
	public function all() { return $this->rows; }
	public function save( Experiment $e ) { $this->rows[ $e->control()->value() ] = $e; }
	public function remove( DocumentKey $key ) { unset( $this->rows[ $key->value() ] ); }
	public function record_view( $c, $a, $d ) { $this->bump( $c, $a, $d, 'views', 1, 0 ); }
	public function record_order( $c, $a, $d, $r ) { $this->bump( $c, $a, $d, 'orders', 1, (float) $r ); }
	private function bump( $c, $a, $d, $col, $by, $rev ) {
		$k = "$c|$a|$d";
		$row = $this->stats[ $k ] ?? array( 'date' => $d, 'arm' => $a, 'views' => 0, 'orders' => 0, 'revenue' => 0.0, 'doc' => $c );
		$row[ $col ] += $by;
		$row['revenue'] += $rev;
		$this->stats[ $k ] = $row;
	}
	public function totals( $control, $since = null ) {
		$out = array();
		foreach ( $this->daily( $control, $since ) as $row ) {
			$prior = $out[ $row['arm'] ] ?? array( 'views' => 0, 'orders' => 0, 'revenue' => 0.0 );
			$out[ $row['arm'] ] = array(
				'views'   => $prior['views'] + $row['views'],
				'orders'  => $prior['orders'] + $row['orders'],
				'revenue' => $prior['revenue'] + $row['revenue'],
			);
		}
		return $out;
	}
	public function daily( $control, $since = null ) {
		$out = array();
		foreach ( $this->stats as $row ) {
			if ( $row['doc'] !== $control ) { continue; }
			$out[] = array( 'date' => $row['date'], 'arm' => $row['arm'], 'views' => $row['views'], 'orders' => $row['orders'], 'revenue' => $row['revenue'] );
		}
		usort( $out, static function ( $a, $b ) { return array( $a['date'], $a['arm'] ) <=> array( $b['date'], $b['arm'] ); } );
		return $out;
	}
	public function clear( $control ) {
		$this->cleared++;
		foreach ( array_keys( $this->stats ) as $k ) {
			if ( 0 === strpos( $k, $control . '|' ) ) { unset( $this->stats[ $k ] ); }
		}
	}
}

final class StubDocuments implements HomepageRepositoryPort {
	public $rows = array();
	public function get( DocumentKey $key ) {
		return isset( $this->rows[ $key->value() ] ) ? HomepageDocument::from_array( $this->rows[ $key->value() ] ) : HomepageDocument::create( $key );
	}
	public function save( HomepageDocument $d ) { $d->assign_id( 1 ); $this->rows[ $d->key()->value() ] = $d->to_array(); }
	public function live_payload( DocumentKey $key ) { return $this->rows[ $key->value() ]['live_sections'] ?? array(); }
	public function listing() { return array(); }
	public function key_for_page( $page_id ) { return null; }
	public function delete( DocumentKey $key ) { unset( $this->rows[ $key->value() ] ); return true; }
	public function due_for_publish( $now ) { return array(); }
}

final class FixedClock implements ClockPort {
	public function now() { return 1767225600; } // 2026-01-01, so the filename is predictable.
	public function timezone() { return 'UTC'; }
}

final class SeenEvents implements EventDispatcherPort {
	public $seen = array();
	public function dispatch( DomainEvent $e ) { $this->seen[] = array( 'name' => $e->name(), 'changes' => $e->changes() ); }
	public function dispatch_all( array $es ) { foreach ( $es as $e ) { $this->dispatch( $e ); } }
	public function names() { return array_column( $this->seen, 'name' ); }
	public function last() { return end( $this->seen ) ?: array(); }
}

final class GrantAll implements AuthorizationPort {
	private $granted;
	public function __construct( array $granted ) { $this->granted = $granted; }
	public function can( $p ) { return in_array( $p, $this->granted, true ); }
	public function can_any( array $ps ) { foreach ( $ps as $p ) { if ( $this->can( $p ) ) { return true; } } return false; }
	public function require_permission( $p ) { if ( ! $this->can( $p ) ) { throw new Forbidden( $p ); } }
	public function require_any( array $ps ) { if ( ! $this->can_any( $ps ) ) { throw new Forbidden( 'any' ); } }
	public function actor_id() { return 7; }
	public function actor_roles() { return array( 'homePage' ); }
}

$docs   = new StubDocuments();
$vault  = new MemoryExperiments();
$events = new SeenEvents();
$clock  = new FixedClock();

// A variant with something published, which is what save() insists on.
$eid = HomepageDocument::create( $variant );
$eid->assign_id( 9 );
$docs->rows['eid'] = array_merge(
	$eid->to_array(),
	array( 'id' => 9, 'title' => 'Eid layout', 'live_sections' => array( array( 'id' => wp_generate_uuid4(), 'type' => 'hero', 'content' => array(), 'enabled' => true ) ) )
);

$handler = new ExperimentHandler( $docs, $vault, new GrantAll( array( 'homepage.view', 'homepage.experiment', 'homepage.publish' ) ), $clock, $events );

$handler->save( $control, 'eid', 40 );
check( 'configuring a test is audited', in_array( DomainEvent::EXPERIMENT_CONFIGURED, $events->names(), true ) );
check( 'with the new split in the row', 40 === ( $events->last()['changes']['new']['split'] ?? 0 ) );
check( 'and nothing where there was nothing before', array() === ( $events->last()['changes']['old'] ?? null ) );

$handler->save( $control, 'eid', 60 );
check( 'changing the split records the OLD value too', 40 === ( $events->last()['changes']['old']['split'] ?? 0 ) );
check( 'and the new one',                              60 === ( $events->last()['changes']['new']['split'] ?? 0 ) );

$handler->start( $control );
check( 'starting is audited',                in_array( DomainEvent::EXPERIMENT_STARTED, $events->names(), true ) );
check( 'the row shows it was not running before', false === ( $events->last()['changes']['old']['running'] ?? true ) );
check( 'and is now',                              true === ( $events->last()['changes']['new']['running'] ?? false ) );

$vault->record_view( 'default', 'control', '2026-01-01' );
$vault->record_view( 'default', 'eid', '2026-01-01' );
$vault->record_order( 'default', 'eid', '2026-01-01', 250.0 );
$vault->record_view( 'default', 'eid', '2026-01-02' );

$report = $handler->read( $control );
check( 'the report carries the day-by-day series', 2 === count( $report['daily'] ) );
check( 'the first day has both arms',              2 === count( $report['daily'][0]['arms'] ) );
check( 'the second day carries only its own view',  1 === $report['daily'][1]['arms']['eid']['views'] );
check( 'and the order landed on the first day',     1 === $report['daily'][0]['arms']['eid']['orders'] );

echo "\n[17] Exporting is a read — and a logged one\n";
$file = $handler->export( $control );
check( 'a filename with the date on it', 'ab-default-2026-01-01.csv' === $file['filename'] );
check( 'the file has both days',          2 === $file['rows'] );
check( 'the money made it in',            false !== strpos( $file['csv'], '250.00' ) );
check( 'the export is audited',           in_array( DomainEvent::EXPERIMENT_EXPORTED, $events->names(), true ) );

$reader   = new ExperimentHandler( $docs, $vault, new GrantAll( array( 'homepage.view' ) ), $clock, $events );
$refused  = false;
try { $reader->export( $control ); } catch ( \Throwable $e ) { $refused = true; }
check( 'someone who may see the report may export it', ! $refused );

$outsider = new ExperimentHandler( $docs, $vault, new GrantAll( array() ), $clock, $events );
check( 'someone who may not, may not', throws_forbidden( static function () use ( $outsider, $control ) { $outsider->export( $control ); } ) );
check( 'and cannot start one either',  throws_forbidden( static function () use ( $outsider, $control ) { $outsider->start( $control ); } ) );

echo "\n[18] Deleting a test records what was destroyed\n";
$before_count = count( $events->names() );
$handler->remove( $control );
$row = $events->last();

check( 'removing is audited',                     DomainEvent::EXPERIMENT_REMOVED === $row['name'] );
check( 'the row keeps the numbers that are gone', ( $row['changes']['old']['totals']['eid']['views'] ?? 0 ) > 0 );
check( 'and which variant it was',                'eid' === ( $row['changes']['old']['experiment']['variant'] ?? '' ) );
check( 'exactly one event, not a cascade',        $before_count + 1 === count( $events->names() ) );
check( 'the numbers really are gone',             array() === $vault->totals( 'default' ) );
check( 'and so is the experiment',                null === $vault->get( $control ) );

echo "\n[19] The front page: who gets what, and who gets counted\n";

/*
 * Until now this path could not be tested at all — it reaches the composition root statically, and
 * there was no way to hand it an in-memory store. ServiceFactory::set() is that seam. What it buys
 * is a test of the ONE piece of this module that serves different HTML to different people.
 */
use Yazan\Homepage\Infrastructure\Bootstrap\ServiceFactory;
use Yazan\Homepage\Presentation\Render\ExperimentRunner;

$live_store = new MemoryExperiments();
ServiceFactory::set( 'experiments', $live_store );
ServiceFactory::set( 'auth', new GrantAll( array() ) ); // a visitor, not a member of staff.

$live_store->save( ( new Experiment( $control, $variant, 100 ) )->start( 1000 ) );
$_COOKIE = array();

$got = ExperimentRunner::resolve( $control );
check( 'a 100% split sends this visitor to the variant', 'eid' === $got->value() );
check( 'and the response is taken out of the cache',      $GLOBALS['__nocache'] > 0 );
check( 'nothing is counted before the page is planned',   array() === $live_store->totals( 'default' ) );

ExperimentRunner::commit( true );
check( 'the view is counted once the page rendered', 1 === ( $live_store->totals( 'default' )['eid']['views'] ?? 0 ) );

// An arm that renders nothing is not an audience: it shows the theme's own homepage, which is what
// the control shows, so counting it would report a test of a page against itself.
ExperimentRunner::resolve( $control );
ExperimentRunner::commit( false );
check( 'a variant that renders nothing is not counted', 1 === ( $live_store->totals( 'default' )['eid']['views'] ?? 0 ) );
check( 'and the request is disowned, so no order can be attributed to it', null === ExperimentRunner::current() );

// The control arm IS counted with nothing published — "the homepage as it is today" is a fair
// thing to test against.
$live_store->clear( 'default' );
$live_store->save( ( new Experiment( $control, $variant, 0 ) )->start( 1000 ) );
$_COOKIE = array();
ExperimentRunner::resolve( $control );
ExperimentRunner::commit( false );
check( 'the control counts even with nothing published', 1 === ( $live_store->totals( 'default' )['control']['views'] ?? 0 ) );

echo "\n[20] Staff are never in the sample\n";
$live_store->clear( 'default' );
$live_store->save( ( new Experiment( $control, $variant, 100 ) )->start( 1000 ) );
ServiceFactory::set( 'auth', new GrantAll( array( 'homepage.view' ) ) ); // someone who can open the builder.
$_COOKIE = array();

$staff = ExperimentRunner::resolve( $control );
ExperimentRunner::commit( true );

check( 'staff see the layout they asked for, not a variant', 'default' === $staff->value() );
check( 'and are not counted',                                array() === $live_store->totals( 'default' ) );
check( 'and carry no stamp into an order',                   '' === ExperimentRunner::stamp() );

echo "\n[21] A visitor keeps the arm they were given\n";
ServiceFactory::set( 'auth', new GrantAll( array() ) );
$live_store->clear( 'default' );
$live_store->save( ( new Experiment( $control, $variant, 50 ) )->start( 1000 ) );

$GLOBALS['__roll'] = 10; // below 50 → the variant.
$_COOKIE = array();
check( 'a low roll goes to the variant', 'eid' === ExperimentRunner::resolve( $control )->value() );
check( 'the arm is remembered for the next request', 'eid' === ( $_COOKIE['yz_ab_default'] ?? '' ) );

$GLOBALS['__roll'] = 90; // would now be the control — but they have already been assigned.
check( 'a returning visitor is not re-rolled', 'eid' === ExperimentRunner::resolve( $control )->value() );

$_COOKIE['yz_ab_default'] = 'ramadan'; // a layout this test does not use.
check( 'a stale cookie is re-rolled, not obeyed', 'default' === ExperimentRunner::resolve( $control )->value() );

ServiceFactory::reset();
$_COOKIE = array();

echo "\n----------------------------------------\n";
echo "  passed: $pass   failed: $fail\n";
exit( $fail ? 1 : 0 );
