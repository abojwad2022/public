<?php
/**
 * Per-device visibility for a section.
 *
 * Device visibility is decided on the SERVER, not with `display:none`. A hidden section must cost
 * nothing: no query, no render, no bytes. CSS hiding would still pay for all three.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\ValueObject;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable visibility flags.
 */
final class Visibility {

	/** @var bool */
	private $desktop;

	/** @var bool */
	private $tablet;

	/** @var bool */
	private $mobile;

	/**
	 * @param bool $desktop Desktop.
	 * @param bool $tablet  Tablet.
	 * @param bool $mobile  Mobile.
	 */
	private function __construct( $desktop, $tablet, $mobile ) {
		$this->desktop = (bool) $desktop;
		$this->tablet  = (bool) $tablet;
		$this->mobile  = (bool) $mobile;
	}

	/** @return self */
	public static function everywhere() {
		return new self( true, true, true );
	}

	/**
	 * @param array $data Serialised form.
	 * @return self
	 */
	public static function from_array( array $data ) {
		return new self(
			! isset( $data['desktop'] ) || $data['desktop'],
			! isset( $data['tablet'] ) || $data['tablet'],
			! isset( $data['mobile'] ) || $data['mobile']
		);
	}

	/**
	 * @param string $device desktop | tablet | mobile.
	 * @return bool
	 */
	public function on( $device ) {
		switch ( $device ) {
			case 'tablet':
				return $this->tablet;
			case 'mobile':
				return $this->mobile;
			default:
				return $this->desktop;
		}
	}

	/**
	 * Hidden on every device — the section is dead weight and is skipped entirely.
	 *
	 * @return bool
	 */
	public function is_hidden_everywhere() {
		return ! $this->desktop && ! $this->tablet && ! $this->mobile;
	}

	/** @return array */
	public function to_array() {
		return array(
			'desktop' => $this->desktop,
			'tablet'  => $this->tablet,
			'mobile'  => $this->mobile,
		);
	}
}
