<?php
/**
 * Journal — the latest posts.
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
 * Blog component definition.
 */
final class BlogComponent {

	/**
	 * @return ComponentDefinition
	 */
	public static function definition() {
		return ComponentDefinition::make(
			array(
				'type'             => 'blog',
				'label'            => __( 'Journal', 'yazan' ),
				'description'      => __( 'The most recent posts, with their images.', 'yazan' ),
				'icon'             => 'FileText',
				'group'            => 'content',
				'renderer'         => 'plugin_template',
				'max_instances'    => 1,
				'supports'         => array( 'schedule' ),
				'permission_scope' => 'blog',
				'schema'           => ComponentSchema::of(
					array(
						Field::make( 'heading', Type::TEXT, array( 'label' => __( 'Heading', 'yazan' ) ) ),
						Field::make( 'intro', Type::TEXT, array( 'label' => __( 'Intro', 'yazan' ) ) ),
						Field::make( 'count', Type::NUMBER, array(
							'label'       => __( 'How many posts', 'yazan' ),
							'default'     => 3,
							'constraints' => array( 'min' => 1, 'max' => 9 ),
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
