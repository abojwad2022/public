<?php
/**
 * Flash sale — a countdown over a row of discounted products.
 *
 * The countdown has NO date field of its own. It counts down to the section's own schedule end,
 * which is set on the Advanced tab like every other section. One date means three things can never
 * disagree: what the visitor sees ticking, when the server stops rendering the band, and when the
 * page cache is purged.
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
 * Flash sale component definition.
 */
final class FlashSaleComponent {

	/**
	 * @return ComponentDefinition
	 */
	public static function definition() {
		return ComponentDefinition::make(
			array(
				'type'          => 'flash-sale',
				'label'         => __( 'Flash sale', 'yazan' ),
				'description'   => __( 'A countdown over discounted products. Set the end time on the Advanced tab.', 'yazan' ),
				'icon'          => 'Ticket',
				'group'         => 'marketing',
				'renderer'      => 'plugin_template',
				'max_instances' => 1,
				'supports'      => array( 'products', 'schedule' ),
				'schema'        => ComponentSchema::of(
					array(
						Field::make( 'eyebrow', Type::TEXT, array( 'label' => __( 'Eyebrow', 'yazan' ), 'default' => '' ) ),
						Field::make( 'heading', Type::TEXT, array( 'label' => __( 'Heading', 'yazan' ), 'required' => true ) ),
						Field::make( 'intro', Type::TEXT, array( 'label' => __( 'Intro', 'yazan' ) ) ),
						Field::make( 'query', Type::PRODUCT_QUERY, array(
							'label'      => __( 'Which products', 'yazan' ),
							'permission' => 'homepage.products.edit',
							'default'    => array(
								'source'  => 'on_sale',
								'limit'   => 4,
								'orderby' => 'date',
								'order'   => 'DESC',
							),
						) ),
						Field::make( 'button', Type::BUTTON, array( 'label' => __( 'Button', 'yazan' ) ) ),
					)
				),
			)
		);
	}
}
