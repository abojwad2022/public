<?php
require __DIR__ . '/wp-load.php';
header( 'Content-Type: text/plain' );
echo "is_ssl(): " . ( is_ssl() ? 'true' : 'false' ) . "\n";
echo "home_url(): " . home_url( '/' ) . "\n";
foreach ( array( 'HTTP_HOST', 'HTTP_X_ORIGINAL_HOST', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_SSL', 'HTTPS', 'SERVER_PORT', 'REQUEST_SCHEME' ) as $k ) {
	echo "$k: " . ( isset( $_SERVER[ $k ] ) ? $_SERVER[ $k ] : '(unset)' ) . "\n";
}
