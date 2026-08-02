<?php
/**
 * Replace the draft from a package.
 *
 * Imports into the DRAFT and snapshots what was there first, so an import is reviewable and
 * reversible. It never touches the live page — publishing stays a separate decision.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Application\Handler;

use Yazan\Homepage\Application\Service\DocumentValidator;
use Yazan\Homepage\Application\Service\ImportExportService;
use Yazan\Homepage\Application\Service\SectionFactory;
use Yazan\Homepage\Domain\Component\ComponentRegistry;
use Yazan\Homepage\Domain\Document\DocumentKey;
use Yazan\Homepage\Domain\Document\SectionCollection;
use Yazan\Homepage\Domain\Event\DomainEvent;
use Yazan\Homepage\Domain\Port\AuthorizationPort;
use Yazan\Homepage\Domain\Port\EventDispatcherPort;
use Yazan\Homepage\Domain\Port\HomepageRepositoryPort;
use Yazan\Homepage\Domain\Port\RevisionRepositoryPort;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports a homepage package.
 */
final class ImportHandler extends AbstractHandler {

	/** @var ImportExportService */
	private $porting;

	/** @var RevisionRepositoryPort */
	private $revisions;

	/** @var SectionFactory */
	private $factory;

	/**
	 * @param HomepageRepositoryPort $documents Repository.
	 * @param AuthorizationPort      $auth      Authorization.
	 * @param ComponentRegistry      $registry  Registry.
	 * @param DocumentValidator      $validator Validator.
	 * @param EventDispatcherPort    $events    Dispatcher.
	 * @param ImportExportService    $porting   Package service.
	 * @param RevisionRepositoryPort $revisions Revisions.
	 * @param SectionFactory         $factory   Section factory.
	 */
	public function __construct(
		HomepageRepositoryPort $documents,
		AuthorizationPort $auth,
		ComponentRegistry $registry,
		DocumentValidator $validator,
		EventDispatcherPort $events,
		ImportExportService $porting,
		RevisionRepositoryPort $revisions,
		SectionFactory $factory
	) {
		parent::__construct( $documents, $auth, $registry, $validator, $events );

		$this->porting   = $porting;
		$this->revisions = $revisions;
		$this->factory   = $factory;
	}

	/**
	 * Report what an import would do, changing nothing.
	 *
	 * @param array $package Decoded package.
	 * @return array
	 */
	public function dry_run( array $package ) {
		$this->auth->require_permission( 'homepage.import' );

		return array_merge( array( 'dry_run' => true ), $this->porting->inspect( $package ) );
	}

	/**
	 * @param DocumentKey $key     Document key.
	 * @param array       $package Decoded package.
	 * @param int|null    $version Expected document version.
	 * @return array
	 */
	public function handle( DocumentKey $key, array $package, $version = null ) {
		$this->auth->require_permission( 'homepage.import' );

		// An import can add, remove and reorder everything at once, so it needs the permissions
		// for all three rather than a single "import" key standing in for the lot.
		$this->auth->require_permission( 'homepage.sections.create' );
		$this->auth->require_permission( 'homepage.sections.delete' );
		$this->auth->require_permission( 'homepage.sections.sort' );

		$report = $this->porting->inspect( $package );
		$raw    = $this->porting->sections_from( $package );

		$document = $this->load( $key, $version );

		$this->revisions->append( $document, $this->auth->actor_id(), 'before_import', false );

		$sections = array();

		foreach ( $raw as $entry ) {
			// Rehydrated through the factory: every value is re-sanitised against the schema, so a
			// hand-edited package cannot smuggle a field past the same checks a form goes through.
			$sections[] = $this->factory->rehydrate( (array) $entry );
		}

		// Same reasoning as applying a template: the package report is the record of what came in.
		$document->replace_sections( SectionCollection::of( $sections ), 'import', false );

		$document->record(
			DomainEvent::document(
				DomainEvent::DOCUMENT_IMPORTED,
				$key->value(),
				array(
					'sections'      => count( $sections ),
					'media_dropped' => $report['media_dropped'],
					'source'        => isset( $package['site'] ) ? (string) $package['site'] : '',
				)
			)
		);

		$this->persist( $document );

		return $this->result(
			$document,
			array(
				'imported' => $report,
				'sections' => $document->sections()->to_array(),
			)
		);
	}
}
