<?php
/**
 * Video band — a poster image that plays on demand.
 *
 * Never autoplays and never embeds a third-party player until asked. A background video autoplaying
 * on a jewellery homepage costs the visitor several megabytes before they have decided they want
 * it, and an embed on load hands a tracker to every visitor whether they press play or not.
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
 * Video component definition.
 */
final class VideoComponent {

	/**
	 * @return ComponentDefinition
	 */
	public static function definition() {
		return ComponentDefinition::make(
			array(
				'type'        => 'video',
				'label'       => __( 'Video', 'yazan' ),
				'description' => __( 'A poster image with a play button. The file loads only when someone presses it.', 'yazan' ),
				'icon'        => 'Image',
				'group'       => 'content',
				'renderer'    => 'plugin_template',
				'structured_data' => array( __CLASS__, 'structured_data' ),
				'supports'    => array( 'media', 'schedule' ),
				'schema'      => ComponentSchema::of(
					array(
						Field::make( 'eyebrow', Type::TEXT, array( 'label' => __( 'Eyebrow', 'yazan' ) ) ),
						Field::make( 'heading', Type::TEXT, array( 'label' => __( 'Heading', 'yazan' ), 'required' => true ) ),
						Field::make( 'intro', Type::TEXTAREA, array( 'label' => __( 'Intro', 'yazan' ) ) ),
						Field::make( 'poster', Type::MEDIA, array( 'label' => __( 'Poster image', 'yazan' ), 'required' => true, 'group' => 'design', 'permission' => 'homepage.design.edit' ) ),
						Field::make( 'video', Type::VIDEO, array( 'label' => __( 'Video file', 'yazan' ), 'help' => __( 'MP4 or WebM from the media library.', 'yazan' ), 'required' => true ) ),
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

	/**
	 * VideoObject.
	 *
	 * `uploadDate` comes from the attachment's own date rather than today's — a schema that claims
	 * every video was uploaded on the day the page was last cached is worse than none.
	 *
	 * @param array $content Section content.
	 * @return array|null
	 */
	public static function structured_data( array $content ) {
		$video  = (int) ( $content['video'] ?? 0 );
		$poster = (int) ( $content['poster'] ?? 0 );
		$name   = trim( (string) ( $content['heading'] ?? '' ) );

		if ( ! $video || '' === $name ) {
			return null;
		}

		$url       = wp_get_attachment_url( $video );
		$thumbnail = $poster ? wp_get_attachment_image_url( $poster, 'full' ) : '';

		if ( ! $url ) {
			return null;
		}

		$node = array(
			'@type'       => 'VideoObject',
			'name'        => $name,
			'description' => trim( (string) ( $content['intro'] ?? '' ) ) ?: $name,
			'contentUrl'  => $url,
			'uploadDate'  => get_post_time( 'c', true, $video ),
		);

		if ( $thumbnail ) {
			$node['thumbnailUrl'] = $thumbnail;
		}

		return $node;
	}
}
