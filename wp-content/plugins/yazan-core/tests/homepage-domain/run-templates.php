<?php
/**
 * Template library tests.
 *
 * The one rule worth proving: applying a template is not a privileged path. It re-sanitises, it
 * re-checks permissions, and it mints new identities.
 */
require __DIR__ . '/wp-stubs.php';
require YAZAN_CORE_DIR . 'includes/homepage/autoload.php';

use Yazan\Homepage\Application\Handler\CreateSectionHandler;
use Yazan\Homepage\Application\Handler\TemplateHandler;
use Yazan\Homepage\Application\Service\DocumentValidator;
use Yazan\Homepage\Application\Service\FieldSanitizer;
use Yazan\Homepage\Application\Service\PermissionFilter;
use Yazan\Homepage\Application\Service\SectionFactory;
use Yazan\Homepage\Domain\Component\ComponentRegistry;
use Yazan\Homepage\Domain\Document\DocumentKey;
use Yazan\Homepage\Domain\Document\HomepageDocument;
use Yazan\Homepage\Domain\Event\DomainEvent;
use Yazan\Homepage\Domain\Exception\Forbidden;
use Yazan\Homepage\Domain\Exception\SectionNotFound;
use Yazan\Homepage\Domain\Exception\ValidationFailed;
use Yazan\Homepage\Domain\Port\AuthorizationPort;
use Yazan\Homepage\Domain\Port\EventDispatcherPort;
use Yazan\Homepage\Domain\Port\HomepageRepositoryPort;
use Yazan\Homepage\Domain\Port\RevisionRepositoryPort;
use Yazan\Homepage\Domain\Port\TemplateRepositoryPort;
use Yazan\Homepage\Infrastructure\Adapter\WpMediaAdapter;
use Yazan\Homepage\Presentation\Components\CollectionsComponent;
use Yazan\Homepage\Presentation\Components\GlobalComponent;
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

final class TDocs implements HomepageRepositoryPort {
	public $stored = array();
	public function get( DocumentKey $k ) {
		return isset( $this->stored[ $k->value() ] ) ? HomepageDocument::from_array( $this->stored[ $k->value() ] ) : HomepageDocument::create( $k );
	}
	public function save( HomepageDocument $d ) { $d->assign_id( 1 ); $this->stored[ $d->key()->value() ] = $d->to_array(); }
	public function live_payload( DocumentKey $k ) { return array(); }
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
final class TRevs implements RevisionRepositoryPort {
	public $rows = array();
	public function append( HomepageDocument $d, $a, $n = '', $p = false ) { $this->rows[] = $n; return count( $this->rows ); }
	public function listing( $k, $l = 30, $o = 0 ) { return array(); }
	public function find( $id ) { return null; }
	public function previous_publish( $k, $b ) { return null; }
	public function prune( $k, $keep ) { return 0; }
}
final class TTemplates implements TemplateRepositoryPort {
	public $rows = array();
	public function save( $kind, $name, $type, array $payload, $media, $author ) {
		$id = count( $this->rows ) + 1;
		$this->rows[ $id ] = array( 'id' => $id, 'kind' => $kind, 'component_type' => $type, 'name' => $name, 'payload' => $payload );
		return $id;
	}
	public function all( $kind = '' ) { return array_values( $this->rows ); }
	public function find( $id ) { return $this->rows[ $id ] ?? null; }
	public function delete( $id ) { unset( $this->rows[ $id ] ); return true; }
}
final class TAuth implements AuthorizationPort {
	private $g;
	public function __construct( array $g ) { $this->g = $g; }
	public function can( $p ) { return in_array( $p, $this->g, true ); }
	public function can_any( array $ps ) { foreach ( $ps as $p ) { if ( $this->can( $p ) ) return true; } return false; }
	public function require_permission( $p ) { if ( ! $this->can( $p ) ) throw new Forbidden( $p ); }
	public function require_any( array $ps ) { if ( ! $this->can_any( $ps ) ) throw new Forbidden( 'any' ); }
	public function actor_id() { return 3; }
	public function actor_roles() { return array( 't' ); }
}
final class TEvents implements EventDispatcherPort {
	public function dispatch( DomainEvent $e ) {}
	public function dispatch_all( array $es ) {}
}

$registry = new ComponentRegistry();
$registry->register( StoryComponent::definition() );
$registry->register( CollectionsComponent::definition() );
$registry->register( GlobalComponent::definition() );

$sanitizer = new FieldSanitizer( new WpMediaAdapter() );
$factory   = new SectionFactory( $registry, $sanitizer );
$validator = new DocumentValidator( $registry );

$FULL = array(
	'homepage.view', 'homepage.edit', 'homepage.templates.view', 'homepage.templates.create',
	'homepage.templates.delete', 'homepage.sections.create', 'homepage.sections.edit',
	'homepage.sections.delete', 'homepage.sections.sort',
);

$docs = new TDocs();
$tpls = new TTemplates();
$revs = new TRevs();
$auth = new TAuth( $FULL );
$evts = new TEvents();
$deps = array( $docs, $auth, $registry, $validator, $evts );
$key  = DocumentKey::default_key();

$create = new CreateSectionHandler( $deps[0], $deps[1], $deps[2], $deps[3], $deps[4], $factory, new PermissionFilter( $auth ) );
$tpl    = new TemplateHandler( $deps[0], $deps[1], $deps[2], $deps[3], $deps[4], $tpls, $revs, $factory );

echo "\n[1] Save a section as a template, then use it\n";
$r  = $create->handle( $key, 'collections', array( 'heading' => 'Stones', 'terms' => array( 11, 12 ) ) );
$id = $r['section']['id'];

$saved = $tpl->save( $key, 'Stone grid', $id );
check( 'template saved',        $saved['id'] > 0 && 'section' === $saved['kind'] );
check( 'an empty name is refused', throws( ValidationFailed::class, static fn() => $tpl->save( $key, '  ', $id ) ) );

$applied = $tpl->apply( $key, $saved['id'] );
check( 'applied to the draft',   2 === count( $docs->get( $key )->sections()->all() ) );
check( 'a NEW identity, not a clone of the id', $applied['section']['id'] !== $id );
check( 'content came along',     'Stones' === $applied['section']['content']['heading'] );
check( 'and so did the term list', array( 11, 12 ) === $applied['section']['content']['terms'] );

echo "\n[2] A template cannot dodge the rules it was saved under\n";
$story = $create->handle( $key, 'story', array( 'heading' => 'Heritage' ) );
$story_tpl = $tpl->save( $key, 'Heritage band', $story['section']['id'] );
// Story is a max-one component and one already exists.
check( 'max_instances still applies', throws( ValidationFailed::class, static fn() => $tpl->apply( $key, $story_tpl['id'] ) ) );

$weak = new TemplateHandler( $docs, new TAuth( array( 'homepage.view', 'homepage.templates.view' ) ), $registry, $validator, $evts, $tpls, $revs, $factory );
check( 'applying needs the create permission', throws( Forbidden::class, static fn() => $weak->apply( $key, $saved['id'] ) ) );
check( 'saving needs its own permission',      throws( Forbidden::class, static fn() => $weak->save( $key, 'x', $id ) ) );
check( 'deleting needs its own permission',    throws( Forbidden::class, static fn() => $weak->remove( $saved['id'] ) ) );

echo "\n[3] Whole-homepage templates\n";
$doc_tpl = $tpl->save( $key, 'Launch layout', null );
check( 'document template saved', 'document' === $doc_tpl['kind'] );

$before = count( $docs->get( $key )->sections()->all() );
$out    = $tpl->apply( $key, $doc_tpl['id'] );
check( 'it REPLACES the draft',   $before === count( $out['sections'] ) );
check( 'and snapshots first',     in_array( 'before_template', $revs->rows, true ) );

$restricted = new TemplateHandler( $docs, new TAuth( array( 'homepage.view', 'homepage.sections.create' ) ), $registry, $validator, $evts, $tpls, $revs, $factory );
check( 'replacing needs delete + sort too', throws( Forbidden::class, static fn() => $restricted->apply( $key, $doc_tpl['id'] ) ) );

echo "\n[4] Missing things fail clearly\n";
check( 'an unknown template is a 404', throws( SectionNotFound::class, static fn() => $tpl->apply( $key, 9999 ) ) );
$listing = $tpl->listing();
check( 'the listing resolves component labels', 'Collections' === $listing['templates'][0]['label'] );
check( 'and flags availability',                true === $listing['templates'][0]['available'] );

echo "\n[5] Shared sections are references, not copies\n";
$s5   = $create->handle( $key, 'collections', array( 'heading' => 'Shared band' ) );
$sid  = $s5['section']['id'];
$out  = $tpl->make_global( $key, $sid, 'Site-wide band' );
$refs = array_values( array_filter( $out['sections'], static fn( $x ) => 'global' === $x['type'] ) );

check( 'the section became a reference',   1 === count( $refs ) );
check( 'pointing at the stored copy',      (int) $refs[0]['content']['ref'] === $out['shared'] );
check( 'the original is gone from the doc', ! in_array( $sid, array_column( $out['sections'], 'id' ), true ) );
check( 'sharing it twice is refused', throws( ValidationFailed::class, static fn() => $tpl->make_global( $key, $refs[0]['id'], 'Again' ) ) );

$applied = $tpl->apply( $key, $out['shared'] );
check( 'using it elsewhere inserts a REFERENCE too', 'global' === $applied['section']['type'] );
check( 'aimed at the same shared section',           (int) $applied['section']['content']['ref'] === $out['shared'] );

echo "\n[6] Detaching breaks the link\n";
// The document already holds other Collections sections from earlier steps, so the detached one is
// tracked by POSITION — the type it turns back into is not unique here.
$target = $applied['section']['id'];
$rows   = $docs->get( $key )->sections()->to_array();

$position = null;
foreach ( $rows as $index => $row ) {
	if ( $row['id'] === $target ) { $position = $index; }
}

$detached = $tpl->detach( $key, $target );
$now      = $detached['sections'][ $position ] ?? array();

check( 'it is a real section again',    'collections' === ( $now['type'] ?? '' ) );
check( 'carrying the shared content',   'Shared band' === ( $now['content']['heading'] ?? '' ) );
check( 'and it kept its place',         null !== $position );
check( 'detaching a normal section is refused', throws( ValidationFailed::class, static fn() => $tpl->detach( $key, $now['id'] ) ) );

echo "\n----------------------------------------\n";
echo "  passed: $pass   failed: $fail\n";
exit( $fail ? 1 : 0 );
