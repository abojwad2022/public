<?php
/**
 * Yazan Dashboard — bootstrap.
 *
 * Owns the standalone `/dashboard` route: a WordPress rewrite maps it (and any client-side sub-route)
 * to a minimal HTML shell that mounts the React SPA and hands it a fresh `wp_rest` nonce + the current
 * user. Everything the SPA does afterwards goes through the yazan/v1 REST controllers. The page is
 * intentionally NOT rendered through the theme — it is its own document, so no storefront chrome,
 * scripts, or styles leak in.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Route + shell + asset wiring for the dashboard SPA.
 */
class Yazan_Dashboard {

	/** Path segment the dashboard lives under. */
	const SLUG = 'dashboard';

	/** Query var flagged by the rewrite. */
	const QV = 'yazan_dashboard';

	/** Build output dir, relative to the plugin root. */
	const BUILD_DIR = 'assets/dashboard';

	/**
	 * Register the rewrite + query var. Hook: init.
	 *
	 * @return void
	 */
	public static function add_rewrite() {
		add_rewrite_rule( '^' . self::SLUG . '/?$', 'index.php?' . self::QV . '=1', 'top' );
		add_rewrite_rule( '^' . self::SLUG . '/(.+?)/?$', 'index.php?' . self::QV . '=1', 'top' );
	}

	/**
	 * Whitelist our query var. Hook: query_vars.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public static function add_query_var( $vars ) {
		$vars[] = self::QV;
		return $vars;
	}

	/**
	 * Intercept the request and render the shell. Hook: template_redirect.
	 *
	 * @return void
	 */
	public static function maybe_render() {
		if ( ! get_query_var( self::QV ) ) {
			return;
		}
		self::render_shell();
		exit;
	}

	/**
	 * Emit the standalone SPA document.
	 *
	 * @return void
	 */
	private static function render_shell() {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( "Content-Security-Policy: frame-ancestors 'self'" );
		header( 'X-Content-Type-Options: nosniff' );
		status_header( 200 );

		$logged_in = is_user_logged_in() && current_user_can( Yazan_Dashboard_Auth::CAP );
		$boot       = array(
			'restRoot'  => esc_url_raw( rest_url( Yazan_Dashboard_Auth::NS ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			// The nonce below is only valid for 12–24h and is tied to the login session, so a tab
			// left open outlives it. Core's rest-nonce action mints a replacement from the cookie
			// alone — it is the only renewal route that is not itself gated on a nonce.
			'nonceUrl'  => esc_url_raw( admin_url( 'admin-ajax.php?action=rest-nonce' ) ),
			'basePath'  => '/' . self::SLUG,
			'homeUrl'   => esc_url_raw( home_url( '/' ) ),
			'storeUrl'  => esc_url_raw( home_url( '/store/' ) ),
			'loggedIn'  => $logged_in,
			'user'      => $logged_in ? Yazan_Dashboard_Auth::user_payload( wp_get_current_user() ) : null,
		);

		$css   = self::asset_tag( 'style' );
		$js    = self::asset_tag( 'script' );
		$fonts = self::shared_css_tag( 'yz-fonts.css' );

		// The UI sans is on the critical path for every screen, so it is fetched in
		// parallel with the stylesheet rather than after it. The display serif and the
		// Arabic face are not preloaded: the serif appears only on headings, and the
		// Arabic face is gated behind unicode-range and never fetched in a Latin session.
		$inter_preload = sprintf(
			'<link rel="preload" as="font" type="font/woff2" href="%s" crossorigin>',
			esc_url( YAZAN_CORE_URL . 'assets/fonts/inter-var.woff2' )
		);

		// Minimal, self-contained document. The JSON island is the only server→client channel.
		?><!DOCTYPE html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', get_locale() ) ); ?>"<?php echo is_rtl() ? ' dir="rtl"' : ''; ?> data-theme="dark">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html__( 'Yazan — Dashboard', 'yazan' ); ?></title>
	<link rel="icon" href="<?php echo esc_url( YAZAN_CORE_URL . 'assets/dashboard/favicon.svg' ); ?>">
	<?php
	/*
	 * Theme boot — runs BEFORE the stylesheet so the correct theme is painted first time
	 * (no flash of the wrong theme). Defaults to DARK; a saved choice still wins (OS preference intentionally ignored).
	 */
	?>
	<script>
	(function () {
		try {
			var t = localStorage.getItem('yazan-dash-theme');
			if (t !== 'light' && t !== 'dark') {
				t = 'dark'; // default to dark; a saved choice still wins (OS preference intentionally ignored)
			}
			document.documentElement.setAttribute('data-theme', t);
		} catch (e) {}
	})();
	</script>
	<script id="yazan-boot" type="application/json"><?php echo wp_json_encode( $boot ); ?></script>
	<?php
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- built markup, escaped at source.
	echo $inter_preload;
	echo $fonts;
	echo $css;
	// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
</head>
<body>
	<a class="yz-skip-link" href="#yz-main"><?php echo esc_html__( 'Skip to content', 'yazan' ); ?></a>
	<div id="root"></div>
	<?php echo $js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built markup, escaped in asset_tag(). ?>
</body>
</html>
		<?php
	}

	/**
	 * Versioned <link> for a stylesheet in the plugin's shared assets/css/ folder.
	 *
	 * These sheets (yz-tokens.css, yz-fonts.css) are the ones the Rewards iframes and the
	 * wp-admin surfaces also load, so every Yazan surface resolves the identical URL and
	 * the browser caches one copy.
	 *
	 * @param string $file File name inside assets/css/.
	 * @return string
	 */
	public static function shared_css_tag( $file ) {
		$path = YAZAN_CORE_DIR . 'assets/css/' . $file;
		if ( ! file_exists( $path ) ) {
			return '';
		}

		return sprintf(
			'<link rel="stylesheet" href="%s?v=%s">',
			esc_url( YAZAN_CORE_URL . 'assets/css/' . $file ),
			esc_attr( (string) filemtime( $path ) )
		);
	}

	/**
	 * Resolve the built entry's file names from Vite's manifest.
	 *
	 * @return array{js:string,css:string}|null Null when there is no usable build.
	 */
	private static function manifest() {
		static $entry = false;
		if ( false !== $entry ) {
			return $entry;
		}

		$entry = null;
		$path  = trailingslashit( YAZAN_CORE_DIR . self::BUILD_DIR ) . 'manifest.json';
		if ( ! file_exists( $path ) ) {
			return $entry;
		}

		$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local build artefact, not a remote request.
		if ( ! is_array( $data ) ) {
			return $entry;
		}

		$js  = '';
		$css = '';

		foreach ( $data as $record ) {
			if ( empty( $record['file'] ) ) {
				continue;
			}
			if ( ! empty( $record['isEntry'] ) ) {
				$js = (string) $record['file'];
				// When CSS *is* attached to the entry, prefer that association.
				if ( isset( $record['css'][0] ) ) {
					$css = (string) $record['css'][0];
				}
				continue;
			}
			// With cssCodeSplit disabled the single stylesheet is its own record
			// (keyed "style.css") rather than being listed on the entry.
			if ( '' === $css && str_ends_with( (string) $record['file'], '.css' ) ) {
				$css = (string) $record['file'];
			}
		}

		if ( '' !== $js ) {
			$entry = array(
				'js'  => $js,
				'css' => $css,
			);
		}

		return $entry;
	}

	/**
	 * Build the <link>/<script> tag for the compiled bundle, or a helpful notice when the
	 * build is missing (so a fresh checkout explains itself instead of showing a blank page).
	 *
	 * IMPORTANT — these tags carry NO `?v=` cache-buster, and must not.
	 * The bundle is code-split, and every generated chunk imports the entry by its bare
	 * relative name (`from "./app-<hash>.js"`). A query string on the document's script tag
	 * would make `app.js?v=1` and `app.js` two distinct module URLs, so the browser would
	 * evaluate the whole bundle TWICE — two React instances and two copies of every context.
	 * Lazily-loaded screens then read a context with no provider above it and the app blanks.
	 * Cache-busting is done by content hash in the FILE NAME instead, which is also why these
	 * assets can be treated as immutable.
	 *
	 * @param string $type 'style' | 'script'.
	 * @return string
	 */
	private static function asset_tag( $type ) {
		$entry = self::manifest();
		$file  = null;

		if ( $entry ) {
			$file = 'style' === $type ? $entry['css'] : $entry['js'];
		}

		if ( ! $file || ! file_exists( trailingslashit( YAZAN_CORE_DIR . self::BUILD_DIR ) . $file ) ) {
			if ( 'script' === $type ) {
				return '<script>document.getElementById("root").innerHTML='
					. wp_json_encode( '<div style="font:16px/1.6 system-ui;padding:3rem;max-width:40rem;margin:auto;color:#333">'
						. '<h1>Dashboard build not found</h1><p>Run <code>npm install &amp;&amp; npm run build</code> in '
						. '<code>plugins/yazan-core/dashboard-app</code> to generate the bundle and its '
						. '<code>.vite/manifest.json</code>.</p></div>' )
					. ';</script>';
			}
			return '';
		}

		$url = trailingslashit( YAZAN_CORE_URL . self::BUILD_DIR ) . $file;

		if ( 'style' === $type ) {
			return sprintf( '<link rel="stylesheet" href="%s">', esc_url( $url ) );
		}
		return sprintf( '<script type="module" src="%s"></script>', esc_url( $url ) );
	}
}
