<?php
/**
 * Collection stories — the two full-width parallax panels.
 *
 * The theme part renders exactly two panels from `cstory_1_*` and `cstory_2_*` keys, so the
 * repeater is capped at two and each row binds to its numbered key set. Lifting that cap is a
 * template change, not a schema change — noted rather than hidden.
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
 * Collection stories component definition.
 */
final class CollectionStoriesComponent {

	/**
	 * @return ComponentDefinition
	 */
	public static function definition() {
		return ComponentDefinition::make(
			array(
				'type'          => 'collection-stories',
				'label'         => __( 'Story panels', 'yazan' ),
				'description'   => __( 'Two full-width parallax panels — image on one side, copy on the other.', 'yazan' ),
				'icon'          => 'Images',
				'group'         => 'content',
				'theme_part'    => 'template-parts/home/collection-stories',
				'max_instances' => 1,
				'supports'      => array( 'media', 'schedule' ),
				'schema'        => ComponentSchema::of(
					array(
						Field::make( 'stories', Type::REPEATER, array(
							'label'       => __( 'Panels', 'yazan' ),
							'help'        => __( 'The theme renders exactly two panels.', 'yazan' ),
							'constraints' => array( 'max_items' => 2, 'min_items' => 2 ),
							'fields'      => array(
								Field::make( 'eyebrow', Type::TEXT, array( 'label' => __( 'Eyebrow', 'yazan' ), 'translatable' => true ) ),
								Field::make( 'title', Type::TEXT, array( 'label' => __( 'Title', 'yazan' ), 'translatable' => true ) ),
								Field::make( 'body', Type::TEXTAREA, array( 'label' => __( 'Body', 'yazan' ), 'translatable' => true ) ),
								Field::make( 'cta_label', Type::TEXT, array( 'label' => __( 'Button label', 'yazan' ), 'translatable' => true ) ),
								Field::make( 'url', Type::URL, array( 'label' => __( 'Button link', 'yazan' ) ) ),
								Field::make( 'image', Type::MEDIA, array( 'label' => __( 'Image', 'yazan' ), 'group' => 'design', 'permission' => 'homepage.design.edit' ) ),
							),
						) ),
					)
				),
				'bindings'      => array(
					'text'  => array(
						'cstory_1_eyebrow' => 'stories.0.eyebrow',
						'cstory_1_title'   => 'stories.0.title',
						'cstory_1_body'    => 'stories.0.body',
						'cstory_1_cta'     => 'stories.0.cta_label',
						'cstory_1_url'     => 'stories.0.url',
						'cstory_2_eyebrow' => 'stories.1.eyebrow',
						'cstory_2_title'   => 'stories.1.title',
						'cstory_2_body'    => 'stories.1.body',
						'cstory_2_cta'     => 'stories.1.cta_label',
						'cstory_2_url'     => 'stories.1.url',
					),
					'image' => array(
						'cstory_1_image' => 'stories.0.image',
						'cstory_2_image' => 'stories.1.image',
					),
				),
			)
		);
	}
}
