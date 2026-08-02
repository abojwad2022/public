<?php
/**
 * Use-case tests.
 *
 * Runs every write handler against in-memory repositories — no WordPress, no database, no REST.
 * The permission matrix here is the most important test in the module: it is the difference
 * between "the UI hides the button" and "the server refuses the request".
 */
require __DIR__ . '/wp-stubs.php';
require YAZAN_CORE_DIR . 'includes/homepage/autoload.php';

use Yazan\Homepage\Application\Service\DocumentValidator;
use Yazan\Homepage\Application\Service\FieldSanitizer;
use Yazan\Homepage\Application\Service\PermissionFilter;
use Yazan\Homepage\Application\Service\SectionFactory;
use Yazan\Homepage\Application\Handler\CreateSectionHandler;
use Yazan\Homepage\Application\Handler\DeleteSectionHandler;
use Yazan\Homepage\Application\Handler\DuplicateSectionHandler;
use Yazan\Homepage\Application\Handler\PublishHandler;
use Yazan\Homepage\Application\Handler\ReorderSectionsHandler;
use Yazan\Homepage\Application\Handler\SaveDraftHandler;
use Yazan\Homepage\Application\Handler\ToggleSectionHandler;
use Yazan\Homepage\Application\Handler\UpdateSectionHandler;
use Yazan\Homepage\Application\Query\GetEditorDocument;
use Yazan\Homepage\Domain\Component\ComponentRegistry;
use Yazan\Homepage\Domain\Document\DocumentKey;
use Yazan\Homepage\Domain\Document\HomepageDocument;
use Yazan\Homepage\Domain\Event\DomainEvent;
use Yazan\Homepage\Domain\Exception\Forbidden;
use Yazan\Homepage\Domain\Exception\VersionConflict;
use Yazan\Homepage\Domain\Port\AuthorizationPort;
use Yazan\Homepage\Domain\Port\EventDispatcherPort;
use Yazan\Homepage\Domain\Port\HomepageRepositoryPort;
use Yazan\Homepage\Domain\Port\RevisionRepositoryPort;
use Yazan\Homepage\Infrastructure\Adapter\WpMediaAdapter;
use Yazan\Homepage\Presentation\Components\HeroComponent;
use Yazan\Homepage\Presentation\Components\CollectionsComponent;
use Yazan\Homepage\Presentation\Components\NewsletterComponent;
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

/* ------------------------------------------------------------------ test doubles */

final class MemoryDocuments implements HomepageRepositoryPort {
	public $stored = array();
	public function get( DocumentKey $key ) {
		if ( ! isset( $this->stored[ $key->value() ] ) ) {
			return HomepageDocument::create( $key );
		}
		return HomepageDocument::from_array( $this->stored[ $key->value() ] );
	}
	public function save( HomepageDocument $d ) {
		$d->assign_id( 1 );
		$this->stored[ $d->key()->value() ] = $d->to_array();
	}
	public function live_payload( DocumentKey $key ) {
		$row = $this->stored[ $key->value() ] ?? array();
		return $row['live_sections'] ?? array();
	}
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

final class MemoryRevisions implements RevisionRepositoryPort {
	public $rows = array();
	public function append( HomepageDocument $d, $author, $note = '', $is_publish = false ) {
		$this->rows[] = array(
			'id'          => count( $this->rows ) + 1,
			'doc_key'     => $d->key()->value(),
			'revision_no' => count( $this->rows ) + 1,
			'is_publish'  => $is_publish ? 1 : 0,
			'sections'    => $d->sections()->to_array(),
			'note'        => $note,
		);
		return count( $this->rows );
	}
	public function listing( $k, $limit = 30, $offset = 0 ) { return $this->rows; }
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

final class FakeAuth implements AuthorizationPort {
	private $granted;
	public function __construct( array $granted ) { $this->granted = $granted; }
	public function can( $p ) { return in_array( $p, $this->granted, true ); }
	public function can_any( array $ps ) { foreach ( $ps as $p ) { if ( $this->can( $p ) ) return true; } return false; }
	public function require_permission( $p ) { if ( ! $this->can( $p ) ) throw ( new Forbidden( $p ) )->with_context( array( 'permission' => $p ) ); }
	public function require_any( array $ps ) { if ( ! $this->can_any( $ps ) ) throw new Forbidden( 'any' ); }
	public function actor_id() { return 5; }
	public function actor_roles() { return array( 'tester' ); }
}

final class RecordingEvents implements EventDispatcherPort {
	public $seen = array();
	public function dispatch( DomainEvent $e ) { $this->seen[] = $e->name(); }
	public function dispatch_all( array $es ) { foreach ( $es as $e ) { $this->dispatch( $e ); } }
}

/* ------------------------------------------------------------------ wiring */

$registry = new ComponentRegistry();
foreach ( array( HeroComponent::class, StoryComponent::class, NewsletterComponent::class, CollectionsComponent::class ) as $c ) {
	$registry->register( $c::definition() );
}

$sanitizer = new FieldSanitizer( new WpMediaAdapter() );
$factory   = new SectionFactory( $registry, $sanitizer );
$validator = new DocumentValidator( $registry );

function build( $granted, $registry, $sanitizer, $factory, $validator, $docs = null ) {
	$docs   = $docs ?: new MemoryDocuments();
	$revs   = new MemoryRevisions();
	$auth   = new FakeAuth( $granted );
	$events = new RecordingEvents();
	$filter = new PermissionFilter( $auth );
	$deps   = array( $docs, $auth, $registry, $validator, $events );

	return array(
		'docs'      => $docs,
		'auth'      => $auth,
		'events'    => $events,
		'create'    => new CreateSectionHandler( $deps[0], $deps[1], $deps[2], $deps[3], $deps[4], $factory, $filter ),
		'update'    => new UpdateSectionHandler( $deps[0], $deps[1], $deps[2], $deps[3], $deps[4], $sanitizer, $filter ),
		'delete'    => new DeleteSectionHandler( ...$deps ),
		'duplicate' => new DuplicateSectionHandler( ...$deps ),
		'toggle'    => new ToggleSectionHandler( ...$deps ),
		'reorder'   => new ReorderSectionsHandler( ...$deps ),
		'save'      => new SaveDraftHandler( $deps[0], $deps[1], $deps[2], $deps[3], $deps[4], $sanitizer, $filter, $factory ),
		'publish'   => new PublishHandler( $deps[0], $deps[1], $deps[2], $deps[3], $deps[4], $revs, new \Yazan\Homepage\Infrastructure\Adapter\WpClockAdapter() ),
		'revert'    => new \Yazan\Homepage\Application\Handler\RevertPublishHandler( $deps[0], $deps[1], $deps[2], $deps[3], $deps[4], $revs ),
		'query'     => new GetEditorDocument( $deps[0], $registry, $auth, $filter, new \Yazan\Homepage\Application\Handler\RevertPublishHandler( $deps[0], $deps[1], $deps[2], $deps[3], $deps[4], $revs ) ),
	);
}

$FULL = array(
	'homepage.view', 'homepage.edit', 'homepage.publish',
	'homepage.sections.create', 'homepage.sections.edit', 'homepage.sections.delete',
	'homepage.sections.sort', 'homepage.sections.duplicate', 'homepage.design.edit',
);
$key = DocumentKey::default_key();

/* ------------------------------------------------------------------ tests */

echo "\n[1] Create → update → publish\n";
$s = build( $FULL, $registry, $sanitizer, $factory, $validator );
$r = $s['create']->handle( $key, 'hero', array( 'line_1' => 'Formed by the earth.' ) );
$hero_id = $r['section']['id'];
check( 'section created',        ! empty( $hero_id ) );
check( 'version returned',       $r['version'] > 1 );
check( 'nothing blocked',        array() === $r['blocked'] );

$r = $s['update']->handle( $key, $hero_id, array( 'content' => array( 'line_1' => 'Worn for a lifetime.' ) ), $r['version'] );
check( 'content updated',        'Worn for a lifetime.' === $r['section']['content']['line_1'] );
check( 'unpublished flagged',    true === $r['has_unpublished_changes'] );

$r = $s['publish']->handle( $key, 'first launch', $r['version'] );
check( 'published',              'published' === $r['status'] );
check( 'draft now matches live', false === $r['has_unpublished_changes'] );
check( 'revision recorded',      $r['revision'] > 0 );
check( 'publish event fired',    in_array( 'homepage.published', $s['events']->seen, true ) );

echo "\n[2] Optimistic locking\n";
check( 'stale version refused (409)', throws( VersionConflict::class, static function () use ( $s, $key, $hero_id ) {
	$s['update']->handle( $key, $hero_id, array( 'content' => array( 'line_1' => 'x' ) ), 1 );
} ) );

echo "\n[3] The permission matrix — the server refuses, not the UI\n";
$view_only = build( array( 'homepage.view' ), $registry, $sanitizer, $factory, $validator, $s['docs'] );
check( 'view-only cannot create',    throws( Forbidden::class, static fn() => $view_only['create']->handle( $key, 'story' ) ) );
check( 'view-only cannot edit',      throws( Forbidden::class, static fn() => $view_only['update']->handle( $key, $hero_id, array( 'content' => array() ) ) ) );
check( 'view-only cannot delete',    throws( Forbidden::class, static fn() => $view_only['delete']->handle( $key, $hero_id ) ) );
check( 'view-only cannot reorder',   throws( Forbidden::class, static fn() => $view_only['reorder']->handle( $key, array( $hero_id ) ) ) );
check( 'view-only cannot publish',   throws( Forbidden::class, static fn() => $view_only['publish']->handle( $key ) ) );
check( 'view-only cannot duplicate', throws( Forbidden::class, static fn() => $view_only['duplicate']->handle( $key, $hero_id ) ) );

echo "\n[4] Section-scoped grants — a Marketing role\n";
$marketing = build( array( 'homepage.view', 'homepage.section.hero.edit' ), $registry, $sanitizer, $factory, $validator, $s['docs'] );
$r = $marketing['update']->handle( $key, $hero_id, array( 'content' => array( 'line_1' => 'Marketing edit' ) ) );
check( 'may edit the hero it owns', 'Marketing edit' === $r['section']['content']['line_1'] );

$s2 = build( $FULL, $registry, $sanitizer, $factory, $validator, $s['docs'] );
$story = $s2['create']->handle( $key, 'story', array( 'heading' => 'Heritage' ) );
$story_id = $story['section']['id'];
check( 'may NOT edit another section', throws( Forbidden::class, static fn() => $marketing['update']->handle( $key, $story_id, array( 'content' => array( 'heading' => 'nope' ) ) ) ) );
check( 'may NOT publish',              throws( Forbidden::class, static fn() => $marketing['publish']->handle( $key ) ) );

echo "\n[5] Field-level permission inside a real save\n";
$no_design = build( array( 'homepage.view', 'homepage.edit', 'homepage.sections.edit' ), $registry, $sanitizer, $factory, $validator, $s['docs'] );
$r = $no_design['update']->handle( $key, $hero_id, array( 'content' => array( 'line_1' => 'Text is fine', 'image' => 42 ) ) );
check( 'text field written',        'Text is fine' === $r['section']['content']['line_1'] );
check( 'image field refused',       0 === (int) $r['section']['content']['image'] );
check( 'block reported to the UI',  1 === count( $r['blocked'] ) && 'image' === $r['blocked'][0]['field'] );
check( 'block names the permission','homepage.design.edit' === $r['blocked'][0]['permission'] );

echo "\n[6] Reorder and duplicate\n";
$s3  = build( $FULL, $registry, $sanitizer, $factory, $validator, $s['docs'] );
$doc = $s3['docs']->get( $key );
$ids = array();
foreach ( $doc->sections()->all() as $sec ) { $ids[] = $sec->id()->value(); }
$r = $s3['reorder']->handle( $key, array_reverse( $ids ) );
check( 'order reversed', $r['sections'][0]['id'] === $ids[ count( $ids ) - 1 ] );
check( 'partial reorder refused', throws( \InvalidArgumentException::class, static fn() => $s3['reorder']->handle( $key, array( $ids[0] ) ) ) );

// Story is a max-one component, so duplicating it MUST be refused — duplicate is not a way
// around an instance limit.
check( 'duplicating a max-one section refused', throws( \Yazan\Homepage\Domain\Exception\ValidationFailed::class, static fn() => $s3['duplicate']->handle( $key, $story_id ) ) );

$col    = $s3['create']->handle( $key, 'collections', array( 'heading' => 'Stones' ) );
$col_id = $col['section']['id'];
$r      = $s3['duplicate']->handle( $key, $col_id );
check( 'repeatable section duplicates with a new id', $r['section']['id'] !== $col_id );

echo "\n[7] max_instances is enforced through the handler too\n";
check( 'second hero refused', throws( \Yazan\Homepage\Domain\Exception\ValidationFailed::class, static fn() => $s3['create']->handle( $key, 'hero' ) ) );

echo "\n[8] Autosave: unknown component types survive a save\n";
$s4  = build( $FULL, $registry, $sanitizer, $factory, $validator );
$s4['create']->handle( $key, 'hero', array( 'line_1' => 'A' ) );
$raw = $s4['docs']->stored['default'];
$raw['sections'][] = array(
	'id'      => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
	'type'    => 'from-a-plugin-thats-off',
	'state'   => 'enabled',
	'content' => array( 'kept' => 'yes' ),
);
$s4['docs']->stored['default'] = $raw;
$r = $s4['save']->handle( $key, $raw['sections'] );
$types = array_map( static fn( $x ) => $x['type'], $r['sections'] );
check( 'unknown type preserved, not deleted', in_array( 'from-a-plugin-thats-off', $types, true ) );

echo "\n[9] A draft may be incomplete; a publish may not\n";
$s6 = build( $FULL, $registry, $sanitizer, $factory, $validator );
$r  = $s6['create']->handle( $key, 'story', array() );          // heading is required, and empty
check( 'an empty required field still SAVES', $r['version'] > 1 );
check( 'but it does not PUBLISH', throws( \Yazan\Homepage\Domain\Exception\ValidationFailed::class, static fn() => $s6['publish']->handle( $key ) ) );

$story2 = $r['section']['id'];
$s6['update']->handle( $key, $story2, array( 'content' => array( 'heading' => 'Heritage' ) ) );
check( 'filled in, it publishes', 'published' === $s6['publish']->handle( $key )['status'] );

$s7 = build( $FULL, $registry, $sanitizer, $factory, $validator );
$r  = $s7['create']->handle( $key, 'story', array() );
$s7['update']->handle( $key, $r['section']['id'], array( 'state' => 'disabled' ) );
check( 'a DISABLED section is not held to required fields', 'published' === $s7['publish']->handle( $key )['status'] );

echo "\n[10] The bulk save is not a way around the sort permission\n";
$s5 = build( $FULL, $registry, $sanitizer, $factory, $validator );
$s5['create']->handle( $key, 'hero', array( 'line_1' => 'A' ) );
$s5['create']->handle( $key, 'story', array( 'heading' => 'B' ) );
$rows = $s5['docs']->stored['default']['sections'];

$no_sort = build(
	array( 'homepage.view', 'homepage.edit', 'homepage.sections.edit', 'homepage.sections.create', 'homepage.sections.delete' ),
	$registry, $sanitizer, $factory, $validator, $s5['docs']
);
check( 'same order saves fine',        is_array( $no_sort['save']->handle( $key, $rows ) ) );
check( 'reordering via save refused',  throws( Forbidden::class, static fn() => $no_sort['save']->handle( $key, array_reverse( $rows ) ) ) );

echo "\n[11] The editor payload\n";
$view = $s3['query']->handle( $key );
check( 'document returned',        isset( $view['document']['sections'] ) );
check( 'component catalog sent',   count( $view['components'] ) === count( $registry->all() ) );
check( 'fields travel with it',    ! empty( $view['components'][0]['fields'] ) );
check( 'capability map sent',      isset( $view['can']['homepage.publish'] ) );
check( 'per-section map sent',     isset( $view['sections']['hero']['edit'] ) );

echo "\n----------------------------------------\n";
echo "  passed: $pass   failed: $fail\n";
exit( $fail ? 1 : 0 );
