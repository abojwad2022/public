<?php
/**
 * Theme-bridge tests.
 *
 * Proves the promise the whole phase rests on: the nine hand-built section templates keep working
 * untouched, the document supplies content when it has it, and the theme's own copy survives when
 * it does not.
 */
require __DIR__ . '/wp-stubs.php';
require YAZAN_CORE_DIR . 'includes/homepage/autoload.php';

use Yazan\Homepage\Application\Service\FieldSanitizer;
use Yazan\Homepage\Application\Service\SectionFactory;
use Yazan\Homepage\Domain\Component\ComponentRegistry;
use Yazan\Homepage\Infrastructure\Adapter\WpMediaAdapter;
use Yazan\Homepage\Presentation\Components\CollectionsComponent;
use Yazan\Homepage\Presentation\Components\HeroComponent;
use Yazan\Homepage\Presentation\Components\StoryComponent;
use Yazan\Homepage\Presentation\Components\TrustComponent;
use Yazan\Homepage\Presentation\Components\BestSellersComponent;
use Yazan\Homepage\Presentation\Render\RenderContext;
use Yazan\Homepage\Presentation\Render\ThemeBridge;

$pass = 0; $fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok   $label\n"; }
	else { $fail++; echo "  FAIL $label\n"; }
}

$registry = new ComponentRegistry();
foreach ( array( HeroComponent::class, StoryComponent::class, CollectionsComponent::class, TrustComponent::class, BestSellersComponent::class ) as $class ) {
	$registry->register( $class::definition() );
}

$factory = new SectionFactory( $registry, new FieldSanitizer( new WpMediaAdapter() ) );

// ServiceFactory::components() is what ThemeBridge asks; point it at this registry.
add_action( 'yazan_homepage_register_components', static function ( $r ) use ( $registry ) {
	foreach ( $registry->all() as $definition ) { $r->register( $definition ); }
} );

ThemeBridge::bind_filters();

echo "\n[1] No section in context → the theme's own values pass through untouched\n";
check( 'text passthrough',  'THEME DEFAULT' === apply_filters( 'yazan_home_text_hero_line_1', 'THEME DEFAULT' ) );
check( 'image passthrough', 'theme.jpg' === apply_filters( 'yazan_home_image_hero_image', 'theme.jpg' ) );
check( 'array passthrough', array( 'x' ) === apply_filters( 'yazan_home_reviews', array( 'x' ) ) );

echo "\n[2] A published section supplies its content\n";
$hero = $factory->make( 'hero', array(
	'line_1'  => 'Formed by the earth.',
	'line_2'  => '',                       // left empty on purpose
	'eyebrow' => 'Yemeni Aqeeq',
	'image'   => 42,
) );
RenderContext::push( $hero, $registry->get( 'hero' ) );
check( 'filled field wins',            'Formed by the earth.' === apply_filters( 'yazan_home_text_hero_line_1', 'THEME DEFAULT' ) );
check( 'EMPTY field keeps theme copy', 'THEME DEFAULT' === apply_filters( 'yazan_home_text_hero_line_2', 'THEME DEFAULT' ) );
check( 'image id resolves to a URL',   'https://yazan.local/img/42.jpg' === apply_filters( 'yazan_home_image_hero_image', 'theme.jpg' ) );
check( 'unbound key untouched',        'other' === apply_filters( 'yazan_home_text_something_else', 'other' ) );
RenderContext::pop();

echo "\n[3] Context is per-section — two blocks of the same type stay separate\n";
$a = $factory->make( 'collections', array( 'heading' => 'Stones' ) );
$b = $factory->make( 'collections', array( 'heading' => 'Silver' ) );
RenderContext::push( $a, $registry->get( 'collections' ) );
$first = apply_filters( 'yazan_home_text_collections_heading', 'D' );
RenderContext::pop();
RenderContext::push( $b, $registry->get( 'collections' ) );
$second = apply_filters( 'yazan_home_text_collections_heading', 'D' );
RenderContext::pop();
check( 'first block reads its own heading',  'Stones' === $first );
check( 'second block reads its own heading', 'Silver' === $second );
check( 'stack empty after pops',             ! RenderContext::is_active() );

echo "\n[4] Category cards are shaped exactly as the template expects\n";
$c = $factory->make( 'collections', array( 'heading' => 'Collections', 'terms' => array( 11, 12 ) ) );
RenderContext::push( $c, $registry->get( 'collections' ) );
$cards = apply_filters( 'yazan_home_collection_cards', array( array( 'name' => 'theme' ) ) );
RenderContext::pop();
check( 'two cards',            is_array( $cards ) && 2 === count( $cards ) );
check( 'name / url / image',   isset( $cards[0]['name'], $cards[0]['url'], $cards[0]['image'] ) );
check( 'order follows the id list', 'Red Aqeeq' === $cards[0]['name'] && 'Blue Aqeeq' === $cards[1]['name'] );
check( 'image is a URL string', 'https://yazan.local/img/501.jpg' === $cards[0]['image'] );
check( 'missing thumbnail → empty string, not a broken id', '' === $cards[1]['image'] );

echo "\n[5] Trust items map onto the template's i/t/d keys\n";
$t = $factory->make( 'trust', array(
	'heading' => 'Why YAZAN',
	'items'   => array(
		array( 'icon' => 'gem', 'title' => 'Authentic', 'description' => 'Real aqeeq.' ),
		array( 'icon' => '<script>', 'title' => 'Silver', 'description' => '925.' ),
	),
) );
RenderContext::push( $t, $registry->get( 'trust' ) );
$items = apply_filters( 'yazan_home_trust_items', array( array( 'i' => 'gem', 't' => 'theme', 'd' => '' ) ) );
RenderContext::pop();
check( 'two items',              2 === count( $items ) );
check( 'keys are i / t / d',     isset( $items[0]['i'], $items[0]['t'], $items[0]['d'] ) );
check( 'valid icon kept',        'gem' === $items[0]['i'] );
check( 'invalid icon refused (never raw markup)', 'gem' === $items[1]['i'] || '' === $items[1]['i'] );

echo "\n[6] Product row builds real WP_Query arguments\n";
$p = $factory->make( 'bestsellers', array(
	'heading' => 'Best Sellers',
	'query'   => array( 'source' => 'best_sellers', 'limit' => 6, 'order' => 'DESC' ),
) );
RenderContext::push( $p, $registry->get( 'bestsellers' ) );
$args = apply_filters( 'yazan_home_bestsellers_args', array( 'posts_per_page' => 4 ) );
RenderContext::pop();
check( 'limit applied',           6 === $args['posts_per_page'] );
check( 'sorted by sales',         'total_sales' === $args['meta_key'] && 'meta_value_num' === $args['orderby'] );
check( 'no_found_rows for speed', true === $args['no_found_rows'] );
check( 'catalogue-hidden excluded', 'exclude-from-catalog' === $args['tax_query'][0]['terms'] );

echo "\n[7] An empty manual list must not become 'every product'\n";
$m = $factory->make( 'bestsellers', array( 'query' => array( 'source' => 'manual', 'ids' => array() ) ) );
RenderContext::push( $m, $registry->get( 'bestsellers' ) );
$args = apply_filters( 'yazan_home_bestsellers_args', array() );
RenderContext::pop();
check( 'sentinel id keeps the result empty', array( 0 ) === $args['post__in'] );

echo "\n----------------------------------------\n";
echo "  passed: $pass   failed: $fail\n";
exit( $fail ? 1 : 0 );
