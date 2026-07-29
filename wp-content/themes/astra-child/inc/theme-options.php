<?php
/**
 * Yazan — theme color switcher (Customizer).
 *
 * Adds an admin option to switch the site's dark base between "Obsidian" (default black) and
 * "Maroon" (the logo background colour). The Maroon variant simply remaps the --yz-ink token on
 * the body, so every dark section, button, badge, announcement bar and footer follows — no
 * duplicated stylesheets.
 *
 * Customize → "YAZAN — Theme Color".
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const YAZAN_MAROON_DEFAULT = '#5C1626'; // Logo background (editable in the Customizer color control).

/**
 * Register the Customizer section, the theme radio, and the maroon shade color control.
 *
 * @param WP_Customize_Manager $wp_customize Customizer manager.
 */
add_action( 'customize_register', 'yazan_customize_theme' );
function yazan_customize_theme( $wp_customize ) {
	$wp_customize->add_section(
		'yazan_theme',
		array(
			'title'    => __( 'YAZAN — Theme Color', 'yazan' ),
			'priority' => 30,
		)
	);

	$wp_customize->add_setting(
		'yazan_theme_variant',
		array(
			'default'           => 'obsidian',
			'sanitize_callback' => 'yazan_sanitize_variant',
			'transport'         => 'refresh',
		)
	);
	$wp_customize->add_control(
		'yazan_theme_variant',
		array(
			'type'        => 'radio',
			'section'     => 'yazan_theme',
			'label'       => __( 'Color theme', 'yazan' ),
			'description' => __( 'Switch the dark sections between Obsidian (black) and Maroon (logo colour).', 'yazan' ),
			'choices'     => array(
				'obsidian' => __( 'Obsidian (black)', 'yazan' ),
				'maroon'   => __( 'Maroon (logo colour)', 'yazan' ),
			),
		)
	);

	$wp_customize->add_setting(
		'yazan_maroon_color',
		array(
			'default'           => YAZAN_MAROON_DEFAULT,
			'sanitize_callback' => 'sanitize_hex_color',
			'transport'         => 'refresh',
		)
	);
	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'yazan_maroon_color',
			array(
				'section'     => 'yazan_theme',
				'label'       => __( 'Maroon shade', 'yazan' ),
				'description' => __( 'Base colour used by the Maroon theme (defaults to the logo background).', 'yazan' ),
			)
		)
	);
}

/**
 * Sanitize the variant choice.
 *
 * @param string $value Raw value.
 * @return string
 */
function yazan_sanitize_variant( $value ) {
	return in_array( $value, array( 'obsidian', 'maroon' ), true ) ? $value : 'obsidian';
}

/**
 * Flag the active variant on <body> so CSS can target it.
 *
 * @param string[] $classes Body classes.
 * @return string[]
 */
add_filter( 'body_class', 'yazan_theme_body_class' );
function yazan_theme_body_class( $classes ) {
	$classes[] = 'yz-theme-' . get_theme_mod( 'yazan_theme_variant', 'obsidian' );
	return $classes;
}

/**
 * When Maroon is active, remap the dark base token to the chosen shade.
 */
add_action( 'wp_head', 'yazan_theme_inline_css', 20 );
function yazan_theme_inline_css() {
	if ( 'maroon' !== get_theme_mod( 'yazan_theme_variant', 'obsidian' ) ) {
		return;
	}
	$color = sanitize_hex_color( get_theme_mod( 'yazan_maroon_color', YAZAN_MAROON_DEFAULT ) );
	if ( ! $color ) {
		$color = YAZAN_MAROON_DEFAULT;
	}
	printf(
		'<style id="yazan-theme-maroon">body.yazan.yz-theme-maroon{--yz-ink:%1$s;}body.yazan.yz-theme-maroon .yz-hero{background:radial-gradient(120%% 90%% at 72%% 28%%,color-mix(in srgb, %1$s 72%%, #fff) 0%%,var(--yz-ink) 60%%);}</style>' . "\n",
		esc_attr( $color )
	);
}
