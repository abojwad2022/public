<?php
/**
 * Yazan — asset enqueue.
 *
 * Loads brand fonts, the stylesheet layer (base/motion/woocommerce) and the JS layer
 * (motion observer, UI interactions, and GSAP parallax on the front page only).
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache-busting version string for a theme-local asset.
 *
 * Every enqueue here used to pass the hardcoded YAZAN_VERSION constant. That
 * only busts caches when someone remembers to bump the constant by hand — so
 * any CSS/JS edit without a manual bump shipped stale to every returning
 * visitor, and to any CDN in front of the site. It also meant a single bump
 * invalidated all 20+ files at once even if one line changed.
 *
 * Appending the file's own mtime fixes both: each asset gets its own version,
 * it changes exactly when that file changes, and the human-readable release
 * number stays visible in the URL (`?ver=2.6.0.1753441200`).
 *
 * The stat() call is trivially cheap and OS-cached, and results are memoised
 * per request. Falls back to the bare constant if the file is missing, so a
 * mistyped path degrades to current behaviour instead of an empty version.
 *
 * @param string $relative Path relative to the child theme root, e.g. 'assets/css/main.css'.
 * @return string Version string for wp_enqueue_style()/wp_enqueue_script().
 */
function yazan_asset_ver( $relative ) {
	static $cache = array();

	$relative = ltrim( (string) $relative, '/' );

	if ( isset( $cache[ $relative ] ) ) {
		return $cache[ $relative ];
	}

	$path  = YAZAN_DIR . '/' . $relative;
	$mtime = is_readable( $path ) ? filemtime( $path ) : false;

	$cache[ $relative ] = $mtime ? YAZAN_VERSION . '.' . $mtime : YAZAN_VERSION;

	return $cache[ $relative ];
}

/**
 * Brand fonts — Cormorant Garamond (display) + Jost (body).
 *
 * Loaded from Google Fonts with display=swap. For GDPR + best performance you can host these
 * locally instead (Astra supports local Google Fonts hosting) and remove this callback.
 */
/**
 * Tiny render-blocking head bits:
 *  - add `.yz-js` to <html> before first paint (so scroll-reveal hiding only applies with JS on
 *    — no flash, and content never stays hidden if JS fails; see motion.css).
 *  - when ?yz-static=1 is present, force all reveals visible (static previews / screenshots).
 */
add_action( 'wp_head', 'yazan_head_inline', 1 );
function yazan_head_inline() {
	echo "<script>document.documentElement.className+=' yz-js';</script>\n";
	if ( isset( $_GET['yz-static'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo "<style>.yz-reveal,[data-reveal],[data-img-reveal]{opacity:1!important;transform:none!important;clip-path:none!important}.yz-hero{min-height:620px!important}.yz-cstory{min-height:0!important}.yz-cstory__media{min-height:360px!important}</style>\n";
	}
}

/**
 * Drop the Google-Fonts requests that other tools add but the brand never uses: Astra's default
 * Lato, Elementor's Roboto / Roboto Slab, and CartFlows' own Lato on the checkout. The brand fonts
 * are the self-hosted Cormorant Garamond (display) + Jost (body), and
 * `body.yazan{font-family:var(--yz-font-body)}` already wins over Astra's Lato — so removing these
 * is purely a network win (part of the zero-external-requests goal). Elementor is additionally
 * disabled by the pre_option filter below; this dequeue is the belt-and-suspenders for the current
 * render, and the ONLY thing that catches CartFlows.
 *
 * Runs at priority 100 so it lands after the plugins that register these handles.
 */
add_action( 'wp_enqueue_scripts', 'yazan_dequeue_foreign_fonts', 100 );
function yazan_dequeue_foreign_fonts() {
	$handles = array(
		'astra-google-fonts',
		'elementor-gf-roboto',
		'elementor-gf-robotoslab',
		// CartFlows loads Lato:700 on the checkout it owns — the last external font on the site.
		'cartflows-google-fonts',
	);

	foreach ( $handles as $handle ) {
		wp_dequeue_style( $handle );
		wp_deregister_style( $handle );
	}
}

/**
 * Keep Elementor's Google Fonts off — permanently.
 *
 * The site's zero-external-requests position was originally achieved by setting the
 * `elementor_google_font` option to 0 in the database. That option later came back UNSET (an
 * Elementor update or a settings save will do it), and because Elementor treats "unset" as
 * "enabled", every page quietly started serving two fonts.googleapis.com stylesheets again —
 * Roboto and Roboto Slab, neither of which this theme ever uses (`body.yazan` sets Jost/Cormorant).
 *
 * A DB value can be unset again by the next update, so this is enforced in code instead:
 * `pre_option_*` short-circuits get_option() before it ever reads the row, which no plugin update
 * can undo. Returning the string '0' (not false) is what makes the short-circuit take effect.
 *
 * To genuinely use a Google font later, remove this filter — do not fight it from the Elementor UI,
 * because the setting screen will look like it saved and change nothing.
 */
add_filter( 'pre_option_elementor_google_font', 'yazan_disable_elementor_google_fonts' );
function yazan_disable_elementor_google_fonts() {
	return '0';
}

/**
 * Drop the leftover preconnect/dns-prefetch hints to the Google Fonts hosts. Nothing loads from them
 * anymore (brand fonts are self-hosted; Astra/Elementor Google fonts removed), so these only open a
 * needless connection to Google — remove for true zero-external + privacy.
 */
add_filter( 'wp_resource_hints', 'yazan_strip_google_font_hints', 20, 2 );
function yazan_strip_google_font_hints( $urls, $relation_type ) {
	if ( 'preconnect' !== $relation_type && 'dns-prefetch' !== $relation_type ) {
		return $urls;
	}
	return array_filter( $urls, function ( $u ) {
		$href = is_array( $u ) ? ( isset( $u['href'] ) ? $u['href'] : '' ) : $u;
		return false === strpos( $href, 'fonts.googleapis.com' ) && false === strpos( $href, 'fonts.gstatic.com' );
	} );
}

add_action( 'wp_enqueue_scripts', 'yazan_enqueue_fonts', 5 );
function yazan_enqueue_fonts() {
	// Brand fonts (Cormorant Garamond + Jost, variable, latin/latin-ext) are SELF-HOSTED in
	// assets/fonts/ via assets/css/fonts.css — zero external requests (no fonts.googleapis.com /
	// fonts.gstatic.com), which is better for privacy, reliability, and LCP. No preconnect needed.
	wp_enqueue_style(
		'yazan-fonts',
		YAZAN_URI . '/assets/css/fonts.css',
		array(),
		yazan_asset_ver( 'assets/css/fonts.css' )
	);

	// Arabic pairing (Amiri display + Cairo body) — RTL locales only, ALSO self-hosted (assets/fonts/
	// via assets/css/fonts-ar.css) so zero-external holds when Arabic/RTL is switched on.
	if ( is_rtl() ) {
		wp_enqueue_style(
			'yazan-fonts-ar',
			YAZAN_URI . '/assets/css/fonts-ar.css',
			array(),
			yazan_asset_ver( 'assets/css/fonts-ar.css' )
		);
	}
}

/**
 * Styles + scripts.
 */
add_action( 'wp_enqueue_scripts', 'yazan_enqueue_assets', 15 );
function yazan_enqueue_assets() {
	$css = YAZAN_URI . '/assets/css/';
	$js  = YAZAN_URI . '/assets/js/';

	/* -------------------------------------------------------------- Styles */

	// Interchangeable theme TOKENS (both identities). Loaded FIRST + render-blocking
	// in <head> so the CSS variables for the active theme are resolved before first
	// paint (pairs with the pre-paint boot script in inc/theme-switcher.php → no FOUC).
	wp_enqueue_style( 'yazan-theme-tokens', $css . 'theme-tokens.css', array( 'astra-theme-css' ), yazan_asset_ver( 'assets/css/theme-tokens.css' ) );

	// Base: typography, layout utilities, header, footer.
	wp_enqueue_style( 'yazan-main', $css . 'main.css', array( 'yazan-theme-tokens' ), yazan_asset_ver( 'assets/css/main.css' ) );

	/*
	 * Motion: tokens, reveals, hero, image, card motion, reduced-motion guard.
	 *
	 * The stylesheet is enqueued even for a store with motion off, because `header.css` and
	 * `megamenu.css` declare it as a dependency and dropping it would take the header's layout with
	 * it. What the flag controls is the BEHAVIOUR — the observer script below, and the intensity
	 * variable that the animations scale from. Setting it to zero is what "off" means here.
	 */
	wp_enqueue_style( 'yazan-motion', $css . 'motion.css', array( 'yazan-main' ), yazan_asset_ver( 'assets/css/motion.css' ) );

	$yz_motion = function_exists( 'yazan_motion_settings' ) ? yazan_motion_settings() : array( 'enabled' => true, 'parallax' => true, 'intensity' => 1.0 );

	if ( function_exists( 'yazan_store_module_on' ) && ! yazan_store_module_on( 'motion' ) ) {
		$yz_motion['enabled']  = false;
		$yz_motion['parallax'] = false;
	}

	// One custom property the whole motion system scales from — no per-store branching in the CSS.
	wp_add_inline_style(
		'yazan-motion',
		':root{--yz-motion-intensity:' . ( $yz_motion['enabled'] ? (float) $yz_motion['intensity'] : 0 ) . '}'
	);

	// Luxury header — transparent/sticky/hide-show/logo/menu/mobile.
	wp_enqueue_style( 'yazan-header', $css . 'header.css', array( 'yazan-main', 'yazan-motion' ), yazan_asset_ver( 'assets/css/header.css' ) );

	// Mega menu — premium multi-column dropdown for the header nav (desktop).
	wp_enqueue_style( 'yazan-megamenu', $css . 'megamenu.css', array( 'yazan-header' ), yazan_asset_ver( 'assets/css/megamenu.css' ) );

	// Homepage sections — front page only.
	if ( is_front_page() ) {
		wp_enqueue_style( 'yazan-home', $css . 'home.css', array( 'yazan-main', 'yazan-motion' ), yazan_asset_ver( 'assets/css/home.css' ) );
	}

	// RTL / Arabic overrides — loaded last, only for right-to-left locales.
	if ( is_rtl() ) {
		wp_enqueue_style( 'yazan-rtl', $css . 'rtl.css', array( 'yazan-main' ), yazan_asset_ver( 'assets/css/rtl.css' ) );
	}

	// WooCommerce components: cards, badges, product page, buttons, hairlines.
	if ( class_exists( 'WooCommerce' ) ) {
		wp_enqueue_style( 'yazan-woo', $css . 'woocommerce.css', array( 'yazan-main' ), yazan_asset_ver( 'assets/css/woocommerce.css' ) );
	}

	// BURGUNDY override layer — scoped under html[data-yz-theme="burgundy"], INERT for
	// the Black theme. Depends on every base sheet so it loads LAST and wins the cascade.
	// Loaded site-wide (all WooCommerce pages included) so the chosen theme is inherited
	// everywhere. Adding another dark theme = one more sheet here, no edits above.
	$yazan_theme_deps = array( 'yazan-theme-tokens', 'yazan-main', 'yazan-motion', 'yazan-header', 'yazan-megamenu' );
	if ( class_exists( 'WooCommerce' ) ) {
		$yazan_theme_deps[] = 'yazan-woo';
	}
	if ( is_front_page() ) {
		$yazan_theme_deps[] = 'yazan-home';
	}
	wp_enqueue_style( 'yazan-theme-burgundy', $css . 'theme-burgundy.css', $yazan_theme_deps, yazan_asset_ver( 'assets/css/theme-burgundy.css' ) );

	// NOTE: the CartFlows *Instant Checkout* header is styled via yazan_checkout_inline_css()
	// below — NOT enqueued here. Instant Checkout removes the theme's late wp_enqueue_scripts
	// callbacks (verified: yazan_enqueue_assets is hooked but its body never registers on that
	// template), so a normal wp_enqueue_style() call cannot reach the checkout header.

	/* ------------------------------------------------------------- Scripts */

	// Scroll-reveal observer — runs the whole site (~1KB, deferred). Skipped entirely for a store
	// with motion off: elements keep their final state, which is what the reduced-motion path
	// already renders, so nothing is left invisible.
	if ( $yz_motion['enabled'] ) {
		wp_enqueue_script( 'yazan-motion', $js . 'motion.js', array(), yazan_asset_ver( 'assets/js/motion.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );
	}

	// Luxury header controller: transparent→solid, sticky, smart hide/show, logo shrink.
	wp_enqueue_script( 'yazan-header', $js . 'header.js', array(), yazan_asset_ver( 'assets/js/header.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );

	// Mega menu a11y: touch open, Escape / outside-click close.
	wp_enqueue_script( 'yazan-megamenu', $js . 'megamenu.js', array(), yazan_asset_ver( 'assets/js/megamenu.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );

	// UI interactions: sticky mobile ATC, add-to-cart feedback, shop filters, live search.
	wp_enqueue_script( 'yazan-ui', $js . 'interactions.js', array(), yazan_asset_ver( 'assets/js/interactions.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );
	wp_localize_script(
		'yazan-ui',
		'YazanLiveSearch',
		array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'yazan_live_search' ),
		)
	);

	// Parallax (GSAP + ScrollTrigger) — front page only, desktop-only behaviour handled in JS.
	if ( is_front_page() && $yz_motion['enabled'] && $yz_motion['parallax'] ) {
		// Hosted locally (assets/js/vendor/) so the front page makes ZERO external requests — better for
		// privacy, reliability, and LCP than the cdnjs CDN. GSAP 3.12.5 (see vendor/ for the pinned copy).
		wp_enqueue_script( 'gsap', $js . 'vendor/gsap.min.js', array(), '3.12.5', true );
		wp_enqueue_script( 'gsap-st', $js . 'vendor/ScrollTrigger.min.js', array( 'gsap' ), '3.12.5', true );
		wp_enqueue_script( 'yazan-parallax', $js . 'parallax.js', array( 'gsap-st' ), yazan_asset_ver( 'assets/js/parallax.js' ), true );
	}
}

/**
 * Inline the checkout-header CSS on checkout/cart views.
 *
 * WHY inline instead of wp_enqueue_style(): CartFlows Instant Checkout strips the theme's late
 * `wp_enqueue_scripts` callbacks, so `yazan_enqueue_assets()` never actually registers on that
 * template (verified) and the child-theme stylesheets never load there. Printing on `wp_head` at
 * a late priority sidesteps that pipeline entirely AND lands after `wp_print_styles`, so it wins
 * the cascade over CartFlows' own checkout-template.css. Scoped to `.cartflows-instant-checkout`
 * in the CSS itself, so it is inert on any non-instant checkout that uses the theme header.
 */
/**
 * Kill the logo "flash" on load. Astra prints `.custom-logo-link img{width:120px}` inline in <head>,
 * which applies at first paint — before the child theme's external header.css can constrain it — so
 * the tall YAZAN mark briefly renders 120px wide (stretched/oversized) until the stylesheet loads.
 * Printing our own proportional override inline at a LATE wp_head priority (after Astra's inline CSS)
 * makes the logo render at its final size from the very first paint. Mirror of the header.css rule.
 */
add_action( 'wp_head', 'yazan_logo_inline_css', 101 );
function yazan_logo_inline_css() {
	echo "<style id=\"yazan-logo-inline\">.yazan .yz-header .custom-logo-link img,.yazan .yz-header .site-logo-img img,.yazan .yz-header .site-branding img{width:auto!important;max-width:none!important;height:auto!important;max-height:44px!important}</style>\n";
}

add_action( 'wp_head', 'yazan_checkout_inline_css', 100 );
function yazan_checkout_inline_css() {
	if ( ! function_exists( 'yazan_is_checkout_view' ) || ! yazan_is_checkout_view() ) {
		return;
	}

	$file = YAZAN_DIR . '/assets/css/checkout.css';
	if ( ! is_readable( $file ) ) {
		return;
	}

	$css = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- local theme file, not remote.
	if ( false === $css || '' === trim( $css ) ) {
		return;
	}

	// Static, first-party theme CSS — safe to print verbatim inside a <style> tag.
	echo "\n<style id=\"yazan-checkout-inline\">\n" . $css . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Inline the premium THANK-YOU styling on the CartFlows Instant thank-you page.
 *
 * Same delivery constraint as yazan_checkout_inline_css() above: the CartFlows instant thank-you
 * template is a standalone HTML document that strips the theme's late enqueue callbacks, so the
 * child-theme stylesheets AND the --yz-* token sheet never load there. We therefore (1) <link> the
 * self-hosted brand fonts (Cormorant + Jost, plus the Arabic Amiri/Cairo pair for Arabic names and
 * addresses) and (2) print thankyou.css inline. Printing on wp_head at priority 100 lands after
 * wp_print_styles, so it wins the cascade over CartFlows' own instant-thankyou-styles.css. The sheet
 * carries its own local --ty-* palette (Black + Burgundy), so both identities are covered without the
 * token sheet. Gated to the thank-you view only, so it never touches the checkout form or the rest
 * of the site. Runs on wp_head (fired by the template's wp_head()).
 */
add_action( 'wp_head', 'yazan_thankyou_inline_css', 100 );
function yazan_thankyou_inline_css() {
	if ( ! function_exists( 'yazan_is_thankyou_view' ) || ! yazan_is_thankyou_view() ) {
		return;
	}

	$file = YAZAN_DIR . '/assets/css/thankyou.css';
	if ( ! is_readable( $file ) ) {
		return;
	}

	$css = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- local theme file, not remote.
	if ( false === $css || '' === trim( $css ) ) {
		return;
	}

	// Brand fonts must be <link>ed here — the normal wp_enqueue_style() path is stripped on this
	// standalone template. Self-hosted, so still zero external requests.
	$ver = yazan_asset_ver( 'assets/css/fonts.css' );
	printf(
		"<link rel=\"stylesheet\" id=\"yazan-ty-fonts\" href=\"%s\" media=\"all\" />\n",
		esc_url( YAZAN_URI . '/assets/css/fonts.css?ver=' . $ver )
	);
	printf(
		"<link rel=\"stylesheet\" id=\"yazan-ty-fonts-ar\" href=\"%s\" media=\"all\" />\n",
		esc_url( YAZAN_URI . '/assets/css/fonts-ar.css?ver=' . yazan_asset_ver( 'assets/css/fonts-ar.css' ) )
	);

	// Static, first-party theme CSS — safe to print verbatim inside a <style> tag.
	echo "\n<style id=\"yazan-thankyou-inline\">\n" . $css . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Kill the checkout-header logo "size flash" (the ~1s giant logo on entering CartFlows checkout).
 *
 * CartFlows Instant Checkout prints the site logo as a bare `get_custom_logo()` <img> carrying the
 * upload's intrinsic width/height attributes (here 360x393, a PORTRAIT mark) and NO inline style.
 * CartFlows' own checkout-template.css only sets `width:auto` on it — it never caps the height. Our
 * height cap lives in the inlined checkout.css (yazan_checkout_inline_css above), i.e. a <head>
 * <style> block, so it only takes effect once that stylesheet is committed. In the paint window
 * before that commit the img has nothing constraining it, so it balloons to its intrinsic ~393px
 * height — the giant yellow logo the user saw flash, then snap down to 44px once the CSS applied.
 *
 * The SAME timing gap also caused a COLOUR flash: the logo PNG is natively GOLD, and it's recoloured
 * to black on the checkout header via `filter: brightness(0)` in checkout.css — another <head> <style>
 * rule. So before that stylesheet committed the logo flashed GOLD, then snapped to black.
 *
 * Fix: stamp BOTH the height constraint AND the black recolour as an inline STYLE ATTRIBUTE on the
 * <img> itself. An element's own style attribute travels in the same HTML token as the element and is
 * applied the instant it is parsed — no dependency on any <style>/<link> being committed — so the logo
 * is never painted at intrinsic size and never painted gold: it is black at 44px from the very first
 * frame. The Burgundy theme (which needs a WHITE logo, `brightness(0) invert(1)`) still wins because
 * its checkout.css rule is marked `!important`, and a stylesheet `!important` beats a normal inline
 * declaration. So Black shows black instantly, Burgundy settles to white — the switch keeps working.
 */
add_filter( 'cartflows_instant_checkout_header_logo', 'yazan_checkout_logo_no_flash', 20 );
function yazan_checkout_logo_no_flash( $logo_html ) {
	// Bail if there's no <img>, or it already carries an inline style (e.g. CartFlows' own custom-logo
	// path already sizes it) — never stomp an existing style attribute.
	if ( ! is_string( $logo_html ) || false === strpos( $logo_html, '<img' ) || false !== strpos( $logo_html, 'style=' ) ) {
		return $logo_html;
	}

	// `opacity:0` starts the logo HIDDEN. On this stripped template only element-inline styles paint at
	// parse time — the head <style>/checkout-template.css commit a beat later — so without this the
	// (now correctly sized & blackened) logo paints ALONE before the header band and form arrive. The
	// checkout.css `yz-logo-fade-in` animation reveals it, and that animation only runs once the head
	// CSS commits — i.e. the same moment the rest of the header/page paints — so they appear together.
	return preg_replace(
		'/<img\b/',
		'<img style="width:auto;height:44px;max-height:44px;filter:brightness(0);opacity:0"',
		$logo_html,
		1
	);
}

/**
 * Let the inline `filter: brightness(0)` above survive wp_kses_post() on the checkout header.
 *
 * CartFlows echoes the logo through wp_kses_post(), whose inline-CSS sanitiser (safecss_filter_attr)
 * DROPS the `filter` declaration: `filter` isn't in kses' allowed-property list, and even once allowed
 * the `brightness()` function trips kses' "no `(` in values" guard. So without this, the recolour we
 * stamp in yazan_checkout_logo_no_flash() is silently stripped and the logo flashes GOLD before the
 * stylesheet's `filter: brightness(0)` finally lands (the exact flash we're killing).
 *
 * These two hooks re-permit ONLY a bare `filter: brightness(n) [invert(n)]` — a script-free, URL-free
 * recolour — and ONLY on checkout views (registered on template_redirect behind yazan_is_checkout_view),
 * so the relaxation never touches normal post content elsewhere on the site.
 */
/**
 * Safety net for the checkout logo's inline `opacity:0`.
 *
 * The logo is revealed by the checkout.css `yz-logo-fade-in` animation. That is reliable for any
 * working page (the stylesheet always commits), but if checkout.css ever fails to load while the
 * CartFlows header still renders, the logo would be stuck invisible. This late, low-priority timer
 * force-shows any checkout logo that is still fully transparent after a few seconds — it never fires
 * on a normal load (the fade completes in <0.5s), so it only ever rescues the broken-CSS edge case.
 */
add_action( 'wp_footer', 'yazan_checkout_logo_reveal_fallback', 100 );
function yazan_checkout_logo_reveal_fallback() {
	if ( ! function_exists( 'yazan_is_checkout_view' ) || ! yazan_is_checkout_view() ) {
		return;
	}
	?>
<script id="yazan-checkout-logo-fallback">
window.setTimeout(function () {
	var img = document.querySelector('.main-header--site-logo img, .main-header--content img.custom-logo');
	if (img && window.getComputedStyle(img).opacity === '0') {
		img.style.opacity = '1';
	}
}, 3000);
</script>
	<?php
}

add_action( 'template_redirect', 'yazan_allow_checkout_logo_filter_css' );
function yazan_allow_checkout_logo_filter_css() {
	if ( ! function_exists( 'yazan_is_checkout_view' ) || ! yazan_is_checkout_view() ) {
		return;
	}

	// 1) Permit `filter` as an inline-style property.
	add_filter(
		'safe_style_css',
		function ( $props ) {
			$props[] = 'filter';
			return $props;
		}
	);

	// 2) Approve ONLY a bare brightness()/invert() value; defer to kses' verdict for anything else.
	add_filter(
		'safecss_filter_attr_allow_css',
		function ( $allow, $css_test_string ) {
			if ( preg_match( '/^\s*filter:\s*brightness\(\s*[0-9.]+\s*\)(\s+invert\(\s*[0-9.]+\s*\))?\s*$/', $css_test_string ) ) {
				return true;
			}
			return $allow;
		},
		10,
		2
	);
}
