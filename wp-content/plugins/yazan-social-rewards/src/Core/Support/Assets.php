<?php
/**
 * Asset URL/version helper.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves plugin asset URLs and cache-busts them with the file mtime, so a
 * rebuild is picked up without bumping a constant. Mirrors the yazan-core
 * dashboard's approach.
 */
final class Assets {

	/**
	 * Absolute URL to a bundled asset (path relative to the plugin root).
	 *
	 * @param string $relative e.g. "assets/css/front.css".
	 * @return string
	 */
	public function url( string $relative ): string {
		return YAZAN_REWARDS_URL . ltrim( $relative, '/' );
	}

	/**
	 * Absolute filesystem path to a bundled asset.
	 *
	 * @param string $relative Relative path.
	 * @return string
	 */
	public function path( string $relative ): string {
		return YAZAN_REWARDS_DIR . ltrim( $relative, '/' );
	}

	/**
	 * A cache-busting version string: file mtime when readable, else the plugin
	 * version.
	 *
	 * @param string $relative Relative path.
	 * @return string
	 */
	public function version( string $relative ): string {
		$path = $this->path( $relative );
		if ( is_readable( $path ) ) {
			$mtime = filemtime( $path );
			if ( false !== $mtime ) {
				return (string) $mtime;
			}
		}
		return YAZAN_REWARDS_VERSION;
	}
}
