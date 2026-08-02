<?php
/**
 * Hero slider — unlimited slides, each with its own art, buttons and display window.
 *
 * The theme's single-slide hero stays where it is. This is a separate component rather than an
 * extra "slides" field on that one, because turning a section that renders one headline into a
 * section that renders eight is a different template, a different LCP story and a different
 * accessibility contract — pretending otherwise would mean one component quietly behaving as two.
 *
 * A slide's schedule is declared to the render pipeline through `schedule_paths`, so the page
 * cache is purged the moment a slide is due to appear or expire. Without that, a scheduled slide
 * shows up whenever the cache happens to lapse, which is not a schedule.
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
 * Hero slider component definition.
 */
final class HeroSliderComponent {

	/**
	 * @return ComponentDefinition
	 */
	public static function definition() {
		return ComponentDefinition::make(
			array(
				'type'           => 'hero-slider',
				'label'          => __( 'Hero slider', 'yazan' ),
				'description'    => __( 'Full-screen slides with their own images, buttons and display windows.', 'yazan' ),
				'icon'           => 'Images',
				'group'          => 'content',
				'renderer'       => 'plugin_template',
				'max_instances'  => 1,
				'supports'       => array( 'media', 'schedule' ),
				'schedule_paths' => array( 'slides.*.window' ),
				'schema'         => ComponentSchema::of(
					array(
						Field::make( 'slides', Type::REPEATER, array(
							'label'       => __( 'Slides', 'yazan' ),
							'constraints' => array( 'max_items' => 8 ),
							'fields'      => array(
								Field::make( 'eyebrow', Type::TEXT, array( 'label' => __( 'Eyebrow', 'yazan' ) ) ),
								Field::make( 'title', Type::TEXT, array( 'label' => __( 'Title', 'yazan' ), 'constraints' => array( 'max_length' => 80 ) ) ),
								Field::make( 'subtitle', Type::TEXT, array( 'label' => __( 'Subtitle', 'yazan' ), 'constraints' => array( 'max_length' => 80 ) ) ),
								Field::make( 'description', Type::TEXTAREA, array( 'label' => __( 'Description', 'yazan' ) ) ),
								// Responsive: a phone gets a portrait crop instead of a wide one
								// scaled down to a smear.
								Field::make( 'image', Type::MEDIA, array(
									'label'      => __( 'Image', 'yazan' ),
									'responsive' => true,
									'group'      => 'design',
									'permission' => 'homepage.design.edit',
								) ),
								Field::make( 'overlay', Type::RANGE, array(
									'label'       => __( 'Overlay strength', 'yazan' ),
									'default'     => 45,
									'group'       => 'design',
									'permission'  => 'homepage.design.edit',
									'constraints' => array( 'min' => 0, 'max' => 90 ),
								) ),
								Field::make( 'align', Type::SELECT, array(
									'label'       => __( 'Text position', 'yazan' ),
									'default'     => 'start',
									'group'       => 'design',
									'permission'  => 'homepage.design.edit',
									'constraints' => array( 'choices' => array( 'start', 'center', 'end' ) ),
								) ),
								Field::make( 'primary', Type::BUTTON, array( 'label' => __( 'Primary button', 'yazan' ) ) ),
								Field::make( 'secondary', Type::BUTTON, array( 'label' => __( 'Secondary button', 'yazan' ) ) ),
								Field::make( 'window', Type::GROUP, array(
									'label'  => __( 'Show this slide only between', 'yazan' ),
									'help'   => __( 'Leave both empty to show it always.', 'yazan' ),
									'group'  => 'advanced',
									'fields' => array(
										Field::make( 'from', Type::DATETIME, array( 'label' => __( 'From', 'yazan' ) ) ),
										Field::make( 'to', Type::DATETIME, array( 'label' => __( 'Until', 'yazan' ) ) ),
									),
								) ),
							),
						) ),
						Field::make( 'autoplay', Type::TOGGLE, array(
							'label'   => __( 'Advance automatically', 'yazan' ),
							'default' => true,
							'help'    => __( 'Ignored for visitors who ask for reduced motion.', 'yazan' ),
						) ),
						Field::make( 'speed', Type::NUMBER, array(
							'label'       => __( 'Seconds per slide', 'yazan' ),
							'default'     => 6,
							'constraints' => array( 'min' => 3, 'max' => 20 ),
						) ),
					)
				),
			)
		);
	}
}
