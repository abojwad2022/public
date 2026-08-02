<?php
/**
 * Newsletter band — the email capture above the footer.
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
 * Newsletter component definition.
 */
final class NewsletterComponent {

	/**
	 * @return ComponentDefinition
	 */
	public static function definition() {
		return ComponentDefinition::make(
			array(
				'type'             => 'newsletter',
				'label'            => __( 'Newsletter', 'yazan' ),
				'description'      => __( 'Email capture band. The form itself comes from the theme.', 'yazan' ),
				'icon'             => 'Mail',
				'group'            => 'marketing',
				'theme_part'       => 'template-parts/home/newsletter',
				'max_instances'    => 1,
				'supports'         => array( 'schedule' ),
				'permission_scope' => 'newsletter',
				'schema'           => ComponentSchema::of(
					array(
						Field::make( 'eyebrow', Type::TEXT, array( 'label' => __( 'Eyebrow', 'yazan' ), 'translatable' => true ) ),
						Field::make( 'heading', Type::TEXT, array( 'label' => __( 'Heading', 'yazan' ), 'required' => true, 'translatable' => true ) ),
						Field::make( 'intro', Type::TEXT, array( 'label' => __( 'Intro', 'yazan' ), 'translatable' => true ) ),
						Field::make( 'cta_label', Type::TEXT, array( 'label' => __( 'Button label', 'yazan' ), 'translatable' => true ) ),
					)
				),
				'bindings'         => array(
					'text' => array(
						'newsletter_eyebrow' => 'eyebrow',
						'newsletter_heading' => 'heading',
						'newsletter_intro'   => 'intro',
						'newsletter_cta'     => 'cta_label',
					),
				),
			)
		);
	}
}
