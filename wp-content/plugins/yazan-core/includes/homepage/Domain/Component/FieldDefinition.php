<?php
/**
 * One field in a component schema.
 *
 * This object is the single source of truth for the field: it drives validation, sanitisation,
 * the generated React control, field-level authorization, revision diffing and import checking.
 * Add a field here and all six follow — that is the whole point of the design.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Component;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable field descriptor.
 */
final class FieldDefinition {

	/** @var string */
	private $key;

	/** @var string */
	private $type;

	/** @var array */
	private $spec;

	/** @var ComponentSchema|null Child schema for group/repeater types. */
	private $children;

	/**
	 * @param string $key  Field key.
	 * @param string $type One of FieldType::*.
	 * @param array  $spec Optional attributes.
	 */
	private function __construct( $key, $type, array $spec ) {
		$this->key  = $key;
		$this->type = $type;
		$this->spec = $spec;

		$this->children = null;
		if ( FieldType::is_container( $type ) && ! empty( $spec['fields'] ) ) {
			$this->children = ComponentSchema::of( $spec['fields'] );
		}
	}

	/**
	 * Build a field.
	 *
	 * @param string $key  Field key (snake_case).
	 * @param string $type FieldType constant.
	 * @param array  $spec label, help, default, required, responsive, translatable, permission,
	 *                     group, condition, constraints, fields.
	 * @return self
	 */
	public static function make( $key, $type, array $spec = array() ) {
		if ( ! in_array( $type, FieldType::all(), true ) ) {
			// A typo here would silently create an unsanitised field, so it fails loudly at boot.
			throw new \InvalidArgumentException( 'Unknown Yazan homepage field type: ' . $type );
		}

		return new self( $key, $type, $spec );
	}

	/** @return string */
	public function key() {
		return $this->key;
	}

	/** @return string */
	public function type() {
		return $this->type;
	}

	/** @return string */
	public function label() {
		return isset( $this->spec['label'] ) ? (string) $this->spec['label'] : $this->key;
	}

	/**
	 * The declared default, or the type's empty value.
	 *
	 * @return mixed
	 */
	public function default_value() {
		if ( array_key_exists( 'default', $this->spec ) ) {
			return $this->spec['default'];
		}
		if ( $this->children && FieldType::GROUP === $this->type ) {
			return $this->children->defaults();
		}
		return FieldType::empty_value( $this->type );
	}

	/**
	 * Permission slug(s) required to WRITE this field. Null = inherits the section's edit permission.
	 *
	 * A string is one requirement; an array is ANY of them. That matters for design fields, which
	 * should be reachable both by a narrow grant (`homepage.colors.edit`) and by the umbrella one
	 * (`homepage.design.edit`) — without the umbrella, granting "can design" would somehow not
	 * include colours.
	 *
	 * @return string|string[]|null
	 */
	public function permission() {
		if ( ! isset( $this->spec['permission'] ) ) {
			return null;
		}

		return is_array( $this->spec['permission'] )
			? array_values( $this->spec['permission'] )
			: (string) $this->spec['permission'];
	}

	/** @return bool */
	public function is_required() {
		return ! empty( $this->spec['required'] );
	}

	/** @return bool */
	public function is_responsive() {
		return ! empty( $this->spec['responsive'] );
	}

	/** @return bool */
	public function is_translatable() {
		return ! empty( $this->spec['translatable'] );
	}

	/** @return string Inspector tab: content | design | advanced. */
	public function group() {
		return isset( $this->spec['group'] ) ? (string) $this->spec['group'] : 'content';
	}

	/**
	 * A constraint value (max_length, min, max, choices, mime, max_items, policy…).
	 *
	 * @param string $name    Constraint name.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function constraint( $name, $default = null ) {
		return isset( $this->spec['constraints'][ $name ] ) ? $this->spec['constraints'][ $name ] : $default;
	}

	/** @return ComponentSchema|null */
	public function children() {
		return $this->children;
	}

	/**
	 * Serialised for the REST catalog the builder renders from.
	 *
	 * @return array
	 */
	public function to_array() {
		$out = array(
			'key'          => $this->key,
			'type'         => $this->type,
			'label'        => $this->label(),
			'help'         => isset( $this->spec['help'] ) ? (string) $this->spec['help'] : '',
			'default'      => $this->default_value(),
			'required'     => $this->is_required(),
			'responsive'   => $this->is_responsive(),
			'translatable' => $this->is_translatable(),
			'group'        => $this->group(),
			'permission'   => $this->permission(),
			'condition'    => isset( $this->spec['condition'] ) ? $this->spec['condition'] : null,
			'constraints'  => isset( $this->spec['constraints'] ) ? $this->spec['constraints'] : array(),
		);

		if ( $this->children ) {
			$out['fields'] = $this->children->to_array();
		}

		return $out;
	}
}
