<?php
/**
 * Yazan Homepage — PSR-4 autoloader for the `Yazan\Homepage` namespace.
 *
 * yazan-core ships without Composer, so the module registers its own loader rather than adding a
 * vendor directory to a plugin that has never had one. The mapping is one-to-one and boring on
 * purpose: `Yazan\Homepage\Domain\Section\Section` → `includes/homepage/Domain/Section/Section.php`.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'Yazan\\Homepage\\';
		$length = strlen( $prefix );

		if ( 0 !== strncmp( $prefix, $class, $length ) ) {
			return;
		}

		$relative = substr( $class, $length );
		$path     = YAZAN_CORE_DIR . 'includes/homepage/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);
