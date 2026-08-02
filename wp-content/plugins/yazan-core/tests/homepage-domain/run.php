<?php
require __DIR__ . '/wp-stubs.php';
require YAZAN_CORE_DIR . 'includes/homepage/autoload.php';

use Yazan\Homepage\Application\Service\FieldSanitizer;
use Yazan\Homepage\Application\Service\PermissionFilter;
use Yazan\Homepage\Application\Service\SectionFactory;
use Yazan\Homepage\Application\Service\DocumentValidator;
use Yazan\Homepage\Domain\Component\ComponentDefinition;
use Yazan\Homepage\Domain\Component\ComponentRegistry;
use Yazan\Homepage\Domain\Component\ComponentSchema;
use Yazan\Homepage\Domain\Component\FieldDefinition as F;
use Yazan\Homepage\Domain\Component\FieldType as T;
use Yazan\Homepage\Domain\Document\DocumentKey;
use Yazan\Homepage\Domain\Document\HomepageDocument;
use Yazan\Homepage\Domain\Port\AuthorizationPort;
use Yazan\Homepage\Infrastructure\Adapter\WpMediaAdapter;

$pass = 0; $fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok   $label\n"; }
	else { $fail++; echo "  FAIL $label\n"; }
}

/* A fake actor who may edit sections but NOT design. */
final class FakeAuth implements AuthorizationPort {
	private $granted;
	public function __construct( array $granted ) { $this->granted = $granted; }
	public function can( $p ) { return in_array( $p, $this->granted, true ); }
	public function can_any( array $ps ) { foreach ( $ps as $p ) { if ( $this->can( $p ) ) return true; } return false; }
	public function require_permission( $p ) { if ( ! $this->can( $p ) ) throw new \Yazan\Homepage\Domain\Exception\Forbidden( $p ); }
	public function require_any( array $ps ) { if ( ! $this->can_any( $ps ) ) throw new \Yazan\Homepage\Domain\Exception\Forbidden( 'any' ); }
	public function actor_id() { return 7; }
	public function actor_roles() { return array( 'content' ); }
}

$hero = ComponentDefinition::make( array(
	'type'          => 'hero',
	'label'         => 'Hero',
	'max_instances' => 1,
	'schema'        => ComponentSchema::of( array(
		F::make( 'title',   T::TEXT,     array( 'required' => true, 'constraints' => array( 'max_length' => 20 ) ) ),
		F::make( 'body',    T::RICHTEXT, array( 'constraints' => array( 'policy' => 'inline' ) ) ),
		F::make( 'image',   T::MEDIA,    array( 'permission' => 'homepage.design.edit' ) ),
		F::make( 'overlay', T::RANGE,    array( 'default' => 40, 'permission' => 'homepage.design.edit', 'constraints' => array( 'min' => 0, 'max' => 100 ) ) ),
		F::make( 'cta',     T::BUTTON ),
		F::make( 'align',   T::SELECT,   array( 'default' => 'start', 'responsive' => true, 'constraints' => array( 'choices' => array( 'start', 'center', 'end' ) ) ) ),
	) ),
) );

$registry = new ComponentRegistry();
$registry->register( $hero );

$sanitizer = new FieldSanitizer( new WpMediaAdapter() );
$factory   = new SectionFactory( $registry, $sanitizer );
$validator = new DocumentValidator( $registry );

echo "\n[1] Sanitisation\n";
$section = $factory->make( 'hero', array(
	'title'      => '  <script>alert(1)</script>A very long headline indeed  ',
	'body'       => '<p onclick="x()">Hand-cut <strong>agate</strong><iframe src="//evil"></iframe></p>',
	'image'      => 128,                       // svg mime → must be rejected
	'overlay'    => 500,                       // out of range → clamps to 100
	'cta'        => array( 'url' => 'javascript:alert(1)', 'label' => 'Shop', 'new_tab' => true ),
	'align'      => array( 'desktop' => 'center', 'mobile' => 'bogus' ),
	'not_a_field' => 'should vanish',
) );
$c = $section->content();
check( 'unknown key dropped',            ! array_key_exists( 'not_a_field', $c ) );
check( 'script stripped from text',      false === strpos( $c['title'], '<script' ) );
check( 'max_length enforced',            mb_strlen( $c['title'] ) <= 20 );
check( 'iframe stripped from richtext',  false === strpos( $c['body'], '<iframe' ) );
check( 'strong kept in richtext',        false !== strpos( $c['body'], '<strong>' ) );
check( 'SVG attachment refused',         0 === $c['image'] );
check( 'range clamped to max',           100 === (int) $c['overlay'] );
check( 'javascript: URL refused',        '' === $c['cta']['url'] );
check( 'new tab forces noopener',        'noopener noreferrer' === $c['cta']['rel'] );
check( 'responsive value kept',          'center' === $c['align']['desktop'] );
check( 'invalid choice falls to default','start' === $c['align']['mobile'] );

echo "\n[2] Field-level permissions\n";
$auth   = new FakeAuth( array( 'homepage.sections.edit' ) );   // no design.edit
$filter = new PermissionFilter( $auth );
$stored = $c;
$incoming = $sanitizer->sanitize( $hero->schema(), array( 'title' => 'New title', 'overlay' => 10 ), $stored );
list( $allowed, $blocked ) = $filter->apply( $hero->schema(), $incoming, $stored );
check( 'permitted field written',   'New title' === $allowed['title'] );
check( 'denied field kept as-is',   100 === (int) $allowed['overlay'] );
check( 'block is reported',         1 === count( $blocked ) && 'overlay' === $blocked[0]['field'] );
check( 'block names the permission','homepage.design.edit' === $blocked[0]['permission'] );

echo "\n[3] Aggregate invariants\n";
$doc = HomepageDocument::create( DocumentKey::default_key() );
$doc->add_section( $section, null, $hero->max_instances() );
check( 'section added',        1 === $doc->sections()->count() );
check( 'version bumped',       2 === $doc->version()->value() );
$limit_hit = false;
try { $doc->add_section( $factory->make( 'hero', array( 'title' => 'X' ) ), null, $hero->max_instances() ); }
catch ( \Yazan\Homepage\Domain\Exception\ValidationFailed $e ) { $limit_hit = true; }
check( 'max_instances enforced', $limit_hit );

$b = $factory->make( 'hero', array( 'title' => 'B' ) );
$doc2 = HomepageDocument::create( DocumentKey::default_key() );
$doc2->add_section( $section );
$doc2->add_section( $b );
$ids = array( $b->id()->value(), $section->id()->value() );
$doc2->reorder( $ids );
check( 'reorder applied', $doc2->sections()->all()[0]->id()->value() === $b->id()->value() );
$partial = false;
try { $doc2->reorder( array( $b->id()->value() ) ); } catch ( \InvalidArgumentException $e ) { $partial = true; }
check( 'partial reorder refused (no silent data loss)', $partial );

$dup = $doc2->duplicate_section( $b->id() );
check( 'duplicate gets a new id',   $dup->value() !== $b->id()->value() );
check( 'duplicate sits after source', $doc2->sections()->position_of( $dup ) === 1 );

echo "\n[4] Publish / draft separation\n";
$doc3 = HomepageDocument::create( DocumentKey::default_key() );
$doc3->add_section( $factory->make( 'hero', array( 'title' => 'Live' ) ) );
check( 'nothing live before publish', ! $doc3->has_live_content() );
check( 'unpublished changes flagged', $doc3->has_unpublished_changes() );
$doc3->publish( 12 );
check( 'live after publish',          $doc3->has_live_content() );
check( 'draft matches live',          ! $doc3->has_unpublished_changes() );

echo "\n[5] Events released only once\n";
$events = $doc3->release_events();
check( 'events collected',   count( $events ) >= 2 );
check( 'buffer emptied',     0 === count( $doc3->release_events() ) );
$names = array_map( static fn( $e ) => $e->name(), $events );
check( 'publish event present', in_array( 'homepage.published', $names, true ) );

echo "\n[6] Validation\n";
$bad = HomepageDocument::create( DocumentKey::default_key() );
$bad->add_section( $factory->make( 'hero', array( 'title' => '' ) ) );
$errors = $validator->errors( $bad );
check( 'required field reported', 1 === count( $errors ) && 'required_field' === $errors[0]['code'] );

echo "\n[7] Empty document is valid (backward compatibility)\n";
$empty = HomepageDocument::create( DocumentKey::default_key() );
check( 'no errors',        array() === $validator->errors( $empty ) );
check( 'no live sections', array() === $empty->live_sections() );

echo "\n----------------------------------------\n";
echo "  passed: $pass   failed: $fail\n";
exit( $fail ? 1 : 0 );
