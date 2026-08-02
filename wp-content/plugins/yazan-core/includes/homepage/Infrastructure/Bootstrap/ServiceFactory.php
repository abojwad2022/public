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

use Yazan\Homepage\Application\Handler\CreateSectionHandler;
use Yazan\Homepage\Application\Handler\DeleteSectionHandler;
use Yazan\Homepage\Application\Handler\DocumentsHandler;
use Yazan\Homepage\Application\Handler\DuplicateSectionHandler;
use Yazan\Homepage\Application\Handler\ImportHandler;
use Yazan\Homepage\Application\Handler\RevertPublishHandler;
use Yazan\Homepage\Application\Handler\RollbackHandler;
use Yazan\Homepage\Application\Handler\PublishHandler;
use Yazan\Homepage\Application\Handler\ReorderSectionsHandler;
use Yazan\Homepage\Application\Handler\SaveDraftHandler;
use Yazan\Homepage\Application\Handler\TemplateHandler;
use Yazan\Homepage\Application\Handler\ToggleSectionHandler;
use Yazan\Homepage\Application\Handler\UpdateSectionHandler;
use Yazan\Homepage\Application\Listener\AuditRecorder;
use Yazan\Homepage\Application\Listener\CacheInvalidator;
use Yazan\Homepage\Application\Service\DocumentValidator;
use Yazan\Homepage\Application\Service\FieldSanitizer;
use Yazan\Homepage\Application\Service\PermissionFilter;
use Yazan\Homepage\Application\Service\ImportExportService;
use Yazan\Homepage\Application\Service\SectionFactory;
use Yazan\Homepage\Application\Query\GetEditorDocument;
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
use Yazan\Homepage\Infrastructure\Persistence\WpdbTemplateRepository;
use Yazan\Homepage\Infrastructure\Adapter\WpPublishScheduler;
use Yazan\Homepage\Infrastructure\Woo\ProductQueryAdapter;

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

	/** @return ProductQueryAdapter */
	public static function product_query() {
		return self::once( 'product_query', static function () {
			return new ProductQueryAdapter();
		} );
	}

	/** @return WpPublishScheduler */
	public static function publish_scheduler() {
		return self::once( 'publish_scheduler', static function () {
			return new WpPublishScheduler();
		} );
	}

	/** @return \Yazan\Homepage\Infrastructure\Persistence\ExperimentStore */
	public static function experiments() {
		return self::once( 'experiments', static function () {
			return new \Yazan\Homepage\Infrastructure\Persistence\ExperimentStore();
		} );
	}

	/* ------------------------------------------------------------ use cases */

	/**
	 * The five constructor arguments every write handler shares.
	 *
	 * @return array
	 */
	private static function handler_deps() {
		return array( self::documents(), self::auth(), self::components(), self::validator(), self::events() );
	}

	/** @return \Yazan\Homepage\Application\Handler\ExperimentHandler */
	public static function experiment_handler() {
		return self::once( 'h_experiment', static function () {
			return new \Yazan\Homepage\Application\Handler\ExperimentHandler(
				self::documents(),
				self::experiments(),
				self::auth(),
				self::clock()
			);
		} );
	}

	/** @return GetEditorDocument */
	public static function editor_document() {
		return self::once( 'q_editor', static function () {
			return new GetEditorDocument( self::documents(), self::components(), self::auth(), self::permission_filter(), self::revert_publish() );
		} );
	}

	/** @return CreateSectionHandler */
	public static function create_section() {
		return self::once( 'h_create', static function () {
			$deps = self::handler_deps();
			return new CreateSectionHandler( $deps[0], $deps[1], $deps[2], $deps[3], $deps[4], self::section_factory(), self::permission_filter() );
		} );
	}

	/** @return UpdateSectionHandler */
	public static function update_section() {
		return self::once( 'h_update', static function () {
			$deps = self::handler_deps();
			return new UpdateSectionHandler( $deps[0], $deps[1], $deps[2], $deps[3], $deps[4], self::sanitizer(), self::permission_filter() );
		} );
	}

	/** @return DeleteSectionHandler */
	public static function delete_section() {
		return self::once( 'h_delete', static function () {
			$deps = self::handler_deps();
			return new DeleteSectionHandler( $deps[0], $deps[1], $deps[2], $deps[3], $deps[4] );
		} );
	}

	/** @return DuplicateSectionHandler */
	public static function duplicate_section() {
		return self::once( 'h_duplicate', static function () {
			$deps = self::handler_deps();
			return new DuplicateSectionHandler( $deps[0], $deps[1], $deps[2], $deps[3], $deps[4] );
		} );
	}

	/** @return ToggleSectionHandler */
	public static function toggle_section() {
		return self::once( 'h_toggle', static function () {
			$deps = self::handler_deps();
			return new ToggleSectionHandler( $deps[0], $deps[1], $deps[2], $deps[3], $deps[4] );
		} );
	}

	/** @return ReorderSectionsHandler */
	public static function reorder_sections() {
		return self::once( 'h_reorder', static function () {
			$deps = self::handler_deps();
			return new ReorderSectionsHandler( $deps[0], $deps[1], $deps[2], $deps[3], $deps[4] );
		} );
	}

	/** @return SaveDraftHandler */
	public static function save_draft() {
		return self::once( 'h_save', static function () {
			$deps = self::handler_deps();
			return new SaveDraftHandler( $deps[0], $deps[1], $deps[2], $deps[3], $deps[4], self::sanitizer(), self::permission_filter(), self::section_factory() );
		} );
	}

	/** @return PublishHandler */
	public static function publish() {
		return self::once( 'h_publish', static function () {
			$deps = self::handler_deps();
			return new PublishHandler( $deps[0], $deps[1], $deps[2], $deps[3], $deps[4], self::revisions(), self::clock() );
		} );
	}

	/** @return ImportExportService */
	public static function porting() {
		return self::once( 'porting', static function () {
			return new ImportExportService( self::components(), self::media() );
		} );
	}

	/** @return RollbackHandler */
	public static function rollback() {
		return self::once( 'h_rollback', static function () {
			$deps = self::handler_deps();
			return new RollbackHandler( $deps[0], $deps[1], $deps[2], $deps[3], $deps[4], self::revisions() );
		} );
	}

	/** @return RevertPublishHandler */
	public static function revert_publish() {
		return self::once( 'h_revert', static function () {
			$deps = self::handler_deps();
			return new RevertPublishHandler( $deps[0], $deps[1], $deps[2], $deps[3], $deps[4], self::revisions() );
		} );
	}

	/** @return WpdbTemplateRepository */
	public static function template_repository() {
		return self::once( 'templates_repo', static function () {
			return new WpdbTemplateRepository();
		} );
	}

	/** @return TemplateHandler */
	public static function templates() {
		return self::once( 'h_templates', static function () {
			$deps = self::handler_deps();
			return new TemplateHandler( $deps[0], $deps[1], $deps[2], $deps[3], $deps[4], self::template_repository(), self::revisions(), self::section_factory() );
		} );
	}

	/** @return DocumentsHandler */
	public static function documents_handler() {
		return self::once( 'h_documents', static function () {
			$deps = self::handler_deps();
			return new DocumentsHandler( $deps[0], $deps[1], $deps[2], $deps[3], $deps[4] );
		} );
	}

	/** @return ImportHandler */
	public static function import() {
		return self::once( 'h_import', static function () {
			$deps = self::handler_deps();
			return new ImportHandler( $deps[0], $deps[1], $deps[2], $deps[3], $deps[4], self::porting(), self::revisions(), self::section_factory() );
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
