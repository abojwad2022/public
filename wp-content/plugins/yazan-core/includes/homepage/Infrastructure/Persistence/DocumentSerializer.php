<?php
/**
 * Document ⇄ JSON.
 *
 * Every stored payload carries the schema version that wrote it, so a future format change is a
 * migration rather than a guess about which shape a given row is in.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Infrastructure\Persistence;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JSON encoding for section payloads.
 */
final class DocumentSerializer {

	/** Payload format version. */
	const PAYLOAD_VERSION = 1;

	/**
	 * @param array $sections Section array.
	 * @return string JSON.
	 */
	public static function encode( array $sections ) {
		$json = wp_json_encode(
			array(
				'v'        => self::PAYLOAD_VERSION,
				'sections' => $sections,
			)
		);

		return false === $json ? '' : $json;
	}

	/**
	 * @param string|null $json Stored JSON.
	 * @return array Sections array; empty on anything unreadable.
	 */
	public static function decode( $json ) {
		if ( ! is_string( $json ) || '' === $json ) {
			return array();
		}

		$data = json_decode( $json, true );

		if ( ! is_array( $data ) ) {
			return array();
		}

		// Tolerate a bare array so a hand-edited row is still readable.
		if ( isset( $data['sections'] ) && is_array( $data['sections'] ) ) {
			return $data['sections'];
		}

		return $data;
	}
}
