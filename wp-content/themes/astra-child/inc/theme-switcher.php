<?php
/**
 * Yazan — Interchangeable Theme system (client-side switcher).
 *
 * Provides two (and, by design, N) interchangeable visual identities selectable
 * per-visitor from a floating switcher on the homepage:
 *   • black    — Classic Black Luxury (default, existing)
 *   • burgundy — Dark Burgundy Luxury (new)
 *
 * Responsibilities of this module:
 *   1. A filterable THEME REGISTRY (single source of truth for PHP + JS).
 *   2. A pre-paint HEAD boot script that reads the saved choice from localStorage
 *      and stamps <html data-yz-theme="…"> BEFORE any stylesheet is parsed — so
 *      there is no flash of the wrong theme, on every page (WooCommerce included).
 *   3. Enqueue of the switcher assets + the floating switcher markup (homepage).
 *
 * The token sheets (theme-tokens.css) + the burgundy override sheet
 * (theme-burgundy.css) are enqueued site-wide in inc/enqueue.php, so the chosen
 * theme is inherited by every page automatically.
 *
 * SCALABILITY: add a theme by (a) filtering `yazan_themes`, (b) adding a token
 * block in theme-tokens.css, and (c) — only if it inverts light/dark — a scoped
 * override sheet. No existing code changes.
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Theme registry. The FIRST entry is the default (used when nothing is saved and
 * as the safe fallback for an unknown value).
 *
 * @return array<string,array{label:string,desc:string,ground:string,accent:string}>
 */
function yazan_themes() {
	$themes = array(
		'black' => array(
			'label'  => __( 'Black Luxury', 'yazan' ),
			'desc'   => __( 'Classic', 'yazan' ),
			'ground' => '#141210',
			'accent' => '#8C2F24',
		),
		'burgundy' => array(
			'label'  => __( 'Burgundy Luxury', 'yazan' ),
			'desc'   => __( 'Dark', 'yazan' ),
			'ground' => '#160709',
			'accent' => '#A50606',
		),
	);

	/**
	 * Filter the interchangeable theme registry.
	 *
	 * @param array $themes Theme id => meta.
	 */
	return apply_filters( 'yazan_themes', $themes );
}

/**
 * The default (fallback) theme id — the first registered theme.
 *
 * @return string
 */
function yazan_default_theme() {
	$ids = array_keys( yazan_themes() );
	return $ids ? $ids[0] : 'black';
}

/**
 * localStorage key + the <html> attribute name — shared by PHP + JS.
 */
const YAZAN_THEME_STORAGE_KEY = 'yazan-theme';
const YAZAN_THEME_ATTR        = 'data-yz-theme';

/**
 * Pre-paint boot: stamp <html data-yz-theme> from localStorage BEFORE stylesheets
 * parse, so the correct theme paints on the very first frame (no FOUC / no flash).
 *
 * Hooked at wp_head priority 0 so it prints before the enqueued stylesheets (which
 * print later in wp_head). Runs on EVERY page, so the saved theme is inherited
 * site-wide, including WooCommerce and CartFlows checkout.
 */
add_action( 'wp_head', 'yazan_theme_head_boot', 0 );
function yazan_theme_head_boot() {
	$ids     = array_keys( yazan_themes() );
	$default = yazan_default_theme();

	// A compact allow-list + default, injected safely as JSON.
	$allowed_json = wp_json_encode( $ids );
	$default_json = wp_json_encode( $default );
	$key_json     = wp_json_encode( YAZAN_THEME_STORAGE_KEY );
	$attr_json    = wp_json_encode( YAZAN_THEME_ATTR );

	// No user input is echoed; all values are theme-controlled + JSON-encoded.
	?>
<script id="yazan-theme-boot">/* <![CDATA[ */
(function(){try{
	var K=<?php echo $key_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>,
	    A=<?php echo $attr_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>,
	    L=<?php echo $allowed_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>,
	    D=<?php echo $default_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
	var t;try{t=window.localStorage.getItem(K);}catch(e){t=null;}
	if(L.indexOf(t)===-1){t=D;}
	document.documentElement.setAttribute(A,t);
}catch(e){try{document.documentElement.setAttribute("<?php echo esc_js( YAZAN_THEME_ATTR ); ?>","<?php echo esc_js( $default ); ?>");}catch(_){}}}
)();
/* ]]> */</script>
	<?php
}

/**
 * Enqueue the switcher assets site-wide and expose the registry to JS.
 * (The token + burgundy sheets also load site-wide via inc/enqueue.php.)
 *
 * The switcher is available on every page so a visitor can return to the Black
 * theme (or pick Burgundy) from anywhere — not only the homepage.
 */
add_action( 'wp_enqueue_scripts', 'yazan_theme_switcher_assets', 20 );
function yazan_theme_switcher_assets() {
	wp_enqueue_style(
		'yazan-theme-switcher',
		YAZAN_URI . '/assets/css/theme-switcher.css',
		array( 'yazan-theme-tokens' ),
		YAZAN_VERSION
	);

	wp_enqueue_script(
		'yazan-theme-switcher',
		YAZAN_URI . '/assets/js/theme-switcher.js',
		array(),
		YAZAN_VERSION,
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);

	// id => translated label, for the sun/moon toggle's dynamic aria-label.
	$labels = array();
	foreach ( yazan_themes() as $id => $meta ) {
		$labels[ $id ] = $meta['label'];
	}

	wp_localize_script(
		'yazan-theme-switcher',
		'YazanThemes',
		array(
			'themes'     => array_keys( yazan_themes() ),
			'default'    => yazan_default_theme(),
			'storageKey' => YAZAN_THEME_STORAGE_KEY,
			'attr'       => YAZAN_THEME_ATTR,
			'labels'     => $labels,
			/* translators: %s: the theme that a click will switch to. */
			'switchTpl'  => __( 'Switch to %s', 'yazan' ),
		)
	);
}

/**
 * Render the floating theme switcher in the footer (site-wide).
 *
 * Markup is a labelled radiogroup so it is accessible; the ACTIVE state is driven
 * purely by CSS from <html data-yz-theme> (see theme-switcher.css) → correct on
 * first paint, and JS only maintains ARIA + roving tabindex.
 */
add_action( 'wp_footer', 'yazan_theme_switcher_render' );
function yazan_theme_switcher_render() {
	$themes  = yazan_themes();
	$default = yazan_default_theme();

	// With exactly two themes the control is a single sun/moon switch (a click
	// flips to the other theme). With three or more it stays a dropdown of named
	// options. The class drives which affordance CSS shows; JS reads the same.
	$is_binary = ( 2 === count( $themes ) );
	?>
	<div class="yz-theme-switcher yz-no-theme-anim<?php echo $is_binary ? ' yz-theme-switcher--binary' : ''; ?>">
		<div class="yz-theme-switcher__panel"
			role="radiogroup"
			aria-label="<?php esc_attr_e( 'Choose a visual theme', 'yazan' ); ?>">
			<span class="yz-theme-switcher__title"><?php esc_html_e( 'Theme', 'yazan' ); ?></span>
			<?php foreach ( $themes as $id => $meta ) : ?>
				<button type="button"
					class="yz-theme-option"
					role="radio"
					data-yz-theme-option="<?php echo esc_attr( $id ); ?>"
					aria-checked="<?php echo esc_attr( $id === $default ? 'true' : 'false' ); ?>"
					tabindex="<?php echo esc_attr( $id === $default ? '0' : '-1' ); ?>">
					<span class="yz-theme-option__swatch" aria-hidden="true"></span>
					<span class="yz-theme-option__text">
						<span class="yz-theme-option__name"><?php echo esc_html( $meta['label'] ); ?></span>
						<span class="yz-theme-option__desc"><?php echo esc_html( $meta['desc'] ); ?></span>
					</span>
					<svg class="yz-theme-option__check" width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
						<path d="M13 4.5 6.5 11 3 7.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
				</button>
			<?php endforeach; ?>
		</div>

		<button type="button"
			class="yz-theme-switcher__toggle"
			aria-expanded="false"
			aria-haspopup="true"
			aria-label="<?php esc_attr_e( 'Switch theme', 'yazan' ); ?>">
			<?php // Sun/Moon switch (binary mode). Sun ⇒ light "black" theme, Moon ⇒ dark "burgundy". ?>
			<span class="yz-theme-switcher__daynight" aria-hidden="true">
				<svg class="yz-dn yz-dn--sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" focusable="false">
					<circle cx="12" cy="12" r="4.1"/>
					<path d="M12 2.6v2.6M12 18.8v2.6M4.6 4.6l1.9 1.9M17.5 17.5l1.9 1.9M2.6 12h2.6M18.8 12h2.6M4.6 19.4l1.9-1.9M17.5 6.5l1.9-1.9"/>
				</svg>
				<svg class="yz-dn yz-dn--moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" focusable="false">
					<path d="M20.5 14.3A8.2 8.2 0 1 1 9.7 3.5a6.4 6.4 0 0 0 10.8 10.8Z"/>
				</svg>
			</span>
			<span class="yz-theme-switcher__mark" aria-hidden="true"><i></i><i></i></span>
			<span class="yz-theme-switcher__label"><?php esc_html_e( 'Theme', 'yazan' ); ?></span>
		</button>
	</div>
	<?php
}
