<?php
/**
 * Plugin-rendered section tests.
 *
 * These bands have no theme template, so the plugin draws them. What is worth proving is that they
 * escape their output, refuse to render when a required piece is missing, and never autoplay.
 */
require __DIR__ . '/wp-stubs.php';
require YAZAN_CORE_DIR . 'includes/homepage/autoload.php';

use Yazan\Homepage\Application\Service\FieldSanitizer;
use Yazan\Homepage\Application\Service\SectionFactory;
use Yazan\Homepage\Domain\Component\ComponentRegistry;
use Yazan\Homepage\Infrastructure\Adapter\WpMediaAdapter;
use Yazan\Homepage\Presentation\Components\BrandsComponent;
use Yazan\Homepage\Presentation\Components\ContactCtaComponent;
use Yazan\Homepage\Presentation\Components\FaqComponent;
use Yazan\Homepage\Presentation\Components\HeroSliderComponent;
use Yazan\Homepage\Presentation\Components\ProductCarouselComponent;
use Yazan\Homepage\Presentation\Components\StatisticsComponent;
use Yazan\Homepage\Presentation\Components\VideoComponent;
use Yazan\Homepage\Presentation\Render\PluginTemplateRenderer;
use Yazan\Homepage\Presentation\Render\StructuredData;

$pass = 0; $fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok   $label\n"; }
	else { $fail++; echo "  FAIL $label\n"; }
}

$registry = new ComponentRegistry();
foreach ( array( VideoComponent::class, StatisticsComponent::class, FaqComponent::class, BrandsComponent::class, ContactCtaComponent::class, HeroSliderComponent::class, ProductCarouselComponent::class ) as $c ) {
	$registry->register( $c::definition() );
}
$factory = new SectionFactory( $registry, new FieldSanitizer( new WpMediaAdapter() ) );

function render( $factory, $registry, $type, array $values ) {
	StructuredData::reset();
	$section = $factory->make( $type, $values );
	ob_start();
	PluginTemplateRenderer::render( $section, $registry->get( $type ) );
	return (string) ob_get_clean();
}

echo "\n[1] Video\n";
$html = render( $factory, $registry, 'video', array(
	'heading' => 'Inside the workshop',
	'poster'  => 42,
	'video'   => 0,
) );
check( 'refuses to render without a video file', '' === trim( $html ) );

$html = render( $factory, $registry, 'video', array(
	'heading' => 'Inside the <workshop>',
	'poster'  => 42,
	'video'   => 77,
) );
check( 'renders with both pieces',      false !== strpos( $html, '<video' ) );
check( 'never autoplays',               false === strpos( $html, 'autoplay' ) );
check( 'does not preload the file',     false !== strpos( $html, 'preload="none"' ) );
check( 'has a poster',                  false !== strpos( $html, 'poster=' ) );
check( 'escapes the heading',           false === strpos( $html, '<workshop>' ) );

echo "\n[2] Numbers\n";
$html = render( $factory, $registry, 'statistics', array( 'items' => array() ) );
check( 'nothing to show renders nothing', '' === trim( $html ) );

$html = render( $factory, $registry, 'statistics', array(
	'heading' => 'By the numbers',
	'items'   => array(
		array( 'value' => '1,200+', 'label' => 'Stones cut' ),
		array( 'value' => '',       'label' => 'Empty row' ),
		array( 'value' => '40',     'label' => 'Countries' ),
	),
) );
check( 'keeps a formatted figure',   false !== strpos( $html, '1,200+' ) );
check( 'drops the empty row',        false === strpos( $html, 'Empty row' ) );
check( 'counts only the real rows',  false !== strpos( $html, '--yzhp-stats-count:2' ) );

echo "\n[3] Questions\n";
$html = render( $factory, $registry, 'faq', array(
	'heading' => 'Questions',
	'items'   => array(
		array(
			'question' => 'Is every stone unique?',
			'answer'   => 'Yes — <strong>always</strong>. <script>alert(1)</script>',
		),
	),
) );
check( 'uses <details>, so it works with no JS', false !== strpos( $html, '<details' ) );
check( 'keeps allowed markup',                   false !== strpos( $html, '<strong>always</strong>' ) );
check( 'strips the script TAG on the way in',    false === strpos( $html, '<script' ) );
// wp_kses keeps the text that was inside a stripped tag — by design, and harmless: it is inert
// text at that point. What must never survive is the tag itself, which is what is asserted above.
check( 'no executable markup survives at all',   false === strpos( $html, '<scr' ) && false === strpos( $html, 'onerror' ) );

echo "\n[4] Brands\n";
$html = render( $factory, $registry, 'brands', array( 'items' => array( array( 'name' => 'A', 'logo' => 0 ) ) ) );
check( 'a row with no logo renders nothing', '' === trim( $html ) );

$html = render( $factory, $registry, 'brands', array(
	'heading' => 'As seen in',
	'items'   => array(
		array( 'name' => 'Al "Quds"', 'logo' => 42, 'url' => 'https://example.com' ),
		array( 'name' => 'No logo',   'logo' => 0 ),
	),
) );
check( 'renders the mark that has one',   1 === substr_count( $html, '<img' ) );
check( 'links it',                        false !== strpos( $html, 'href="https://example.com"' ) );
check( 'and carries rel=noopener',        false !== strpos( $html, 'rel="noopener"' ) );
check( 'the name is escaped into alt',    false !== strpos( $html, '&quot;Quds&quot;' ) );

echo "\n[5] Call to action\n";
$html = render( $factory, $registry, 'contact-cta', array( 'heading' => '' ) );
check( 'no heading, no band', '' === trim( $html ) );

$html = render( $factory, $registry, 'contact-cta', array(
	'heading' => 'Commission a piece',
	'primary' => array( 'label' => 'Talk to us', 'url' => 'https://yazan.local/contact', 'new_tab' => true ),
	'secondary' => array( 'label' => 'Bad link', 'url' => 'javascript:alert(1)' ),
) );
check( 'primary button renders',            false !== strpos( $html, 'Talk to us' ) );
check( 'new tab carries noopener',          false !== strpos( $html, 'rel="noopener noreferrer"' ) );
check( 'the javascript: URL never renders', false === strpos( $html, 'javascript:' ) );
check( 'and its button is dropped entirely', false === strpos( $html, 'Bad link' ) );

echo "\n[6] Hero slider\n";
$yz_past   = time() - 3600;
$yz_future = time() + 86400;

$html = render( $factory, $registry, 'hero-slider', array(
	'slides' => array(
		array( 'title' => 'Live now',  'image' => array( 'desktop' => 42 ) ),
		array( 'title' => 'Next week', 'image' => array( 'desktop' => 42 ), 'window' => array( 'from' => $yz_future ) ),
		array( 'title' => 'Expired',   'image' => array( 'desktop' => 42 ), 'window' => array( 'to' => $yz_past ) ),
	),
) );
check( 'the live slide renders',                 false !== strpos( $html, 'Live now' ) );
check( 'a future slide is not sent at all',      false === strpos( $html, 'Next week' ) );
check( 'an expired slide is not sent at all',    false === strpos( $html, 'Expired' ) );
check( 'one slide means no arrows',              false === strpos( $html, 'data-slider-next' ) );

$html = render( $factory, $registry, 'hero-slider', array(
	'slides'   => array(
		array( 'title' => 'One', 'image' => array( 'desktop' => 42, 'mobile' => 43 ) ),
		array( 'title' => 'Two', 'image' => array( 'desktop' => 42 ) ),
	),
	'autoplay' => true,
	'speed'    => 8,
) );
check( 'two slides get arrows and dots',   false !== strpos( $html, 'data-slider-next' ) && false !== strpos( $html, 'data-slider-to' ) );
check( 'speed is emitted in milliseconds', false !== strpos( $html, 'data-speed="8000"' ) );
check( 'a mobile crop becomes a <source>', false !== strpos( $html, 'max-width: 600px' ) );
check( 'the first slide loads eagerly',    false !== strpos( $html, 'loading="eager"' ) );
check( 'the rest do not',                  false !== strpos( $html, 'loading="lazy"' ) );
check( 'only one slide is exposed at a time', 1 === substr_count( $html, 'aria-hidden="true"><picture' ) + substr_count( $html, 'aria-hidden="true"' ) - substr_count( $html, 'class="yzhp-hero__scrim" aria-hidden="true"' ) );

$empty = render( $factory, $registry, 'hero-slider', array( 'slides' => array( array( 'title' => '', 'image' => array( 'desktop' => 0 ) ) ) ) );
check( 'a slide with neither title nor image is dropped', '' === trim( $empty ) );

echo "\n[7] Nested schedules are declared to the render pipeline\n";
check( 'the hero declares its slide windows', array( 'slides.*.window' ) === $registry->get( 'hero-slider' )->schedule_paths() );

echo "\n[8] Product carousel\n";
$carousel = $registry->get( 'product-carousel' );
$per      = null;
foreach ( $carousel->schema()->fields() as $key => $f ) {
	if ( 'per_view' === $key ) { $per = $f; }
}
check( 'cards-per-view is responsive',   $per && $per->is_responsive() );
check( 'and is a layout permission',     'homepage.layout.edit' === $per->permission() );
check( 'the query defaults to newest',   'latest' === $carousel->defaults()['query']['source'] );
check( 'it needs the shared script',     in_array( 'product-carousel', \Yazan\Homepage\Presentation\Render\AssetCollector::NEEDS_SCRIPT, true ) );

// WooCommerce is absent in this harness, so the template must bail rather than fatal.
$html = render( $factory, $registry, 'product-carousel', array( 'heading' => 'New in' ) );
check( 'renders nothing without WooCommerce, no fatal', '' === trim( $html ) );

echo "\n[9] Structured data describes only what is on the page\n";
render( $factory, $registry, 'faq', array( 'heading' => 'Q', 'items' => array() ) );
check( 'an FAQ that did not render describes nothing', array() === StructuredData::nodes() );

render( $factory, $registry, 'faq', array(
	'heading' => 'Questions',
	'items'   => array(
		array( 'question' => 'Is every stone unique?', 'answer' => 'Yes — <strong>always</strong>.' ),
		array( 'question' => 'No answer here',        'answer' => '' ),
	),
) );
$nodes = StructuredData::nodes();
check( 'a rendered FAQ emits FAQPage',      1 === count( $nodes ) && 'FAQPage' === $nodes[0]['@type'] );
check( 'only the complete question',        1 === count( $nodes[0]['mainEntity'] ) );
check( 'the answer is plain text',          'Yes — always.' === $nodes[0]['mainEntity'][0]['acceptedAnswer']['text'] );

render( $factory, $registry, 'video', array( 'heading' => 'Workshop', 'poster' => 42, 'video' => 0 ) );
check( 'a video that did not render describes nothing', array() === StructuredData::nodes() );

render( $factory, $registry, 'video', array( 'heading' => 'Workshop', 'intro' => 'Inside', 'poster' => 42, 'video' => 77 ) );
$nodes = StructuredData::nodes();
check( 'a rendered video emits VideoObject', 1 === count( $nodes ) && 'VideoObject' === $nodes[0]['@type'] );
check( 'with the file URL',                  false !== strpos( $nodes[0]['contentUrl'], '77.mp4' ) );
check( 'and the ATTACHMENT date, not today', '2026-01-15T09:00:00+00:00' === $nodes[0]['uploadDate'] );

render( $factory, $registry, 'brands', array( 'items' => array( array( 'name' => 'A', 'logo' => 42 ) ) ) );
check( 'a component with no describer stays silent', array() === StructuredData::nodes() );

echo "\n[10] The registry treats these like any other component\n";
check( 'seven registered',        7 === count( $registry->all() ) );
check( 'each declares itself plugin-rendered', 'plugin_template' === $registry->get( 'faq' )->renderer() );
check( 'and has no theme part',   null === $registry->get( 'faq' )->theme_part() );
check( 'permissions are generated', in_array( 'homepage.section.video.edit', $registry->permissions(), true ) );

echo "\n----------------------------------------\n";
echo "  passed: $pass   failed: $fail\n";
exit( $fail ? 1 : 0 );
