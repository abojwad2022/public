<?php
/**
 * Composition root.
 *
 * Not a DI container. Constructor injection is used everywhere — which is the part that buys
 * testability — but the wiring is explicit here, because adding a container to a plugin that has
 * never had Composer means a third pattern in a codebase that already has two. The ports make
 * swapping any of this a one-line change if that trade-off ever stops being right.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Infrastructure\Bootstrap;

use Yazan\Homepage\Application\Listener\AuditRecorder;
use Yazan\Homepage\Application\Listener\CacheInvalidator;
use Yazan\Homepage\Application\Service\DocumentValidator;
use Yazan\Homepage\Application\Service\FieldSanitizer;
use Yazan\Homepage\Application\Service\PermissionFilter;
use Yazan\Homepage\Application\Service\SectionFactory;
use Yazan\Homepage\Domain\Component\ComponentRegistry;
use Yazan\Homepage\Infrastructure\Adapter\WpClockAdapter;
use Yazan\Homepage\Infrastructure\Adapter\WpEventDispatcher;
use Yazan\Homepage\Infrastructure\Adapter\WpMediaAdapter;
use Yazan\Homepage\Infrastructure\Adapter\WpObjectCacheAdapter;
use Yazan\Homepage\Infrastructure\Adapter\WpTermQueryAdapter;
use Yazan\Homepage\Infrastructure\Adapter\YazanAuditAdapter;
use Yazan\Homepage\Infrastructure\Adapter\YazanAuthorizationAdapter;
use Yazan\Homepage\Infrastructure\Persistence\WpdbHomepageRepository;
use Yazan\Homepage\Infrastructure\Persistence\WpdbRevisionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Memoised service construction.
 */
final class ServiceFactory {

	/** @var array<string,object> */
	private static $instances = array();

	/**
	 * Memoise one service.
	 *
	 * @param string   $key     Service key.
	 * @param callable $factory Builder.
	 * @return object
	 */
	private static function once( $key, $factory ) {
		if ( ! isset( self::$instances[ $key ] ) ) {
			self::$instances[ $key ] = $factory();
		}

		return self::$instances[ $key ];
	}

	/** @return ComponentRegistry */
	public static function components() {
		return ComponentBootstrap::registry();
	}

	/** @return YazanAuthorizationAdapter */
	public static function auth() {
		return self::once( 'auth', static function () {
			return new YazanAuthorizationAdapter();
		} );
	}

	/** @return YazanAuditAdapter */
	public static function audit() {
		return self::once( 'audit', static function () {
			return new YazanAuditAdapter( self::auth() );
		} );
	}

	/** @return WpObjectCacheAdapter */
	public static function cache() {
		return self::once( 'cache', static function () {
			return new WpObjectCacheAdapter();
		} );
	}

	/** @return WpClockAdapter */
	public static function clock() {
		return self::once( 'clock', static function () {
			return new WpClockAdapter();
		} );
	}

	/** @return WpMediaAdapter */
	public static function media() {
		return self::once( 'media', static function () {
			return new WpMediaAdapter();
		} );
	}

	/** @return WpTermQueryAdapter */
	public static function terms() {
		return self::once( 'terms', static function () {
			return new WpTermQueryAdapter();
		} );
	}

	/** @return WpEventDispatcher */
	public static function events() {
		return self::once( 'events', static function () {
			return new WpEventDispatcher();
		} );
	}

	/** @return WpdbHomepageRepository */
	public static function documents() {
		return self::once( 'documents', static function () {
			return new WpdbHomepageRepository( self::cache() );
		} );
	}

	/** @return WpdbRevisionRepository */
	public static function revisions() {
		return self::once( 'revisions', static function () {
			return new WpdbRevisionRepository();
		} );
	}

	/** @return FieldSanitizer */
	public static function sanitizer() {
		return self::once( 'sanitizer', static function () {
			return new FieldSanitizer( self::media() );
		} );
	}

	/** @return PermissionFilter */
	public static function permission_filter() {
		return self::once( 'permission_filter', static function () {
			return new PermissionFilter( self::auth() );
		} );
	}

	/** @return SectionFactory */
	public static function section_factory() {
		return self::once( 'section_factory', static function () {
			return new SectionFactory( self::components(), self::sanitizer() );
		} );
	}

	/** @return DocumentValidator */
	public static function validator() {
		return self::once( 'validator', static function () {
			return new DocumentValidator( self::components() );
		} );
	}

	/** @return AuditRecorder */
	public static function audit_recorder() {
		return self::once( 'audit_recorder', static function () {
			return new AuditRecorder( self::audit() );
		} );
	}

	/** @return CacheInvalidator */
	public static function cache_invalidator() {
		return self::once( 'cache_invalidator', static function () {
			return new CacheInvalidator( self::cache() );
		} );
	}

	/**
	 * Reset — tests only.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$instances = array();
	}
}
