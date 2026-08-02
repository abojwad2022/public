<?php
/**
 * A reference to a shared section.
 *
 * A global section is stored ONCE and pointed at from as many documents as you like. Editing it
 * changes every page that uses it — which is the whole reason to have one, and also why it is a
 * reference and not a copy. A "shared" section implemented as a copy is a section that silently
 * stops being shared the first time someone edits one of them.
 *
 * This component holds nothing but the pointer. What renders is whatever the shared section is.
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
 * Global-section reference.
 */
final class GlobalComponent {

	/** The type slug, referenced from the render pipeline. */
	const TYPE = 'global';

	/**
	 * @return ComponentDefinition
	 */
	public static function definition() {
		return ComponentDefinition::make(
			array
			(
				'type'             => self::TYPE,
				'label'            => __( 'Shared section', 'yazan' ),
				'description'      => __( 'A section stored once and used on several pages. Editing it changes all of them.', 'yazan' ),
				'icon'             => 'Copy',
				'group'            => 'content',
				// It never renders itself: the pipeline swaps it for what it points at.
				'renderer'         => 'reference',
				// No section-scoped permission of its own — the shared section it points at
				// carries its own component's permission, which is the one that should decide.
				'permission_scope' => null,
				'schema'           => ComponentSchema::of(
					array(
						Field::make( 'ref', Type::NUMBER, array(
							'label'       => __( 'Shared section', 'yazan' ),
							'required'    => true,
							'default'     => 0,
							'constraints' => array( 'min' => 0, 'max' => 999999999 ),
						) ),
					)
				),
			)
		);
	}
}
