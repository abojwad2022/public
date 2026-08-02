<?php
/**
 * Revision, rollback and package tests.
 *
 * The safety net for a live store: a restore must be reviewable and itself reversible, and an
 * import must never half-apply.
 */
require __DIR__ . '/wp-stubs.php';
require YAZAN_CORE_DIR . 'includes/homepage/autoload.php';

use Yazan\Homepage\Application\Service\DocumentValidator;
use Yazan\Homepage\Application\Service\FieldSanitizer;
use Yazan\Homepage\Application\Service\ImportExportService;
use Yazan\Homepage\Application\Service\PermissionFilter;
use Yazan\Homepage\Application\Service\SectionFactory;
use Yazan\Homepage\Application\Handler\CreateSectionHandler;
use Yazan\Homepage\Application\Handler\ImportHandler;
use Yazan\Homepage\Application\Handler\PublishHandler;
use Yazan\Homepage\Application\Handler\RollbackHandler;
use Yazan\Homepage\Application\Handler\UpdateSectionHandler;
use Yazan\Homepage\Domain\Component\ComponentRegistry;
use Yazan\Homepage\Domain\Document\DocumentKey;
use Yazan\Homepage\Domain\Document\HomepageDocument;
use Yazan\Homepage\Domain\Event\DomainEvent;
use Yazan\Homepage\Domain\Exception\Forbidden;
use Yazan\Homepage\Domain\Exception\ValidationFailed;
use Yazan\Homepage\Domain\Port\AuthorizationPort;
use Yazan\Homepage\Domain\Port\EventDispatcherPort;
use Yazan\Homepage\Domain\Port\HomepageRepositoryPort;
use Yazan\Homepage\Domain\Port\RevisionRepositoryPort;
use Yazan\Homepage\Domain\Revision\RevisionDiff;
use Yazan\Homepage\Infrastructure\Adapter\WpClockAdapter;
use Yazan\Homepage\Infrastructure\Adapter\WpMediaAdapter;
use Yazan\Homepage\Presentation\Components\HeroComponent;
use Yazan\Homepage\Presentation\Components\StoryComponent;

$pass = 0; $fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok   $label\n"; }
	else { $fail++; echo "  FAIL $label\n"; }
}
function throws( $class, callable $fn ) {
	try { $fn(); return false; } catch ( \Throwable $e ) { return $e instanceof $class; }
}

final class Docs implements HomepageRepositoryPort {
	public $stored = array();
	public function get( DocumentKey $k ) {
		return isset( $this->stored[ $k->value() ] )
			? HomepageDocument::from_array( $this->stored[ $k->value() ] )
			: HomepageDocument::create( $k );
	}
	public function save( HomepageDocument $d ) { $d->assign_id( 1 ); $this->stored[ $d->key()->value() ] = $d->to_array(); }
	public function live_payload( DocumentKey $k ) { return $this->stored[ $k->value() ]['live_sections'] ?? array(); }
	public function listing() {
		$out = array();
		foreach ( $this->stored as $key => $row ) {
			$out[] = array( 'doc_key' => $key, 'title' => $row['title'], 'status' => $row['status'], 'bound_page_id' => $row['bound_page_id'] ?? 0, 'updated_at' => '' );
		}
		return $out;
	}
	public function key_for_page( $page_id ) {
		foreach ( $this->stored as $key => $row ) {
			if ( (int) ( $row['bound_page_id'] ?? 0 ) === (int) $page_id ) { return $key; }
		}
		return null;
	}
	public function delete( DocumentKey $key ) { unset( $this->stored[ $key->value() ] ); return true; }
	public function due_for_publish( $now ) { return array(); }
}

final class Revs implements RevisionRepositoryPort {
	public $rows = array();
	public function append( HomepageDocument $d, $author, $note = '', $is_publish = false ) {
		$this->rows[] = array(
			'id'          => count( $this->rows ) + 1,
			'doc_key'     => $d->key()->value(),
			'revision_no' => count( $this->rows ) + 1,
			'note'        => $note,
			'author_id'   => $author,
			'is_publish'  => $is_publish ? 1 : 0,
			'sections'    => $d->sections()->to_array(),
		);
		return count( $this->rows );
	}
	public function listing( $k, $limit = 30, $offset = 0 ) { return array_reverse( $this->rows ); }
	public function find( $id ) { return $this->rows[ $id - 1 ] ?? null; }
	public function previous_publish( $k, $before ) {
		$found = null;
		foreach ( $this->rows as $row ) {
			if ( empty( $row['is_publish'] ) ) { continue; }
			$id = $row['id'] ?? 0;
			if ( $before > 0 && $id >= $before ) { continue; }
			$found = $row;
		}
		return $found;
	}
	public function prune( $k, $keep ) { return 0; }
}

final class Auth implements AuthorizationPort {
	private $g;
	public function __construct( array $g ) { $this->g = $g; }
	public function can( $p ) { return in_array( $p, $this->g, true ); }
	public function can_any( array $ps ) { foreach ( $ps as $p ) { if ( $this->can( $p ) ) return true; } return false; }
	public function require_permission( $p ) { if ( ! $this->can( $p ) ) throw new Forbidden( $p ); }
	public function require_any( array $ps ) { if ( ! $this->can_any( $ps ) ) throw new Forbidden( 'any' ); }
	public function actor_id() { return 9; }
	public function actor_roles() { return array( 'tester' ); }
}

final class Events implements EventDispatcherPort {
	public $seen = array();
	public function dispatch( DomainEvent $e ) { $this->seen[] = $e->name(); }
	public function dispatch_all( array $es ) { foreach ( $es as $e ) { $this->dispatch( $e ); } }
}

$registry = new ComponentRegistry();
$registry->register( HeroComponent::definition() );
$registry->register( StoryComponent::definition() );

$sanitizer = new FieldSanitizer( new WpMediaAdapter() );
$factory   = new SectionFactory( $registry, $sanitizer );
$validator = new DocumentValidator( $registry );
$porting   = new ImportExportService( $registry, new WpMediaAdapter() );

$FULL = array(
	'homepage.view', 'homepage.edit', 'homepage.publish', 'homepage.rollback',
	'homepage.import', 'homepage.export', 'homepage.sections.create',
	'homepage.sections.edit', 'homepage.sections.delete', 'homepage.sections.sort',
	'homepage.design.edit',
);

$docs   = new Docs();
$revs   = new Revs();
$auth   = new Auth( $FULL );
$events = new Events();
$filter = new PermissionFilter( $auth );
$deps   = array( $docs, $auth, $registry, $validator, $events );
$key    = DocumentKey::default_key();

$create   = new CreateSectionHandler( $deps[0], $deps[1], $deps[2], $deps[3], $deps[4], $factory, $filter );
$update   = new UpdateSectionHandler( $deps[0], $deps[1], $deps[2], $deps[3], $deps[4], $sanitizer, $filter );
$publish  = new PublishHandler( $deps[0], $deps[1], $deps[2], $deps[3], $deps[4], $revs, new WpClockAdapter() );
$rollback = new RollbackHandler( $deps[0], $deps[1], $deps[2], $deps[3], $deps[4], $revs );
$import   = new ImportHandler( $deps[0], $deps[1], $deps[2], $deps[3], $deps[4], $porting, $revs, $factory );

echo "\n[1] Publish writes a revision; rollback restores into the DRAFT\n";
$r  = $create->handle( $key, 'story', array( 'heading' => 'First version' ) );
$id = $r['section']['id'];
$publish->handle( $key, 'v1' );
check( 'revision recorded on publish', 1 === count( $revs->rows ) );

$update->handle( $key, $id, array( 'content' => array( 'heading' => 'Second version' ) ) );
$publish->handle( $key, 'v2' );

$r = $rollback->handle( $key, 1 );
check( 'draft restored', 'First version' === $r['sections'][0]['content']['heading'] );
check( 'live is untouched until publish', true === $r['has_unpublished_changes'] );
check( 'the pre-rollback draft was snapshotted', 'before_rollback' === $revs->rows[ count( $revs->rows ) - 1 ]['note'] );

echo "\n[2] Rollback needs its own permission\n";
$weak = new RollbackHandler( $docs, new Auth( array( 'homepage.view', 'homepage.edit' ) ), $registry, $validator, $events, $revs );
check( 'refused without homepage.rollback', throws( Forbidden::class, static fn() => $weak->handle( $key, 1 ) ) );
check( 'a missing revision is a 404', throws( \Yazan\Homepage\Domain\Exception\SectionNotFound::class, static fn() => $rollback->handle( $key, 999 ) ) );

echo "\n[3] The diff reads changes, not churn\n";
$before = $revs->rows[0]['sections'];
$after  = $revs->rows[1]['sections'];
$diff   = RevisionDiff::between( $before, $after );
check( 'one section changed', 1 === count( $diff['changed'] ) );
check( 'the changed field is named', in_array( 'heading', $diff['changed'][0]['fields'], true ) );
check( 'nothing reported as added or removed', 0 === count( $diff['added'] ) && 0 === count( $diff['removed'] ) );

$moved = RevisionDiff::between(
	array( array( 'id' => 'a', 'type' => 'hero', 'content' => array() ), array( 'id' => 'b', 'type' => 'story', 'content' => array() ) ),
	array( array( 'id' => 'b', 'type' => 'story', 'content' => array() ), array( 'id' => 'a', 'type' => 'hero', 'content' => array() ) )
);
check( 'a reorder is MOVED, not delete+add', 2 === count( $moved['moved'] ) && 0 === count( $moved['added'] ) );

echo "\n[4] Export → import round trip\n";
$document = $docs->get( $key );
$package  = $porting->export( $document );
check( 'format marker present', ImportExportService::FORMAT === $package['format'] );
check( 'sections travel',       count( $package['document']['sections'] ) > 0 );
check( 'no user data in it',    ! isset( $package['document']['sections'][0]['author'] ) );

$fresh_docs = new Docs();
$fresh      = new ImportHandler( $fresh_docs, $auth, $registry, $validator, $events, $porting, new Revs(), $factory );
$result     = $fresh->handle( $key, $package );
check( 'imported into an empty site', count( $result['sections'] ) === count( $package['document']['sections'] ) );
check( 'content survived',            'First version' === $result['sections'][0]['content']['heading'] );
check( 'live still empty after import', false === $fresh_docs->get( $key )->has_live_content() );

echo "\n[5] A package that cannot be applied is refused WHOLE\n";
$bad = $package;
$bad['document']['sections'][] = array( 'id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'type' => 'not-installed', 'content' => array() );
check( 'unknown component refuses the package', throws( ValidationFailed::class, static fn() => $porting->inspect( $bad ) ) );

$alien = $package;
$alien['format'] = 'something-else';
check( 'a foreign file is refused', throws( ValidationFailed::class, static fn() => $porting->inspect( $alien ) ) );

$future = $package;
$future['format_version'] = 99;
check( 'a newer format is refused', throws( ValidationFailed::class, static fn() => $porting->inspect( $future ) ) );

echo "\n[6] Import is gated on every permission it actually uses\n";
$import_only = new ImportHandler( $docs, new Auth( array( 'homepage.import' ) ), $registry, $validator, $events, $porting, $revs, $factory );
check( 'import alone is not enough', throws( Forbidden::class, static fn() => $import_only->handle( $key, $package ) ) );
check( 'but a dry run is allowed',   is_array( $import_only->dry_run( $package ) ) );

echo "\n[7] Media references that do not exist here are cleared, not left dangling\n";
$with_media = $package;
$with_media['document']['sections'][0]['content']['image'] = 128; // an SVG in the stubs — refused
$report = $porting->inspect( $with_media );
check( 'the bad reference is reported', in_array( 128, $report['media_dropped'], true ) );
$cleaned = $porting->sections_from( $with_media );
check( 'and cleared on import',         0 === $cleaned[0]['content']['image'] );

echo "\n[8] Undo the publish — the LIVE page moves, the draft does not\n";
use Yazan\Homepage\Application\Handler\RevertPublishHandler;

$d2 = new Docs();
$v2 = new Revs();
$a2 = new Auth( $FULL );
$e2 = new Events();
$f2 = new PermissionFilter( $a2 );
$dd = array( $d2, $a2, $registry, $validator, $e2 );

$c2 = new CreateSectionHandler( $dd[0], $dd[1], $dd[2], $dd[3], $dd[4], $factory, $f2 );
$u2 = new UpdateSectionHandler( $dd[0], $dd[1], $dd[2], $dd[3], $dd[4], $sanitizer, $f2 );
$p2 = new PublishHandler( $dd[0], $dd[1], $dd[2], $dd[3], $dd[4], $v2, new WpClockAdapter() );
$rv = new RevertPublishHandler( $dd[0], $dd[1], $dd[2], $dd[3], $dd[4], $v2 );

$r  = $c2->handle( $key, 'story', array( 'heading' => 'Good version' ) );
$sid = $r['section']['id'];
$p2->handle( $key, 'ok' );

check( 'nothing to undo after the FIRST publish', null === $rv->target( $key ) );
check( 'and it refuses rather than breaking', throws( ValidationFailed::class, static fn() => $rv->handle( $key ) ) );

$u2->handle( $key, $sid, array( 'content' => array( 'heading' => 'BROKEN version' ) ) );
$p2->handle( $key, 'oops' );
check( 'the mistake is live', 'BROKEN version' === $d2->live_payload( $key )[0]['content']['heading'] );

// Meanwhile the editor has moved on to something new, unpublished.
$u2->handle( $key, $sid, array( 'content' => array( 'heading' => 'Work in progress' ) ) );

$out = $rv->handle( $key );
check( 'live is back to the good version', 'Good version' === $d2->live_payload( $key )[0]['content']['heading'] );
check( 'the DRAFT was not touched',        'Work in progress' === $d2->get( $key )->sections()->all()[0]->content()['heading'] );
check( 'reported which revision it used',  $out['reverted_to'] > 0 );
check( 'the cache-flushing event fired',   in_array( DomainEvent::PUBLISH_REVERTED, $e2->seen, true ) );

$no_publish = new RevertPublishHandler( $d2, new Auth( array( 'homepage.view', 'homepage.rollback' ) ), $registry, $validator, $e2, $v2 );
check( 'refused without homepage.publish', throws( Forbidden::class, static fn() => $no_publish->handle( $key ) ) );

echo "\n----------------------------------------\n";
echo "  passed: $pass   failed: $fail\n";
exit( $fail ? 1 : 0 );
