<?php
/**
 * Invalidates caches when — and only when — the published homepage actually changes.
 *
 * Draft saves deliberately do NOT touch the storefront cache. Autosave runs every couple of
 * seconds while someone types; flushing the page cache on each one would make editing the homepage
 * a denial-of-service attack on the site.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Application\Listener;

use Yazan\Homepage\Domain\Event\DomainEvent;
use Yazan\Homepage\Domain\Port\CachePort;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache listener.
 */
final class CacheInvalidator {

	/** Events that change what a visitor sees. */
	const PUBLIC_EVENTS = array(
		DomainEvent::DOCUMENT_PUBLISHED,
		DomainEvent::PUBLISH_REVERTED,
	);

	/** @var CachePort */
	private $cache;

	/**
	 * @param CachePort $cache Cache.
	 */
	public function __construct( CachePort $cache ) {
		$this->cache = $cache;
	}

	/**
	 * @param DomainEvent $event Event.
	 * @return void
	 */
	public function __invoke( $event ) {
		if ( ! $event instanceof DomainEvent ) {
			return;
		}

		if ( ! in_array( $event->name(), self::PUBLIC_EVENTS, true ) ) {
			return;
		}

		$this->cache->flush();

		// Page caches. Each is a no-op when the plugin is absent, so no capability checks needed.
		do_action( 'litespeed_purge_url', home_url( '/' ) );

		if ( function_exists( 'w3tc_flush_post' ) ) {
			w3tc_flush_post( (int) get_option( 'page_on_front' ) );
		}

		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}

		/**
		 * Fires after the homepage cache has been invalidated.
		 *
		 * @param DomainEvent $event The event that triggered it.
		 */
		do_action( 'yazan_homepage_cache_flushed', $event );
	}
}
