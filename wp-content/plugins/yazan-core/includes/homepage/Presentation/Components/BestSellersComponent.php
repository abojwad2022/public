<?php
/**
 * Best sellers — a WooCommerce product row rendered with the theme's own product cards.
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
 * Best sellers component definition.
 */
final class BestSellersComponent {

	/**
	 * @return ComponentDefinition
	 */
	public static function definition() {
		return ComponentDefinition::make(
			array(
				'type'          => 'bestsellers',
				'label'         => __( 'Product row', 'yazan' ),
				'description'   => __( 'Products chosen by a rule — best sellers, newest, on sale, a category, or a manual list.', 'yazan' ),
				'icon'          => 'Package',
				'group'         => 'commerce',
				'theme_part'    => 'template-parts/home/bestsellers',
				// The template renders `id="bestsellers"`, and duplicate element ids are invalid
				// HTML that breaks in-page anchors. Lifted once the renderer owns the markup.
				'max_instances' => 1,
				'supports'      => array( 'products', 'schedule' ),
				'schema'        => ComponentSchema::of(
					array(
						Field::make( 'heading', Type::TEXT, array( 'label' => __( 'Heading', 'yazan' ), 'translatable' => true ) ),
						Field::make( 'intro', Type::TEXT, array( 'label' => __( 'Intro', 'yazan' ), 'translatable' => true ) ),
						Field::make( 'query', Type::PRODUCT_QUERY, array(
							'label'      => __( 'Which products', 'yazan' ),
							'permission' => 'homepage.products.edit',
							'default'    => array(
								'source'  => 'best_sellers',
								'limit'   => 4,
								'orderby' => 'popularity',
								'order'   => 'DESC',
							),
						) ),
					)
				),
				'bindings'      => array(
					'text'  => array(
						'bestsellers_heading' => 'heading',
						'bestsellers_intro'   => 'intro',
					),
					'array' => array(
						'yazan_home_bestsellers_args' => array(
							'path'  => 'query',
							'shape' => 'query_args',
						),
					),
				),
			)
		);
	}
}
