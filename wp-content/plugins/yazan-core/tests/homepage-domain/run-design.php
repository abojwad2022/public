<?php
/**
 * Per-section design tests.
 *
 * A stylesheet is executable text. The one thing that must be true here is that nothing an editor
 * can type ever closes a declaration and starts its own.
 */
require __DIR__ . '/wp-stubs.php';
require YAZAN_CORE_DIR . 'includes/homepage/autoload.php';

use Yazan\Homepage\Application\Service\FieldSanitizer;
use Yazan\Homepage\Application\Service\PermissionFilter;
use Yazan\Homepage\Domain\Design\DesignCompiler;
use Yazan\Homepage\Domain\Design\DesignSchema;
use Yazan\Homepage\Domain\Exception\Forbidden;
use Yazan\Homepage\Domain\Port\AuthorizationPort;
use Yazan\Homepage\Infrastructure\Adapter\WpMediaAdapter;

$pass = 0; $fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok   $label\n"; }
	else { $fail++; echo "  FAIL $label\n"; }
}

final class DAuth implements AuthorizationPort {
	private $g;
	public function __construct( array $g ) { $this->g = $g; }
	public function can( $p ) { return in_array( $p, $this->g, true ); }
	public function can_any( array $ps ) { foreach ( $ps as $p ) { if ( $this->can( $p ) ) return true; } return false; }
	public function require_permission( $p ) { if ( ! $this->can( $p ) ) throw new Forbidden( $p ); }
	public function require_any( array $ps ) { if ( ! $this->can_any( $ps ) ) throw new Forbidden( 'any' ); }
	public function actor_id() { return 1; }
	public function actor_roles() { return array(); }
}

$sanitizer = new FieldSanitizer( new WpMediaAdapter() );
$schema    = DesignSchema::schema();
$id        = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

echo "\n[1] Nothing configured, nothing emitted\n";
check( 'an empty design compiles to nothing', '' === DesignCompiler::compile( $id, array() ) );
$wrapper = DesignCompiler::wrapper( $id, array() );
check( 'the class is scoped to this section', false !== strpos( $wrapper['class'], 'yzhp-sec--aaaaaaaabbbb' ) );
check( 'and carries no attributes',           '' === $wrapper['attributes'] );

echo "\n[2] Spacing is a scale, applied per breakpoint\n";
$design = $sanitizer->sanitize( $schema, array(
	'space_top'    => array( 'desktop' => 'large', 'mobile' => 'small' ),
	'space_bottom' => array( 'desktop' => 'none' ),
) );
$css = DesignCompiler::compile( $id, $design );
check( 'desktop rule emitted',      false !== strpos( $css, '--yzhp-pt:clamp(3rem' ) );
check( 'zero is a real value',      false !== strpos( $css, '--yzhp-pb:0rem' ) );
check( 'mobile lands in a query',   false !== strpos( $css, '@media(max-width:600px)' ) );
check( 'and only the phone value',  false !== strpos( $css, '--yzhp-pt:clamp(1rem' ) );

echo "\n[3] Colours: tokens survive, junk does not\n";
$design = $sanitizer->sanitize( $schema, array( 'background' => '--yz-ink', 'text' => '#FFFFFF' ) );
$css    = DesignCompiler::compile( $id, $design );
check( 'a theme token becomes var()',  false !== strpos( $css, 'background-color:var(--yz-ink)' ) );
check( 'a hex colour is kept',         false !== strpos( $css, 'color:#FFFFFF' ) );
check( 'the theme band is cleared so the override shows', false !== strpos( $css, '>section{background-color:transparent' ) );

$attack = $sanitizer->sanitize( $schema, array(
	'background' => 'red;}body{display:none;}.x{color:',
	'text'       => 'expression(alert(1))',
) );
$css = DesignCompiler::compile( $id, $attack );
check( 'a CSS injection never reaches the sheet', false === strpos( $css, 'body{display:none' ) );
check( 'and neither does expression()',           false === strpos( $css, 'expression' ) );
check( 'the whole thing compiles to nothing',     '' === $css );

echo "\n[4] The animation cannot hide content\n";
$design  = $sanitizer->sanitize( $schema, array( 'animation' => 'rise', 'animation_delay' => 5000, 'animation_duration' => 10 ) );
$wrapper = DesignCompiler::wrapper( $id, $design );
check( 'the animation is declared',   false !== strpos( $wrapper['attributes'], 'data-yzhp-anim="rise"' ) );
check( 'an absurd delay is clamped',  false !== strpos( $wrapper['attributes'], '--yzhp-anim-delay:1000ms' ) );
check( 'so is a too-short duration',  false !== strpos( $wrapper['attributes'], '--yzhp-anim-duration:150ms' ) );

$bogus = $sanitizer->sanitize( $schema, array( 'animation' => 'explode' ) );
check( 'an unknown animation falls back to none', 'none' === $bogus['animation'] );

echo "\n[5] Design fields answer to design permissions\n";
$narrow = new PermissionFilter( new DAuth( array( 'homepage.colors.edit' ) ) );
$stored = $sanitizer->sanitize( $schema, array( 'background' => '--yz-ink', 'space_top' => array( 'desktop' => 'large' ) ) );
$wanted = $sanitizer->sanitize( $schema, array( 'background' => '#111111', 'space_top' => array( 'desktop' => 'none' ) ), $stored );
list( $allowed, $blocked ) = $narrow->apply( $schema, $wanted, $stored );
check( 'a colours-only role may change the colour', '#111111' === $allowed['background'] );
check( 'but not the spacing',                       'large' === $allowed['space_top']['desktop'] );
check( 'and is told which permission is missing',   1 === count( $blocked ) && 'homepage.layout.edit' === $blocked[0]['permission'] );

$umbrella = new PermissionFilter( new DAuth( array( 'homepage.design.edit' ) ) );
list( $allowed, $blocked ) = $umbrella->apply( $schema, $wanted, $stored );
check( 'the design umbrella covers both', 'none' === $allowed['space_top']['desktop'] && array() === $blocked );

echo "\n[6] Background images\n";
$design = $sanitizer->sanitize( $schema, array( 'background_image' => 42, 'overlay' => 200 ) );
$css    = DesignCompiler::compile( $id, $design );
check( 'the image is resolved to a URL', false !== strpos( $css, 'background-image:url("https://yazan.local/img/42.jpg")' ) );
check( 'the overlay is clamped to 0.9',  false !== strpos( $css, '--yzhp-overlay:0.9' ) );

$svg = $sanitizer->sanitize( $schema, array( 'background_image' => 128 ) );
check( 'an SVG is refused as a background', 0 === $svg['background_image'] );

echo "\n[X] A design of pure defaults is not a design\n";
$defaults = array(
	'space_top'          => 'inherit',
	'space_bottom'       => 'inherit',
	'background'         => '',
	'background_image'   => 0,
	'overlay'            => 40,
	'text'               => '',
	'animation'          => 'none',
	'animation_delay'    => 0,
	'animation_duration' => 600,
);

check( 'the editor\'s default payload has no effect', ! DesignCompiler::has_effect( $defaults ) );
check( 'and compiles to nothing',                     '' === DesignCompiler::compile( 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $defaults ) );
check( 'an empty payload has no effect',              ! DesignCompiler::has_effect( array() ) );

$spaced             = $defaults;
$spaced['space_top'] = 'large';
check( 'spacing counts as a design', DesignCompiler::has_effect( $spaced ) );

$coloured               = $defaults;
$coloured['background'] = '--ink';
check( 'a background counts', DesignCompiler::has_effect( $coloured ) );

$animated              = $defaults;
$animated['animation'] = 'rise';
check( 'an animation counts even with no CSS of its own', DesignCompiler::has_effect( $animated ) );

$junk               = $defaults;
$junk['background'] = 'red; position:fixed';
check( 'a refused colour is still no design', ! DesignCompiler::has_effect( $junk ) );

echo "\n----------------------------------------\n";
echo "  passed: $pass   failed: $fail\n";
exit( $fail ? 1 : 0 );
