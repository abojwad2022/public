<?php
/**
 * The central component registry.
 *
 * Built-in components register on the same hook third-party code uses, so there is no structural
 * difference between "ours" and "theirs" — the guarantee that adding a section never touches the
 * module core.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Component;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * In-memory registry of component definitions.
 */
final class ComponentRegistry {

	/** @var ComponentDefinition[] */
	private $components = array();

	/**
	 * Register (or replace) a component.
	 *
	 * @param ComponentDefinition $definition Definition.
	 * @return void
	 */
	public function register( ComponentDefinition $definition ) {
		$this->components[ $definition->type() ] = $definition;
	}

	/**
	 * @param string $type Component type.
	 * @return bool
	 */
	public function has( $type ) {
		return isset( $this->components[ $type ] );
	}

	/**
	 * @param string $type Component type.
	 * @return ComponentDefinition|null
	 */
	public function get( $type ) {
		return isset( $this->components[ $type ] ) ? $this->components[ $type ] : null;
	}

	/** @return ComponentDefinition[] */
	public function all() {
		return $this->components;
	}

	/** @return string[] */
	public function types() {
		return array_keys( $this->components );
	}

	/**
	 * Every permission slug the registered components reference — section scopes plus every
	 * field-level slug. This is what the permission catalog is built from.
	 *
	 * @return string[]
	 */
	public function permissions() {
		$out = array();

		foreach ( $this->components as $component ) {
			$section = $component->section_permission();
			if ( $section ) {
				$out[ $section ] = true;
			}
			foreach ( $component->schema()->permissions() as $slug ) {
				$out[ $slug ] = true;
			}
		}

		return array_keys( $out );
	}
}
