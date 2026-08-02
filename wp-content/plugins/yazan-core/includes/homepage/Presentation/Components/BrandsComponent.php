<?php
/**
 * Brands / partners — a quiet row of marks.
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
 * Brands component definition.
 */
final class BrandsComponent {

	/**
	 * @return ComponentDefinition
	 */
	public static function definition() {
		return ComponentDefinition::make(
			array(
				'type'        => 'brands',
				'label'       => __( 'Brands', 'yazan' ),
				'description' => __( 'A row of logos or marks, each optionally linking somewhere.', 'yazan' ),
				'icon'        => 'BadgeCheck',
				'group'       => 'content',
				'renderer'    => 'plugin_template',
				'supports'    => array( 'media', 'schedule' ),
				'schema'      => ComponentSchema::of(
					array(
						Field::make( 'heading', Type::TEXT, array( 'label' => __( 'Heading', 'yazan' ) ) ),
						Field::make( 'items', Type::REPEATER, array(
							'label'       => __( 'Marks', 'yazan' ),
							'constraints' => array( 'max_items' => 12 ),
							'fields'      => array(
								Field::make( 'logo', Type::MEDIA, array( 'label' => __( 'Logo', 'yazan' ) ) ),
								Field::make( 'name', Type::TEXT, array( 'label' => __( 'Name', 'yazan' ), 'help' => __( 'Used as the image description.', 'yazan' ) ) ),
								Field::make( 'url', Type::URL, array( 'label' => __( 'Link', 'yazan' ) ) ),
							),
						) ),
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
