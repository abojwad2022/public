<?php
/**
 * Turns a section's design payload into scoped CSS.
 *
 * Everything is emitted against one generated class, so a rule written for one section can never
 * reach another — including a second instance of the same component, which a type-based selector
 * would hit as well.
 *
 * Values are re-validated here even though the sanitiser already ran. A stylesheet is executable
 * text: a payload written before a constraint tightened, or restored from an old revision, must
 * not be able to close a declaration and start its own.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Design;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Design payload → CSS.
 */
final class DesignCompiler {

	/** The spacing scale, in the same order as DesignSchema::SPACING. */
	const SCALE = array(
		'none'   => '0rem',
		'small'  => 'clamp(1rem, 2vw, 1.75rem)',
		'medium' => 'clamp(2rem, 4vw, 3.5rem)',
		'large'  => 'clamp(3rem, 6vw, 5.5rem)',
		'xlarge' => 'clamp(4.5rem, 9vw, 8rem)',
	);

	/**
	 * The unique class for a section.
	 *
	 * @param string $section_id Section uuid.
	 * @return string
	 */
	public static function class_name( $section_id ) {
		return 'yzhp-sec--' . substr( preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $section_id ) ), 0, 12 );
	}

	/**
	 * Compile one section's rules. Returns '' when the section has no design of its own.
	 *
	 * @param string $section_id Section uuid.
	 * @param array  $design     Design payload.
	 * @return string
	 */
	public static function compile( $section_id, array $design ) {
		if ( ! $design ) {
			return '';
		}

		$selector = '.' . self::class_name( $section_id );
		$vars     = array();
		$rules    = array();

		foreach ( array( 'space_top' => '--yzhp-pt', 'space_bottom' => '--yzhp-pb' ) as $key => $var ) {
			foreach ( self::responsive( $design[ $key ] ?? null ) as $device => $step ) {
				if ( ! $step || 'inherit' === $step || ! isset( self::SCALE[ $step ] ) ) {
					continue;
				}

				$vars[ $device ][] = $var . ':' . self::SCALE[ $step ];
			}
		}

		$background = self::color( $design['background'] ?? '' );

		if ( $background ) {
			$rules[] = 'background-color:' . $background;
			/*
			 * The theme's own bands paint their own background, which would sit on top of this
			 * one. Clearing the direct child is the only way an override on the wrapper can be
			 * seen — scoped to this one section, so nothing else is affected.
			 */
			$rules[] = '}' . $selector . '>section{background-color:transparent';
		}

		$text = self::color( $design['text'] ?? '' );

		if ( $text ) {
			$rules[] = 'color:' . $text;
		}

		$image = (int) ( $design['background_image'] ?? 0 );

		if ( $image > 0 ) {
			$url = wp_get_attachment_image_url( $image, 'full' );

			if ( $url ) {
				// esc_url_raw plus a quote strip: a URL is the one place a stylesheet can be
				// escaped out of, and belt-and-braces costs nothing here.
				$safe    = str_replace( array( '"', "'", ')', '\\' ), '', esc_url_raw( $url ) );
				$rules[] = 'background-image:url("' . $safe . '")';
				$rules[] = 'background-size:cover';
				$rules[] = 'background-position:center';
				$rules[] = '--yzhp-overlay:' . self::ratio( $design['overlay'] ?? 40 );
			}
		}

		$css = '';

		if ( isset( $vars['desktop'] ) || $rules ) {
			$css .= $selector . '{' . implode( ';', array_merge( $vars['desktop'] ?? array(), $rules ) ) . '}';
		}

		// Narrower breakpoints last, so they win on equal specificity.
		if ( ! empty( $vars['tablet'] ) ) {
			$css .= '@media(max-width:1024px){' . $selector . '{' . implode( ';', $vars['tablet'] ) . '}}';
		}

		if ( ! empty( $vars['mobile'] ) ) {
			$css .= '@media(max-width:600px){' . $selector . '{' . implode( ';', $vars['mobile'] ) . '}}';
		}

		return $css;
	}

	/**
	 * The wrapper attributes for a section.
	 *
	 * @param string $section_id Section uuid.
	 * @param array  $design     Design payload.
	 * @return array{class:string,attributes:string}
	 */
	public static function wrapper( $section_id, array $design ) {
		$classes = array( 'yzhp-sec', self::class_name( $section_id ) );
		$attrs   = '';

		$animation = (string) ( $design['animation'] ?? 'none' );

		if ( in_array( $animation, DesignSchema::ANIMATIONS, true ) && 'none' !== $animation ) {
			$classes[] = 'yzhp-anim';
			$attrs    .= ' data-yzhp-anim="' . esc_attr( $animation ) . '"';

			$delay    = max( 0, min( 1000, (int) ( $design['animation_delay'] ?? 0 ) ) );
			$duration = max( 150, min( 2000, (int) ( $design['animation_duration'] ?? 600 ) ) );

			$attrs .= ' style="--yzhp-anim-delay:' . $delay . 'ms;--yzhp-anim-duration:' . $duration . 'ms"';
		}

		if ( ! empty( $design['background_image'] ) ) {
			$classes[] = 'has-bg-image';
		}

		return array(
			'class'      => implode( ' ', $classes ),
			'attributes' => $attrs,
		);
	}

	/**
	 * Does this design change ANYTHING about the page?
	 *
	 * The module promises that a section nobody styled renders byte-for-byte as the theme renders
	 * it — no wrapper div, nothing for a theme rule like `main > section` to trip over. That
	 * promise was written against an empty design array, and then the editor started sending the
	 * schema's defaults with every save: `space_top: inherit`, `overlay: 40`, `animation: none`.
	 * A full array of defaults is still "nobody styled this", and it has to be read that way here
	 * rather than by the truthiness of the array.
	 *
	 * @param array $design Design payload.
	 * @return bool
	 */
	public static function has_effect( array $design ) {
		if ( ! $design ) {
			return false;
		}

		if ( '' !== self::compile( 'probe', $design ) ) {
			return true;
		}

		$animation = (string) ( $design['animation'] ?? 'none' );

		if ( 'none' !== $animation && in_array( $animation, DesignSchema::ANIMATIONS, true ) ) {
			return true;
		}

		return ! empty( $design['background_image'] );
	}

	/**
	 * @param mixed $value Responsive or flat value.
	 * @return array<string,string>
	 */
	private static function responsive( $value ) {
		if ( ! is_array( $value ) ) {
			return $value ? array( 'desktop' => (string) $value ) : array();
		}

		$out = array();

		foreach ( array( 'desktop', 'tablet', 'mobile' ) as $device ) {
			if ( ! empty( $value[ $device ] ) ) {
				$out[ $device ] = (string) $value[ $device ];
			}
		}

		return $out;
	}

	/**
	 * A theme token or a hex colour. Anything else is dropped.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function color( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '/^--[a-z0-9-]{1,60}$/i', $value ) ) {
			return 'var(' . strtolower( $value ) . ')';
		}

		return preg_match( '/^#([a-f0-9]{3}|[a-f0-9]{6})$/i', $value ) ? $value : '';
	}

	/**
	 * @param mixed $value 0-90.
	 * @return string
	 */
	private static function ratio( $value ) {
		return (string) ( max( 0, min( 90, (int) $value ) ) / 100 );
	}
}
