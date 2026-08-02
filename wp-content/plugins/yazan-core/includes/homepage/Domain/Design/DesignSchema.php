<?php
/**
 * The design fields every section gets, on top of its own content.
 *
 * Declared ONCE and shared, rather than copied into seventeen component definitions. Spacing,
 * background and entrance behave the same everywhere; a component that had to redeclare them would
 * eventually declare them slightly differently.
 *
 * Deliberately NOT here: width, column counts and text alignment. Those live inside each theme
 * template's own layout, and a wrapper that tried to override them would be fighting the
 * stylesheet rather than configuring it. Honest limits beat controls that only sometimes work.
 *
 * Spacing is a scale, not a pixel box. "Large" keeps the page's vertical rhythm on every screen;
 * "72px" is right on one and wrong on the rest.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Design;

use Yazan\Homepage\Domain\Component\ComponentSchema;
use Yazan\Homepage\Domain\Component\FieldDefinition as Field;
use Yazan\Homepage\Domain\Component\FieldType as Type;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The shared design schema.
 */
final class DesignSchema {

	/** Spacing steps, mapped to clamp() values in the stylesheet. */
	const SPACING = array( 'inherit', 'none', 'small', 'medium', 'large', 'xlarge' );

	/** Entrance animations. */
	const ANIMATIONS = array( 'none', 'fade', 'rise', 'scale' );

	/** Anyone holding either of these may change spacing and structure. */
	const LAYOUT_PERM = array( 'homepage.layout.edit', 'homepage.design.edit' );

	/** Colours and imagery. */
	const COLOR_PERM = array( 'homepage.colors.edit', 'homepage.design.edit' );

	/** Motion. */
	const MOTION_PERM = array( 'homepage.animations.edit', 'homepage.design.edit' );

	/** @var ComponentSchema|null */
	private static $schema = null;

	/**
	 * @return ComponentSchema
	 */
	public static function schema() {
		if ( null !== self::$schema ) {
			return self::$schema;
		}

		self::$schema = ComponentSchema::of(
			array(
				Field::make( 'space_top', Type::SELECT, array(
					'label'       => __( 'Space above', 'yazan' ),
					'default'     => 'inherit',
					'responsive'  => true,
					'permission'  => self::LAYOUT_PERM,
					'constraints' => array( 'choices' => self::SPACING ),
				) ),
				Field::make( 'space_bottom', Type::SELECT, array(
					'label'       => __( 'Space below', 'yazan' ),
					'default'     => 'inherit',
					'responsive'  => true,
					'permission'  => self::LAYOUT_PERM,
					'constraints' => array( 'choices' => self::SPACING ),
				) ),
				Field::make( 'background', Type::COLOR, array(
					'label'      => __( 'Background colour', 'yazan' ),
					'help'       => __( 'A theme token such as --yz-ink keeps following the colour switcher. A hex value opts out of it.', 'yazan' ),
					'permission' => self::COLOR_PERM,
				) ),
				Field::make( 'background_image', Type::MEDIA, array(
					'label'      => __( 'Background image', 'yazan' ),
					'permission' => self::COLOR_PERM,
				) ),
				Field::make( 'overlay', Type::RANGE, array(
					'label'       => __( 'Image darkening', 'yazan' ),
					'default'     => 40,
					'permission'  => self::COLOR_PERM,
					'condition'   => array( 'field' => 'background_image', 'notEquals' => 0 ),
					'constraints' => array( 'min' => 0, 'max' => 90 ),
				) ),
				Field::make( 'text', Type::COLOR, array(
					'label'      => __( 'Text colour', 'yazan' ),
					'permission' => self::COLOR_PERM,
				) ),
				Field::make( 'animation', Type::SELECT, array(
					'label'       => __( 'Entrance', 'yazan' ),
					'default'     => 'none',
					'help'        => __( 'Skipped entirely for visitors who ask for reduced motion.', 'yazan' ),
					'permission'  => self::MOTION_PERM,
					'constraints' => array( 'choices' => self::ANIMATIONS ),
				) ),
				Field::make( 'animation_delay', Type::NUMBER, array(
					'label'       => __( 'Delay (ms)', 'yazan' ),
					'default'     => 0,
					'permission'  => self::MOTION_PERM,
					'condition'   => array( 'field' => 'animation', 'notEquals' => 'none' ),
					'constraints' => array( 'min' => 0, 'max' => 1000 ),
				) ),
				Field::make( 'animation_duration', Type::NUMBER, array(
					'label'       => __( 'Duration (ms)', 'yazan' ),
					'default'     => 600,
					'permission'  => self::MOTION_PERM,
					'condition'   => array( 'field' => 'animation', 'notEquals' => 'none' ),
					'constraints' => array( 'min' => 150, 'max' => 2000 ),
				) ),
			)
		);

		return self::$schema;
	}

	/**
	 * Every permission slug the design fields reference.
	 *
	 * @return string[]
	 */
	public static function permissions() {
		return self::schema()->permissions();
	}
}
