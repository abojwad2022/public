<?php
/**
 * Add a section to the draft.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Application\Handler;

use Yazan\Homepage\Application\Service\PermissionFilter;
use Yazan\Homepage\Application\Service\SectionFactory;
use Yazan\Homepage\Domain\Component\ComponentRegistry;
use Yazan\Homepage\Domain\Document\DocumentKey;
use Yazan\Homepage\Domain\Port\AuthorizationPort;
use Yazan\Homepage\Domain\Port\EventDispatcherPort;
use Yazan\Homepage\Domain\Port\HomepageRepositoryPort;
use Yazan\Homepage\Application\Service\DocumentValidator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates a section.
 */
final class CreateSectionHandler extends AbstractHandler {

	/** @var SectionFactory */
	private $factory;

	/** @var PermissionFilter */
	private $filter;

	/**
	 * @param HomepageRepositoryPort $documents Repository.
	 * @param AuthorizationPort      $auth      Authorization.
	 * @param ComponentRegistry      $registry  Registry.
	 * @param DocumentValidator      $validator Validator.
	 * @param EventDispatcherPort    $events    Dispatcher.
	 * @param SectionFactory         $factory   Section factory.
	 * @param PermissionFilter       $filter    Field filter.
	 */
	public function __construct(
		HomepageRepositoryPort $documents,
		AuthorizationPort $auth,
		ComponentRegistry $registry,
		DocumentValidator $validator,
		EventDispatcherPort $events,
		SectionFactory $factory,
		PermissionFilter $filter
	) {
		parent::__construct( $documents, $auth, $registry, $validator, $events );

		$this->factory = $factory;
		$this->filter  = $filter;
	}

	/**
	 * @param DocumentKey $key      Document key.
	 * @param string      $type     Component type.
	 * @param array       $values   Optional initial content.
	 * @param int|null    $position Insert index; null appends.
	 * @param int|null    $version  Expected document version.
	 * @return array
	 */
	public function handle( DocumentKey $key, $type, array $values = array(), $position = null, $version = null ) {
		$definition = $this->definition( $type );

		$this->policy->require_create( $definition );

		$document = $this->load( $key, $version );
		$section  = $this->factory->make( $type, $values );

		// A creator who lacks a field's permission gets the component's default there, never the
		// value they tried to set.
		list( $allowed, $blocked ) = $this->filter->apply( $definition->schema(), $section->content(), $definition->defaults() );

		$section = $section->with_content( $allowed );

		$document->add_section( $section, $position, $definition->max_instances() );

		$this->persist( $document );

		return $this->result(
			$document,
			array(
				'section' => $section->to_array(),
				'blocked' => $blocked,
			)
		);
	}
}
