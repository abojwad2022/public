<?php
/**
 * Trust band — the row of assurance icons.
 *
 * The only theme part that had no filter over its items: the six icons and their copy were written
 * into the template. One `yazan_home_trust_items` filter was added there so the whole band becomes
 * editable — the single template change in phase 1 beyond front-page.php.
 *
 * Icons come from the template's own closed set. SVG is never uploaded or stored; the value here
 * is a key that selects trusted, static markup already in the theme.
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
 * Trust component definition.
 */
final class TrustComponent {

	/** The icon keys `template-parts/home/trust.php` defines. */
	const ICONS = array( 'gem', 'silver', 'hand', 'lock', 'ship', 'support' );

	/**
	 * @return ComponentDefinition
	 */
	public static function definition() {
		return ComponentDefinition::make(
			array(
				'type'          => 'trust',
				'label'         => __( 'Trust band', 'yazan' ),
				'description'   => __( 'A row of assurances: authenticity, silver, craft, secure checkout, shipping, support.', 'yazan' ),
				'icon'          => 'ShieldCheck',
				'group'         => 'content',
				'theme_part'    => 'template-parts/home/trust',
				'max_instances' => 1,
				'supports'      => array( 'schedule' ),
				'schema'        => ComponentSchema::of(
					array(
						Field::make( 'heading', Type::TEXT, array( 'label' => __( 'Heading', 'yazan' ), 'translatable' => true ) ),
						Field::make( 'items', Type::REPEATER, array(
							'label'       => __( 'Assurances', 'yazan' ),
							'constraints' => array( 'max_items' => 8 ),
							'fields'      => array(
								Field::make( 'icon', Type::ICON, array( 'label' => __( 'Icon', 'yazan' ), 'default' => 'gem', 'constraints' => array( 'choices' => self::ICONS ) ) ),
								Field::make( 'title', Type::TEXT, array( 'label' => __( 'Title', 'yazan' ), 'translatable' => true ) ),
								Field::make( 'description', Type::TEXT, array( 'label' => __( 'Description', 'yazan' ), 'translatable' => true ) ),
							),
						) ),
					)
				),
				'bindings'      => array(
					'text'  => array(
						'trust_heading' => 'heading',
					),
					'array' => array(
						'yazan_home_trust_items' => array(
							'path'  => 'items',
							'shape' => 'trust_items',
						),
					),
				),
			)
		);
	}
}
