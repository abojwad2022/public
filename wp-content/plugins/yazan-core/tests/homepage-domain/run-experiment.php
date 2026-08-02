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

$pass = 0; $fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok   $label\n"; }
	else { $fail++; echo "  FAIL $label\n"; }
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

echo "\n----------------------------------------\n";
echo "  passed: $pass   failed: $fail\n";
exit( $fail ? 1 : 0 );
