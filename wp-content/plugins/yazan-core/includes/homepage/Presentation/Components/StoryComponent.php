<?php
/**
 * Brand story — the dark heritage band with an image and a link.
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
 * Story component definition.
 */
final class StoryComponent {

	/**
	 * @return ComponentDefinition
	 */
	public static function definition() {
		return ComponentDefinition::make(
			array(
				'type'          => 'story',
				'label'         => __( 'Brand story', 'yazan' ),
				'description'   => __( 'Heritage band: image on one side, headline and paragraph on the other.', 'yazan' ),
				'icon'          => 'BookOpen',
				'group'         => 'content',
				'theme_part'    => 'template-parts/home/story',
				'max_instances' => 1,
				'supports'      => array( 'media', 'schedule' ),
				'schema'        => ComponentSchema::of(
					array(
						Field::make( 'heading', Type::TEXT, array( 'label' => __( 'Heading', 'yazan' ), 'required' => true, 'translatable' => true ) ),
						Field::make( 'body', Type::TEXTAREA, array( 'label' => __( 'Body', 'yazan' ), 'translatable' => true ) ),
						Field::make( 'cta_label', Type::TEXT, array( 'label' => __( 'Button label', 'yazan' ), 'translatable' => true ) ),
						Field::make( 'cta_url', Type::URL, array( 'label' => __( 'Button link', 'yazan' ) ) ),
						Field::make( 'image', Type::MEDIA, array( 'label' => __( 'Image', 'yazan' ), 'group' => 'design', 'permission' => 'homepage.design.edit' ) ),
					)
				),
				'bindings'      => array(
					'text'  => array(
						'story_heading' => 'heading',
						'story_body'    => 'body',
						'story_cta'     => 'cta_label',
						'story_cta_url' => 'cta_url',
					),
					'image' => array(
						'story_image' => 'image',
					),
				),
			)
		);
	}
}
