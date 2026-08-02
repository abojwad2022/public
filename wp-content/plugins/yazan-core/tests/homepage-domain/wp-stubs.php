<?php
define( 'ABSPATH', '/tmp/wp/' );
define( 'YAZAN_CORE_DIR', dirname( __DIR__, 2 ) . '/' );
define( 'YAZAN_CORE_VERSION', '1.9.0' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

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
function add_action( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['__actions'][ $h ][] = $cb; }
function do_action( $h, ...$args ) {
	foreach ( (array) ( $GLOBALS['__actions'][ $h ] ?? array() ) as $cb ) { $cb( ...$args ); }
}
function add_filter( $h, $cb, $p = 10, $n = 1 ) {}
function apply_filters( $h, $v, ...$rest ) { return $v; }
function get_current_user_id() { return 1; }
function get_option( $k, $d = false ) { return $d; }
function update_option( $k, $v, $a = null ) { return true; }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t = 0 ) { return true; }
function wp_cache_get( $k, $g = '', $f = false, &$found = null ) { $found = false; return false; }
function wp_cache_set( $k, $v, $g = '', $t = 0 ) { return true; }
function get_post_type( $id ) { return 'attachment'; }
function get_post_mime_type( $id ) { return 128 === (int) $id ? 'image/svg+xml' : 'image/jpeg'; }
function wp_get_attachment_image_url( $id, $s = 'full' ) { return 'https://yazan.local/img/' . $id . '.jpg'; }
function wp_get_attachment_image_src( $id, $s = 'full' ) { return array( 'https://yazan.local/img/' . $id . '.jpg', 1600, 900 ); }
function wp_get_attachment_image_srcset( $id, $s = 'full' ) { return ''; }
function wp_get_attachment_image_sizes( $id, $s = 'full' ) { return ''; }
function get_post_meta( $id, $k, $single = false ) { return ''; }
function wp_timezone_string() { return 'Asia/Aden'; }
function home_url( $p = '/' ) { return 'https://yazan.local' . $p; }
