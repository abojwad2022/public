<?php
/**
 * Swatch rail + big image band — the current first section of the homepage.
 *
 * The swatch row itself is data-driven from product categories; leaving `terms` empty keeps the
 * theme's own logic (children of `the-stones`, else top categories) exactly as it is today.
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
 * Swatches component definition.
 */
final class SwatchesComponent {

	/**
	 * @return ComponentDefinition
	 */
	public static function definition() {
		return ComponentDefinition::make(
			array(
				'type'          => 'swatches',
				'label'         => __( 'Stone swatches + banner', 'yazan' ),
				'description'   => __( 'The category rail across the dark bar, followed by the full-bleed banner.', 'yazan' ),
				'icon'          => 'LayoutGrid',
				'group'         => 'commerce',
				'theme_part'    => 'template-parts/home/swatches',
				'max_instances' => 1,
				'supports'      => array( 'media', 'schedule' ),
				'schema'        => ComponentSchema::of(
					array(
						Field::make( 'terms', Type::TERM_IDS, array(
							'label'       => __( 'Categories shown', 'yazan' ),
							'help'        => __( 'Leave empty to keep the automatic order (stone categories by product count).', 'yazan' ),
							'constraints' => array( 'taxonomy' => 'product_cat', 'max_items' => 8 ),
						) ),
						Field::make( 'big_eyebrow', Type::TEXT, array( 'label' => __( 'Banner eyebrow', 'yazan' ), 'translatable' => true ) ),
						Field::make( 'big_title', Type::TEXT, array( 'label' => __( 'Banner title', 'yazan' ), 'translatable' => true ) ),
						Field::make( 'big_cta', Type::TEXT, array( 'label' => __( 'Banner button', 'yazan' ), 'translatable' => true ) ),
						Field::make( 'big_image', Type::MEDIA, array( 'label' => __( 'Banner image', 'yazan' ), 'group' => 'design', 'permission' => 'homepage.design.edit' ) ),
					)
				),
				'bindings'      => array(
					'text'  => array(
						'swatches_big_eyebrow' => 'big_eyebrow',
						'swatches_big_title'   => 'big_title',
						'swatches_big_cta'     => 'big_cta',
					),
					'image' => array(
						'swatches_big_image' => 'big_image',
					),
					'array' => array(
						'yazan_home_swatches' => array(
							'path'  => 'terms',
							'shape' => 'term_cards',
						),
					),
				),
			)
		);
	}
}
