<?php
/**
 * Featured collections — the four large category cards.
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
 * Collections component definition.
 */
final class CollectionsComponent {

	/**
	 * @return ComponentDefinition
	 */
	public static function definition() {
		return ComponentDefinition::make(
			array(
				'type'        => 'collections',
				'label'       => __( 'Collections', 'yazan' ),
				'description' => __( 'A grid of category cards with image, name and link.', 'yazan' ),
				'icon'        => 'FolderTree',
				'group'       => 'commerce',
				'theme_part'  => 'template-parts/home/collections',
				'supports'    => array( 'schedule' ),
				'schema'      => ComponentSchema::of(
					array(
						Field::make( 'heading', Type::TEXT, array( 'label' => __( 'Heading', 'yazan' ), 'translatable' => true ) ),
						Field::make( 'intro', Type::TEXT, array( 'label' => __( 'Intro', 'yazan' ), 'translatable' => true ) ),
						Field::make( 'terms', Type::TERM_IDS, array(
							'label'       => __( 'Categories', 'yazan' ),
							'help'        => __( 'Leave empty to keep the current automatic selection.', 'yazan' ),
							'constraints' => array( 'taxonomy' => 'product_cat', 'max_items' => 8 ),
						) ),
					)
				),
				'bindings'    => array(
					'text'  => array(
						'collections_heading' => 'heading',
						'collections_intro'   => 'intro',
					),
					'array' => array(
						'yazan_home_collection_cards' => array(
							'path'  => 'terms',
							'shape' => 'term_cards',
						),
					),
				),
			)
		);
	}
}
