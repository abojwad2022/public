<?php
/**
 * Statistics band — a row of numbers with labels.
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
 * Statistics component definition.
 */
final class StatisticsComponent {

	/**
	 * @return ComponentDefinition
	 */
	public static function definition() {
		return ComponentDefinition::make(
			array(
				'type'        => 'statistics',
				'label'       => __( 'Numbers', 'yazan' ),
				'description' => __( 'A row of figures — stones cut, years of craft, countries shipped to.', 'yazan' ),
				'icon'        => 'ChartNoAxesColumn',
				'group'       => 'content',
				'renderer'    => 'plugin_template',
				'supports'    => array( 'schedule' ),
				'schema'      => ComponentSchema::of(
					array(
						Field::make( 'heading', Type::TEXT, array( 'label' => __( 'Heading', 'yazan' ) ) ),
						Field::make( 'items', Type::REPEATER, array(
							'label'       => __( 'Figures', 'yazan' ),
							'constraints' => array( 'max_items' => 6 ),
							'fields'      => array(
								// Text, not a number: "1,200+" and "40+" are the shapes people
								// actually write, and a numeric field would refuse both.
								Field::make( 'value', Type::TEXT, array( 'label' => __( 'Figure', 'yazan' ), 'constraints' => array( 'max_length' => 12 ) ) ),
								Field::make( 'label', Type::TEXT, array( 'label' => __( 'Label', 'yazan' ) ) ),
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
