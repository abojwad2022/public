<?php
define( 'ABSPATH', '/tmp/wp/' );
define( 'YAZAN_CORE_DIR', dirname( __DIR__, 2 ) . '/' );
define( 'YAZAN_CORE_VERSION', '1.9.0' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

/*
 * The front-page assignment path. `wp_rand` is the roll — fixed here, and varied by the tests that
 * care through $GLOBALS['__roll'], because a test of a 50/50 split that actually rolls dice is a
 * test that fails one run in a thousand and teaches everyone to re-run the suite.
 */
$GLOBALS['__roll']       = 0;
$GLOBALS['__nocache']    = 0;
function wp_rand( $min = 0, $max = 99 ) { return (int) $GLOBALS['__roll']; }
function nocache_headers() { $GLOBALS['__nocache']++; }
function is_ssl() { return false; }

function __( $t, $d = null ) { return $t; }
function _x( $t, $c, $d = null ) { return $t; }
function esc_url_raw( $u, $p = null ) {
	$u = trim( (string) $u );
	if ( '' === $u ) return '';
	$scheme = strtolower( (string) parse_url( $u, PHP_URL_SCHEME ) );
	if ( $scheme && $p && ! in_array( $scheme, $p, true ) ) return '';
	if ( ! $scheme && 0 !== strpos( $u, '/' ) ) return '';
	return $u;
}
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_hex_color( $s ) { return preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', (string) $s ) ? $s : null; }
function absint( $v ) { return abs( (int) $v ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_generate_uuid4() {
	return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
		random_int(0,0xffff), random_int(0,0xffff), random_int(0,0xffff),
		random_int(0,0x0fff) | 0x4000, random_int(0,0x3fff) | 0x8000,
		random_int(0,0xffff), random_int(0,0xffff), random_int(0,0xffff) );
}
function wp_kses( $html, $allowed, $protocols = array() ) {
	return strip_tags( (string) $html, '<a><strong><b><em><i><br><span>' );
}
function wp_kses_post( $html ) { return strip_tags( (string) $html, '<a><p><strong><em><ul><li><br>' ); }
$GLOBALS['__actions'] = array();
$GLOBALS['__filters'] = array();
function add_action( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['__actions'][ $h ][ $p ][] = $cb; }
function do_action( $h, ...$args ) {
	$all = $GLOBALS['__actions'][ $h ] ?? array();
	ksort( $all );
	foreach ( $all as $bucket ) { foreach ( $bucket as $cb ) { $cb( ...$args ); } }
}
function add_filter( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['__filters'][ $h ][ $p ][] = $cb; }
function apply_filters( $h, $v, ...$rest ) {
	$all = $GLOBALS['__filters'][ $h ] ?? array();
	ksort( $all );
	foreach ( $all as $bucket ) { foreach ( $bucket as $cb ) { $v = $cb( $v, ...$rest ); } }
	return $v;
}
function remove_all_filters( $h ) { unset( $GLOBALS['__filters'][ $h ] ); }

/* Terms — a tiny fixture catalogue for the binding tests. */
$GLOBALS['__terms'] = array(
	11 => array( 'name' => 'Red Aqeeq',   'slug' => 'red-aqeeq',   'thumb' => 501, 'count' => 12 ),
	12 => array( 'name' => 'Blue Aqeeq',  'slug' => 'blue-aqeeq',  'thumb' => 0,   'count' => 7 ),
);
function get_terms( $args = array() ) {
	$out = array();
	$ids = isset( $args['include'] ) ? $args['include'] : array_keys( $GLOBALS['__terms'] );
	foreach ( $ids as $id ) {
		if ( ! isset( $GLOBALS['__terms'][ $id ] ) ) { continue; }
		$t = (object) array( 'term_id' => $id, 'name' => $GLOBALS['__terms'][ $id ]['name'], 'slug' => $GLOBALS['__terms'][ $id ]['slug'], 'count' => $GLOBALS['__terms'][ $id ]['count'] );
		$out[] = $t;
	}
	return $out;
}
function get_term_link( $t ) { return 'https://yazan.local/product-category/' . $t->slug . '/'; }
function get_term_meta( $id, $k, $single = false ) { return $GLOBALS['__terms'][ $id ]['thumb'] ?? 0; }
function is_wp_error( $v ) { return false; }
function taxonomy_exists( $t ) { return in_array( $t, array( 'product_cat', 'product_tag', 'pa_stone' ), true ); }
function wc_get_product_ids_on_sale() { return array(); }
function wp_next_scheduled( $h ) { return false; }
function wp_schedule_single_event( $t, $h ) { return true; }
function wp_clear_scheduled_hook( $h ) { return true; }
function is_admin() { return false; }
function is_front_page() { return true; }
function wp_get_attachment_url( $id ) { return $id ? 'https://yazan.local/uploads/' . $id . '.mp4' : ''; }
function wp_enqueue_style( ...$a ) { $GLOBALS['__styles'][] = $a[0]; return true; }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function esc_url( $v ) { return (string) $v; }
function esc_html_e( $v, $d = null ) { echo esc_html( $v ); }
function filemtime_safe( $p ) { return 1; }
function wp_get_attachment_image( $id, $size = 'medium', $icon = false, $attr = array() ) {
	$id = (int) $id;
	if ( ! $id ) { return ''; }
	// Honour whatever attributes the caller passed — the real function does, and a stub that
	// hardcodes loading="lazy" hides exactly the LCP bug this test exists to catch.
	$attr = array_merge( array( 'alt' => '', 'loading' => 'lazy' ), (array) $attr );
	$out  = '<img src="https://yazan.local/img/' . $id . '.jpg"';
	foreach ( $attr as $key => $value ) {
		$out .= ' ' . $key . '="' . esc_attr( (string) $value ) . '"';
	}
	return $out . ' />';
}
function esc_attr_e( $v, $d = null ) { echo esc_attr( $v ); }
function wp_trim_words( $t, $n = 55, $more = null ) { return $t; }
$GLOBALS['__pages'] = array( 55 => 'Eid campaign', 56 => 'Winter' );
function get_post( $id ) {
	return isset( $GLOBALS['__pages'][ (int) $id ] ) ? (object) array( 'ID' => (int) $id, 'post_type' => 'page' ) : null;
}
function get_the_title( $id = 0 ) { return $GLOBALS['__pages'][ (int) $id ] ?? ''; }
function get_permalink( $id = 0 ) { return 'https://yazan.local/?p=' . (int) $id; }
function sanitize_key_stub() {}
function wp_strip_all_tags( $t, $break = false ) { return trim( strip_tags( (string) $t ) ); }
function get_post_time( $format = 'U', $gmt = false, $post = null ) { return '2026-01-15T09:00:00+00:00'; }
function get_current_user_id() { return 1; }
/*
 * A REAL option store, not a stub that always answers the default.
 *
 * It was the no-op version that let the A/B attribution bug through: the only test that could have
 * caught it needed to store an experiment and read it back, and with get_option() answering `false`
 * forever, no such test could be written. A harness that cannot represent state cannot test the
 * code that depends on it.
 */
$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['__options'][ $k ] ); return true; }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
/*
 * Site-timezone formatting. Pinned to UTC here so a test asserting a filename does not start
 * failing at 21:00 in Riyadh — the production behaviour this stands in for (the SITE's day, not
 * UTC's) is asserted where it matters, in the store's date-boundary tests.
 */
function wp_date( $format, $ts = null, $tz = null ) { return gmdate( (string) $format, null === $ts ? time() : (int) $ts ); }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t = 0 ) { return true; }
function wp_cache_get( $k, $g = '', $f = false, &$found = null ) { $found = false; return false; }
function wp_cache_set( $k, $v, $g = '', $t = 0 ) { return true; }
function get_post_type( $id ) { return 'attachment'; }
function get_post_mime_type( $id ) {
	$id = (int) $id;
	if ( 128 === $id ) { return 'image/svg+xml'; }   // the one we expect to be refused
	if ( 77 === $id )  { return 'video/mp4'; }       // the one used as a video in tests
	return 'image/jpeg';
}
function wp_get_attachment_image_url( $id, $s = 'full' ) { return 'https://yazan.local/img/' . $id . '.jpg'; }
function wp_get_attachment_image_src( $id, $s = 'full' ) { return array( 'https://yazan.local/img/' . $id . '.jpg', 1600, 900 ); }
function wp_get_attachment_image_srcset( $id, $s = 'full' ) { return 'https://yazan.local/img/' . (int) $id . '.jpg 1x'; }
function wp_get_attachment_image_sizes( $id, $s = 'full' ) { return ''; }
function get_post_meta( $id, $k, $single = false ) { return ''; }
function wp_timezone_string() { return 'Asia/Aden'; }
function home_url( $p = '/' ) { return 'https://yazan.local' . $p; }
