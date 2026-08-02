<?php
/**
 * Landing-page document tests.
 *
 * The rules that keep a page builder from eating a site: the homepage is not deletable, two
 * layouts cannot claim one page, and a page nobody bound is never touched.
 */
require __DIR__ . '/wp-stubs.php';
require YAZAN_CORE_DIR . 'includes/homepage/autoload.php';

use Yazan\Homepage\Application\Handler\DocumentsHandler;
use Yazan\Homepage\Application\Service\DocumentValidator;
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

$pass = 0; $fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok   $label\n"; }
	else { $fail++; echo "  FAIL $label\n"; }
}
function throws( $class, callable $fn ) {
	try { $fn(); return false; } catch ( \Throwable $e ) { return $e instanceof $class; }
}

final class MDocs implements HomepageRepositoryPort {
	public $stored = array();
	public function get( DocumentKey $k ) {
		return isset( $this->stored[ $k->value() ] ) ? HomepageDocument::from_array( $this->stored[ $k->value() ] ) : HomepageDocument::create( $k );
	}
	public function save( HomepageDocument $d ) { $d->assign_id( count( $this->stored ) + 1 ); $this->stored[ $d->key()->value() ] = $d->to_array(); }
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
			if ( (int) ( $row['bound_page_id'] ?? 0 ) === (int) $page_id && $page_id ) { return $key; }
		}
		return null;
	}
	public function delete( DocumentKey $k ) {
		if ( 'default' === $k->value() ) { return false; }
		unset( $this->stored[ $k->value() ] );
		return true;
	}
	public function due_for_publish( $now ) { return array(); }
}
final class MAuth implements AuthorizationPort {
	private $g;
	public function __construct( array $g ) { $this->g = $g; }
	public function can( $p ) { return in_array( $p, $this->g, true ); }
	public function can_any( array $ps ) { foreach ( $ps as $p ) { if ( $this->can( $p ) ) return true; } return false; }
	public function require_permission( $p ) { if ( ! $this->can( $p ) ) throw new Forbidden( $p ); }
	public function require_any( array $ps ) { if ( ! $this->can_any( $ps ) ) throw new Forbidden( 'any' ); }
	public function actor_id() { return 2; }
	public function actor_roles() { return array(); }
}
final class MEvents implements EventDispatcherPort {
	public function dispatch( DomainEvent $e ) {}
	public function dispatch_all( array $es ) {}
}

$registry  = new ComponentRegistry();
$validator = new DocumentValidator( $registry );
$docs      = new MDocs();
$auth      = new MAuth( array( 'homepage.view', 'homepage.settings' ) );
$handler   = new DocumentsHandler( $docs, $auth, $registry, $validator, new MEvents() );

// The default homepage exists from the start.
$docs->save( HomepageDocument::create( DocumentKey::default_key(), 'Homepage' ) );

echo "\n[1] Creating a landing page\n";
$made = $handler->create( 'eid-campaign', 'Eid campaign' );
check( 'created with its own key', 'eid-campaign' === $made['key'] );
check( 'the same key twice is refused', throws( ValidationFailed::class, static fn() => $handler->create( 'eid-campaign', 'Again' ) ) );
check( "'default' is reserved",         throws( ValidationFailed::class, static fn() => $handler->create( 'default', 'No' ) ) );
check( 'a malformed key is refused',    throws( \InvalidArgumentException::class, static fn() => $handler->create( 'Not A Key!', 'No' ) ) );

echo "\n[2] Binding to a page\n";
$key = DocumentKey::from( 'eid-campaign' );
$out = $handler->bind( $key, 55 );
check( 'bound', 55 === $out['page'] );
check( 'the page now resolves to it', 'eid-campaign' === $docs->key_for_page( 55 ) );
check( 'a page nobody bound resolves to nothing', null === $docs->key_for_page( 999 ) );

$handler->create( 'winter', 'Winter' );
check( 'a second layout cannot claim the same page', throws( ValidationFailed::class, static fn() => $handler->bind( DocumentKey::from( 'winter' ), 55 ) ) );
check( 're-binding the SAME layout is fine',        55 === $handler->bind( $key, 55 )['page'] );
check( 'a page that does not exist is refused',     throws( SectionNotFound::class, static fn() => $handler->bind( $key, 4242 ) ) );
check( 'the homepage cannot be bound elsewhere',    throws( ValidationFailed::class, static fn() => $handler->bind( DocumentKey::default_key(), 55 ) ) );

$handler->bind( $key, 0 );
check( 'unbinding releases the page', null === $docs->key_for_page( 55 ) );

echo "\n[3] Deleting\n";
check( 'the homepage is not deletable', throws( ValidationFailed::class, static fn() => $handler->remove( DocumentKey::default_key() ) ) );
check( 'a landing page is',             true === $handler->remove( DocumentKey::from( 'winter' ) )['deleted'] );

echo "\n[4] It is all behind homepage.settings\n";
$weak = new DocumentsHandler( $docs, new MAuth( array( 'homepage.view', 'homepage.edit', 'homepage.publish' ) ), $registry, $validator, new MEvents() );
check( 'creating needs it', throws( Forbidden::class, static fn() => $weak->create( 'x', 'X' ) ) );
check( 'binding needs it',  throws( Forbidden::class, static fn() => $weak->bind( $key, 55 ) ) );
check( 'deleting needs it', throws( Forbidden::class, static fn() => $weak->remove( $key ) ) );
check( 'but listing only needs view', is_array( $weak->listing()['documents'] ) );

echo "\n[5] The listing\n";
$list = $handler->listing();
check( 'the homepage is listed first', 'default' === $list['documents'][0]['doc_key'] );
check( 'and every row carries a URL',  isset( $list['documents'][0]['url'] ) );

echo "\n----------------------------------------\n";
echo "  passed: $pass   failed: $fail\n";
exit( $fail ? 1 : 0 );
