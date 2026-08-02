<?php
/**
 * Hero — the full-screen opening band.
 *
 * Present in the theme (`template-parts/home/hero.php`) but NOT in the current front-page order:
 * the swatch rail replaced it. Registering it here makes it addable from the builder without any
 * theme work, which is exactly the point of the component registry.
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
 * Hero component definition.
 */
final class HeroComponent {

	/**
	 * @return ComponentDefinition
	 */
	public static function definition() {
		return ComponentDefinition::make(
			array(
				'type'          => 'hero',
				'label'         => __( 'Hero', 'yazan' ),
				'description'   => __( 'Full-screen opening band with a headline over a background image.', 'yazan' ),
				'icon'          => 'Image',
				'group'         => 'content',
				'theme_part'    => 'template-parts/home/hero',
				'max_instances' => 1,
				'supports'      => array( 'media', 'schedule' ),
				'schema'        => ComponentSchema::of(
					array(
						Field::make( 'eyebrow', Type::TEXT, array( 'label' => __( 'Eyebrow', 'yazan' ), 'translatable' => true, 'constraints' => array( 'max_length' => 90 ) ) ),
						Field::make( 'line_1', Type::TEXT, array( 'label' => __( 'Headline, line 1', 'yazan' ), 'required' => true, 'translatable' => true, 'constraints' => array( 'max_length' => 80 ) ) ),
						Field::make( 'line_2', Type::TEXT, array( 'label' => __( 'Headline, line 2', 'yazan' ), 'translatable' => true, 'constraints' => array( 'max_length' => 80 ) ) ),
						Field::make( 'intro', Type::TEXTAREA, array( 'label' => __( 'Intro', 'yazan' ), 'translatable' => true ) ),
						Field::make( 'cta_label', Type::TEXT, array( 'label' => __( 'Button label', 'yazan' ), 'translatable' => true, 'constraints' => array( 'max_length' => 40 ) ) ),
						Field::make( 'image', Type::MEDIA, array( 'label' => __( 'Background image', 'yazan' ), 'group' => 'design', 'permission' => 'homepage.design.edit' ) ),
					)
				),
				'bindings'      => array(
					'text'  => array(
						'hero_eyebrow' => 'eyebrow',
						'hero_line_1'  => 'line_1',
						'hero_line_2'  => 'line_2',
						'hero_intro'   => 'intro',
						'hero_cta'     => 'cta_label',
					),
					'image' => array(
						'hero_image' => 'image',
					),
				),
			)
		);
	}
}
