<?php
/**
 * Product carousel — the same product cards, on a rail.
 *
 * A separate component from the grid row rather than a `layout` switch on it, for a concrete
 * reason: the grid row is rendered by the THEME's own template, and this one has to be rendered
 * here. A single component that is drawn by two different renderers depending on a field value is
 * a component that behaves as two, with none of the honesty of being two.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Presentation\Components;

use Yazan\Homepage\Domain\Component\ComponentDefinition;
use Yazan\Homepage\Domain\Component\ComponentSchema;
use Yazan\Homepage\Domain\Component\FieldDefinition as Field;
use Yazan\Homepage\Domain\Component\FieldType as Type;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product carousel component definition.
 */
final class ProductCarouselComponent {

	/**
	 * @return ComponentDefinition
	 */
	public static function definition() {
		return ComponentDefinition::make(
			array(
				'type'        => 'product-carousel',
				'label'       => __( 'Product carousel', 'yazan' ),
				'description' => __( 'A scrollable rail of products. Swipes on touch, scrolls with the keyboard.', 'yazan' ),
				'icon'        => 'Package',
				'group'       => 'commerce',
				'renderer'    => 'plugin_template',
				'supports'    => array( 'products', 'schedule' ),
				'schema'      => ComponentSchema::of(
					array(
						Field::make( 'heading', Type::TEXT, array( 'label' => __( 'Heading', 'yazan' ) ) ),
						Field::make( 'intro', Type::TEXT, array( 'label' => __( 'Intro', 'yazan' ) ) ),
						Field::make( 'query', Type::PRODUCT_QUERY, array(
							'label'      => __( 'Which products', 'yazan' ),
							'permission' => 'homepage.products.edit',
							'default'    => array(
								'source'  => 'latest',
								'limit'   => 12,
								'orderby' => 'date',
								'order'   => 'DESC',
							),
						) ),
						// Responsive so a phone shows one and a half cards — the half is what tells
						// a visitor there is more to the right without needing an arrow.
						Field::make( 'per_view', Type::NUMBER, array(
							'label'       => __( 'Cards visible', 'yazan' ),
							'default'     => 4,
							'responsive'  => true,
							'group'       => 'design',
							'permission'  => 'homepage.layout.edit',
							'constraints' => array( 'min' => 1, 'max' => 6 ),
						) ),
						Field::make( 'button', Type::BUTTON, array( 'label' => __( 'Button', 'yazan' ) ) ),
						Field::make( 'tone', Type::SELECT, array(
							'label'       => __( 'Background', 'yazan' ),
							'default'     => 'ivory',
							'group'       => 'design',
							'permission'  => 'homepage.design.edit',
							'constraints' => array( 'choices' => array( 'ink', 'ivory' ) ),
						) ),
					)
				),
			)
		);
	}
}
