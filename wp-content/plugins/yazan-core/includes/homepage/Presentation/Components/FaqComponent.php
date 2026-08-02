<?php
/**
 * FAQ band — questions that open and close, with no JavaScript.
 *
 * Built on <details>/<summary>, so it works before any script runs and stays keyboard-accessible
 * and screen-reader-correct without a single line of ARIA to get wrong.
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
 * FAQ component definition.
 */
final class FaqComponent {

	/**
	 * @return ComponentDefinition
	 */
	public static function definition() {
		return ComponentDefinition::make(
			array(
				'type'        => 'faq',
				'label'       => __( 'Questions', 'yazan' ),
				'description' => __( 'Common questions, each opening to its answer.', 'yazan' ),
				'icon'        => 'CircleHelp',
				'group'       => 'content',
				'renderer'    => 'plugin_template',
				'structured_data' => array( __CLASS__, 'structured_data' ),
				'supports'    => array( 'schedule' ),
				'schema'      => ComponentSchema::of(
					array(
						Field::make( 'heading', Type::TEXT, array( 'label' => __( 'Heading', 'yazan' ) ) ),
						Field::make( 'items', Type::REPEATER, array(
							'label'       => __( 'Questions', 'yazan' ),
							'constraints' => array( 'max_items' => 20 ),
							'fields'      => array(
								Field::make( 'question', Type::TEXT, array( 'label' => __( 'Question', 'yazan' ) ) ),
								Field::make( 'answer', Type::RICHTEXT, array( 'label' => __( 'Answer', 'yazan' ), 'constraints' => array( 'policy' => 'inline' ) ) ),
							),
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

	/**
	 * FAQPage, built from the questions that will actually be on the page.
	 *
	 * The answer is stripped to plain text: schema.org accepts limited HTML, but a stray tag is a
	 * silent invalidation of the whole node, and the visible answer is what matters anyway.
	 *
	 * @param array $content Section content.
	 * @return array|null
	 */
	public static function structured_data( array $content ) {
		$entities = array();

		foreach ( (array) ( $content['items'] ?? array() ) as $item ) {
			$question = trim( (string) ( $item['question'] ?? '' ) );
			$answer   = trim( wp_strip_all_tags( (string) ( $item['answer'] ?? '' ) ) );

			if ( '' === $question || '' === $answer ) {
				// A question with no answer is not a FAQ entry — it is an incomplete one, and
				// shipping it invites a manual action rather than a rich result.
				continue;
			}

			$entities[] = array(
				'@type'          => 'Question',
				'name'           => $question,
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $answer,
				),
			);
		}

		if ( ! $entities ) {
			return null;
		}

		return array(
			'@type'      => 'FAQPage',
			'mainEntity' => $entities,
		);
	}
}
