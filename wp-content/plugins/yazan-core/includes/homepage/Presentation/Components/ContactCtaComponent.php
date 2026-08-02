<?php
/**
 * Closing call to action — one line, one or two buttons.
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
 * Contact CTA component definition.
 */
final class ContactCtaComponent {

	/**
	 * @return ComponentDefinition
	 */
	public static function definition() {
		return ComponentDefinition::make(
			array(
				'type'        => 'contact-cta',
				'label'       => __( 'Call to action', 'yazan' ),
				'description' => __( 'A closing band: one line and up to two buttons.', 'yazan' ),
				'icon'        => 'Mail',
				'group'       => 'marketing',
				'renderer'    => 'plugin_template',
				'supports'    => array( 'media', 'schedule' ),
				'schema'      => ComponentSchema::of(
					array(
						Field::make( 'eyebrow', Type::TEXT, array( 'label' => __( 'Eyebrow', 'yazan' ) ) ),
						Field::make( 'heading', Type::TEXT, array( 'label' => __( 'Heading', 'yazan' ), 'required' => true ) ),
						Field::make( 'intro', Type::TEXTAREA, array( 'label' => __( 'Intro', 'yazan' ) ) ),
						Field::make( 'primary', Type::BUTTON, array( 'label' => __( 'Primary button', 'yazan' ) ) ),
						Field::make( 'secondary', Type::BUTTON, array( 'label' => __( 'Secondary button', 'yazan' ) ) ),
						Field::make( 'image', Type::MEDIA, array( 'label' => __( 'Background image', 'yazan' ), 'group' => 'design', 'permission' => 'homepage.design.edit' ) ),
						Field::make( 'tone', Type::SELECT, array(
							'label'       => __( 'Background', 'yazan' ),
							'default'     => 'ink',
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
