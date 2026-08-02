<?php
/**
 * Customer reviews — the testimonial row.
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
 * Reviews component definition.
 */
final class ReviewsComponent {

	/**
	 * @return ComponentDefinition
	 */
	public static function definition() {
		return ComponentDefinition::make(
			array(
				'type'          => 'reviews',
				'label'         => __( 'Testimonials', 'yazan' ),
				'description'   => __( 'Quotes from collectors, with a name and a city.', 'yazan' ),
				'icon'          => 'Quote',
				'group'         => 'social',
				'theme_part'    => 'template-parts/home/reviews',
				'max_instances' => 1,
				'supports'      => array( 'schedule' ),
				'schema'        => ComponentSchema::of(
					array(
						Field::make( 'heading', Type::TEXT, array( 'label' => __( 'Heading', 'yazan' ), 'translatable' => true ) ),
						Field::make( 'reviews', Type::REPEATER, array(
							'label'       => __( 'Quotes', 'yazan' ),
							'constraints' => array( 'max_items' => 9 ),
							'fields'      => array(
								Field::make( 'quote', Type::TEXTAREA, array( 'label' => __( 'Quote', 'yazan' ), 'translatable' => true ) ),
								Field::make( 'name', Type::TEXT, array( 'label' => __( 'Name', 'yazan' ) ) ),
								Field::make( 'city', Type::TEXT, array( 'label' => __( 'City', 'yazan' ) ) ),
							),
						) ),
					)
				),
				'bindings'      => array(
					'text'  => array(
						'reviews_heading' => 'heading',
					),
					'array' => array(
						'yazan_home_reviews' => array(
							'path'  => 'reviews',
							'shape' => 'reviews',
						),
					),
				),
			)
		);
	}
}
