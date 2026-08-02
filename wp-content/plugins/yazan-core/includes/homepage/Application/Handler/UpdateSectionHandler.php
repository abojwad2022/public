<?php
/**
 * Change one section's content, state, visibility, schedule or label.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Application\Handler;

use Yazan\Homepage\Application\Service\DocumentValidator;
use Yazan\Homepage\Application\Service\FieldSanitizer;
use Yazan\Homepage\Application\Service\PermissionFilter;
use Yazan\Homepage\Domain\Component\ComponentRegistry;
use Yazan\Homepage\Domain\Design\DesignSchema;
use Yazan\Homepage\Domain\Document\DocumentKey;
use Yazan\Homepage\Domain\Port\AuthorizationPort;
use Yazan\Homepage\Domain\Port\EventDispatcherPort;
use Yazan\Homepage\Domain\Port\HomepageRepositoryPort;
use Yazan\Homepage\Domain\Section\SectionId;
use Yazan\Homepage\Domain\ValueObject\Schedule;
use Yazan\Homepage\Domain\ValueObject\Visibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates a section.
 */
final class UpdateSectionHandler extends AbstractHandler {

	/** @var FieldSanitizer */
	private $sanitizer;

	/** @var PermissionFilter */
	private $filter;

	/**
	 * @param HomepageRepositoryPort $documents Repository.
	 * @param AuthorizationPort      $auth      Authorization.
	 * @param ComponentRegistry      $registry  Registry.
	 * @param DocumentValidator      $validator Validator.
	 * @param EventDispatcherPort    $events    Dispatcher.
	 * @param FieldSanitizer         $sanitizer Sanitizer.
	 * @param PermissionFilter       $filter    Field filter.
	 */
	public function __construct(
		HomepageRepositoryPort $documents,
		AuthorizationPort $auth,
		ComponentRegistry $registry,
		DocumentValidator $validator,
		EventDispatcherPort $events,
		FieldSanitizer $sanitizer,
		PermissionFilter $filter
	) {
		parent::__construct( $documents, $auth, $registry, $validator, $events );

		$this->sanitizer = $sanitizer;
		$this->filter    = $filter;
	}

	/**
	 * @param DocumentKey $key     Document key.
	 * @param string      $id      Section uuid.
	 * @param array       $changes content, label, state, visibility, schedule.
	 * @param int|null    $version Expected document version.
	 * @return array
	 */
	public function handle( DocumentKey $key, $id, array $changes, $version = null ) {
		$document   = $this->load( $key, $version );
		$section_id = SectionId::from( $id );
		$section    = $document->sections()->get( $section_id );
		$definition = $this->definition_for( $section );

		$this->policy->require_edit( $definition );

		$blocked = array();

		if ( array_key_exists( 'content', $changes ) ) {
			$current = $section->content();

			// Sanitise against the schema first, THEN filter by permission — so a value the actor
			// may not write is never even considered, whatever shape it arrived in.
			$clean = $this->sanitizer->sanitize( $definition->schema(), (array) $changes['content'], $current );

			list( $allowed, $blocked ) = $this->filter->apply( $definition->schema(), $clean, $current );

			$section = $section->with_content( $allowed );
		}

		if ( array_key_exists( 'design', $changes ) ) {
			// The design payload goes through the SAME sanitiser and the same field-permission
			// filter as content. A separate, looser path for "just styling" is how a colour field
			// ends up being the one place unescaped input reaches a stylesheet.
			$current_design = $section->design();

			$clean_design = $this->sanitizer->sanitize( DesignSchema::schema(), (array) $changes['design'], $current_design );

			list( $allowed_design, $design_blocked ) = $this->filter->apply( DesignSchema::schema(), $clean_design, $current_design );

			$section = $section->with_design( $allowed_design );
			$blocked = array_merge( $blocked, $design_blocked );
		}

		if ( array_key_exists( 'label', $changes ) ) {
			$section = $section->with_label( sanitize_text_field( (string) $changes['label'] ) );
		}

		if ( array_key_exists( 'visibility', $changes ) ) {
			$section = $section->with_visibility( Visibility::from_array( (array) $changes['visibility'] ) );
		}

		if ( array_key_exists( 'schedule', $changes ) ) {
			$section = $section->with_schedule( Schedule::from_array( (array) $changes['schedule'] ) );
		}

		if ( array_key_exists( 'state', $changes ) ) {
			$section = $section->with_state( (string) $changes['state'] );
		}

		$document->replace_section( $section );

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
