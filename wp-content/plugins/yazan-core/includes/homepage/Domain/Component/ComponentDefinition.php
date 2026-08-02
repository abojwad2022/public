<?php
/**
 * The complete contract of one homepage component.
 *
 * A component is defined once, here, and everything else is derived: its editor, its permissions,
 * its renderer, its defaults. Registering one is the ONLY step needed to add a section type —
 * no core file changes. That is Open/Closed made concrete.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Component;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable component descriptor.
 */
final class ComponentDefinition {

	/** @var array */
	private $spec;

	/** @var ComponentSchema */
	private $schema;

	/**
	 * @param array $spec Component attributes.
	 */
	private function __construct( array $spec ) {
		$this->spec   = $spec;
		$this->schema = isset( $spec['schema'] ) ? $spec['schema'] : ComponentSchema::of( array() );
	}

	/**
	 * @param array $spec type, label, icon, group, schema, theme_part, renderer, supports,
	 *                    max_instances, permission_scope, assets, description.
	 * @return self
	 */
	public static function make( array $spec ) {
		if ( empty( $spec['type'] ) ) {
			throw new \InvalidArgumentException( 'A Yazan homepage component needs a type.' );
		}
		return new self( $spec );
	}

	/** @return string */
	public function type() {
		return (string) $this->spec['type'];
	}

	/** @return string */
	public function label() {
		return isset( $this->spec['label'] ) ? (string) $this->spec['label'] : $this->type();
	}

	/** @return string */
	public function description() {
		return isset( $this->spec['description'] ) ? (string) $this->spec['description'] : '';
	}

	/** @return string Lucide icon name used by the builder. */
	public function icon() {
		return isset( $this->spec['icon'] ) ? (string) $this->spec['icon'] : 'Square';
	}

	/** @return string Palette group: content | commerce | social | marketing. */
	public function group() {
		return isset( $this->spec['group'] ) ? (string) $this->spec['group'] : 'content';
	}

	/** @return ComponentSchema */
	public function schema() {
		return $this->schema;
	}

	/**
	 * Theme template part rendering this component, without the `.php`.
	 *
	 * Null means the component has no theme template and must be rendered by the plugin.
	 *
	 * @return string|null
	 */
	public function theme_part() {
		return isset( $this->spec['theme_part'] ) ? (string) $this->spec['theme_part'] : null;
	}

	/** @return string Renderer class alias. */
	public function renderer() {
		return isset( $this->spec['renderer'] ) ? (string) $this->spec['renderer'] : 'theme_part';
	}

	/**
	 * Capability flags: media, layout, style, animation, products, schedule.
	 *
	 * @return string[]
	 */
	public function supports() {
		return isset( $this->spec['supports'] ) ? (array) $this->spec['supports'] : array();
	}

	/**
	 * @param string $feature Feature name.
	 * @return bool
	 */
	public function supports_feature( $feature ) {
		return in_array( $feature, $this->supports(), true );
	}

	/** @return int 0 = unlimited. */
	public function max_instances() {
		return isset( $this->spec['max_instances'] ) ? (int) $this->spec['max_instances'] : 0;
	}

	/**
	 * Scope used to build the optional per-section permission `homepage.section.{scope}.edit`.
	 *
	 * @return string|null
	 */
	public function permission_scope() {
		if ( array_key_exists( 'permission_scope', $this->spec ) ) {
			return null === $this->spec['permission_scope'] ? null : (string) $this->spec['permission_scope'];
		}
		return $this->type();
	}

	/**
	 * The section-scoped edit permission, or null when the component opts out.
	 *
	 * @return string|null
	 */
	public function section_permission() {
		$scope = $this->permission_scope();
		return $scope ? 'homepage.section.' . $scope . '.edit' : null;
	}

	/**
	 * How this component's fields map onto the theme's existing content hooks.
	 *
	 *   text  => [ 'hero_line_1' => 'line_1' ]                     yazan_home_text_{key}
	 *   image => [ 'hero_image'  => 'image' ]                      yazan_home_image_{key}
	 *   array => [ 'yazan_home_reviews' => [ path, shape ] ]       whole-array filters
	 *
	 * Paths may be dotted (`stories.0.title`) for components whose template reads numbered keys.
	 *
	 * @return array{text:array<string,string>,image:array<string,string>,array:array<string,array>}
	 */
	public function bindings() {
		$bindings = isset( $this->spec['bindings'] ) ? (array) $this->spec['bindings'] : array();

		return array(
			'text'  => isset( $bindings['text'] ) ? (array) $bindings['text'] : array(),
			'image' => isset( $bindings['image'] ) ? (array) $bindings['image'] : array(),
			'array' => isset( $bindings['array'] ) ? (array) $bindings['array'] : array(),
		);
	}

	/**
	 * A callable that turns this component's content into a schema.org node, or null.
	 *
	 * Declared by the component rather than switched on centrally, so a section added later can
	 * describe itself without the renderer learning about it.
	 *
	 * @return callable|null
	 */
	public function structured_data() {
		return isset( $this->spec['structured_data'] ) ? $this->spec['structured_data'] : null;
	}

	/**
	 * Dotted paths inside the content that hold their own `{from,to}` window.
	 *
	 * A hero whose individual slides are scheduled needs the page cache purged at each slide's
	 * boundary, not only at the section's. Declaring the paths here keeps RenderPipeline generic:
	 * it never has to know what a "slide" is.
	 *
	 * `*` matches every index of a repeater.
	 *
	 * @return string[]
	 */
	public function schedule_paths() {
		return isset( $this->spec['schedule_paths'] ) ? (array) $this->spec['schedule_paths'] : array();
	}

	/**
	 * Assets loaded only when a section of this type actually renders.
	 *
	 * @return array{css:string[],js:string[]}
	 */
	public function assets() {
		$assets = isset( $this->spec['assets'] ) ? (array) $this->spec['assets'] : array();
		return array(
			'css' => isset( $assets['css'] ) ? (array) $assets['css'] : array(),
			'js'  => isset( $assets['js'] ) ? (array) $assets['js'] : array(),
		);
	}

	/**
	 * Content payload for a freshly created section.
	 *
	 * @return array
	 */
	public function defaults() {
		$defaults = $this->schema->defaults();

		if ( isset( $this->spec['defaults'] ) && is_array( $this->spec['defaults'] ) ) {
			foreach ( $this->spec['defaults'] as $key => $value ) {
				if ( $this->schema->has( $key ) ) {
					$defaults[ $key ] = $value;
				}
			}
		}

		return $defaults;
	}

	/**
	 * Serialised for GET /homepage/components.
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'type'          => $this->type(),
			'label'         => $this->label(),
			'description'   => $this->description(),
			'icon'          => $this->icon(),
			'group'         => $this->group(),
			'supports'      => $this->supports(),
			'max_instances' => $this->max_instances(),
			'permission'    => $this->section_permission(),
			'theme_part'    => $this->theme_part(),
			'defaults'      => $this->defaults(),
			'fields'        => $this->schema->to_array(),
		);
	}
}
