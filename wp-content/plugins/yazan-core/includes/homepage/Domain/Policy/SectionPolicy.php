<?php
/**
 * Who may act on a section.
 *
 * Two ways to be allowed, and that is the whole design:
 *
 *   1. the module-wide grant  — `homepage.sections.edit`
 *   2. a section-scoped grant — `homepage.section.hero.edit`
 *
 * The second is what makes "Marketing may touch the hero and the newsletter, nothing else" a role
 * you can build by ticking two boxes, instead of a feature someone has to code.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Policy;

use Yazan\Homepage\Domain\Component\ComponentDefinition;
use Yazan\Homepage\Domain\Exception\Forbidden;
use Yazan\Homepage\Domain\Port\AuthorizationPort;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Section-level authorization.
 */
final class SectionPolicy {

	/** @var AuthorizationPort */
	private $auth;

	/**
	 * @param AuthorizationPort $auth Authorization.
	 */
	public function __construct( AuthorizationPort $auth ) {
		$this->auth = $auth;
	}

	/**
	 * @param ComponentDefinition $definition Component.
	 * @return bool
	 */
	public function can_edit( ComponentDefinition $definition ) {
		return $this->allowed( 'homepage.sections.edit', $definition );
	}

	/**
	 * @param ComponentDefinition $definition Component.
	 * @return bool
	 */
	public function can_create( ComponentDefinition $definition ) {
		return $this->allowed( 'homepage.sections.create', $definition );
	}

	/**
	 * @param ComponentDefinition $definition Component.
	 * @return bool
	 */
	public function can_delete( ComponentDefinition $definition ) {
		return $this->allowed( 'homepage.sections.delete', $definition );
	}

	/**
	 * @param ComponentDefinition $definition Component.
	 * @return void
	 * @throws Forbidden When denied.
	 */
	public function require_edit( ComponentDefinition $definition ) {
		if ( ! $this->can_edit( $definition ) ) {
			throw ( new Forbidden( 'You cannot edit this section.' ) )->with_context(
				array(
					'type'       => $definition->type(),
					'permission' => $definition->section_permission() ?: 'homepage.sections.edit',
				)
			);
		}
	}

	/**
	 * @param ComponentDefinition $definition Component.
	 * @return void
	 * @throws Forbidden When denied.
	 */
	public function require_create( ComponentDefinition $definition ) {
		if ( ! $this->can_create( $definition ) ) {
			throw ( new Forbidden( 'You cannot add this section.' ) )->with_context(
				array( 'type' => $definition->type() )
			);
		}
	}

	/**
	 * @param ComponentDefinition $definition Component.
	 * @return void
	 * @throws Forbidden When denied.
	 */
	public function require_delete( ComponentDefinition $definition ) {
		if ( ! $this->can_delete( $definition ) ) {
			throw ( new Forbidden( 'You cannot remove this section.' ) )->with_context(
				array( 'type' => $definition->type() )
			);
		}
	}

	/**
	 * The per-component map the builder uses to decide what to draw.
	 *
	 * @param ComponentDefinition[] $definitions Components.
	 * @return array<string,array{edit:bool,create:bool,delete:bool}>
	 */
	public function map( array $definitions ) {
		$map = array();

		foreach ( $definitions as $definition ) {
			$map[ $definition->type() ] = array(
				'edit'   => $this->can_edit( $definition ),
				'create' => $this->can_create( $definition ),
				'delete' => $this->can_delete( $definition ),
			);
		}

		return $map;
	}

	/**
	 * Module-wide grant, or this component's own scope.
	 *
	 * @param string              $module_permission Module-wide slug.
	 * @param ComponentDefinition $definition        Component.
	 * @return bool
	 */
	private function allowed( $module_permission, ComponentDefinition $definition ) {
		if ( $this->auth->can( $module_permission ) ) {
			return true;
		}

		$scoped = $definition->section_permission();

		return $scoped ? $this->auth->can( $scoped ) : false;
	}
}
