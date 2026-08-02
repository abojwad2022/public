<?php
/**
 * An ordered set of field definitions.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Component;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The field list for a component (or for a repeater/group row).
 */
final class ComponentSchema {

	/** @var FieldDefinition[] Keyed by field key, insertion-ordered. */
	private $fields = array();

	/**
	 * @param FieldDefinition[] $fields Fields.
	 */
	private function __construct( array $fields ) {
		foreach ( $fields as $field ) {
			$this->fields[ $field->key() ] = $field;
		}
	}

	/**
	 * @param FieldDefinition[] $fields Fields.
	 * @return self
	 */
	public static function of( array $fields ) {
		return new self( $fields );
	}

	/** @return FieldDefinition[] */
	public function fields() {
		return $this->fields;
	}

	/**
	 * @param string $key Field key.
	 * @return FieldDefinition|null
	 */
	public function field( $key ) {
		return isset( $this->fields[ $key ] ) ? $this->fields[ $key ] : null;
	}

	/**
	 * @param string $key Field key.
	 * @return bool
	 */
	public function has( $key ) {
		return isset( $this->fields[ $key ] );
	}

	/**
	 * The full default payload for a fresh section.
	 *
	 * @return array
	 */
	public function defaults() {
		$out = array();
		foreach ( $this->fields as $key => $field ) {
			$out[ $key ] = $field->default_value();
		}
		return $out;
	}

	/**
	 * Every permission slug referenced by any field, deduplicated.
	 *
	 * @return string[]
	 */
	public function permissions() {
		$out = array();
		foreach ( $this->fields as $field ) {
			$perm = $field->permission();
			if ( $perm ) {
				$out[ $perm ] = true;
			}
			if ( $field->children() ) {
				foreach ( $field->children()->permissions() as $child_perm ) {
					$out[ $child_perm ] = true;
				}
			}
		}
		return array_keys( $out );
	}

	/**
	 * @return array<int,array>
	 */
	public function to_array() {
		$out = array();
		foreach ( $this->fields as $field ) {
			$out[] = $field->to_array();
		}
		return $out;
	}
}
