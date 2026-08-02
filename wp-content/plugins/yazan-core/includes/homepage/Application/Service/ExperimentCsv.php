<?php
/**
 * The A/B run as a spreadsheet.
 *
 * One row per day per arm, and nothing else. No totals row, no rate column, no blank separator
 * lines: the moment a file has a summary in it, somebody sorts it and the summary lands in the
 * middle of the data. A pivot table gives them the total in two clicks and it is always right.
 *
 * Two details that are not decoration:
 *
 *   · A UTF-8 BOM. Excel on Windows reads a BOM-less CSV as the system codepage, which turns an
 *     Arabic layout title into mojibake — and this shop's titles are Arabic.
 *   · Formula neutralisation. A layout titled `=cmd|...` is a live formula the moment the file is
 *     opened in Excel; a leading quote makes it text. The title is operator-supplied, and this
 *     file is meant to be opened on someone's desktop.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Application\Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the export file.
 */
final class ExperimentCsv {

	/** Characters Excel and Sheets treat as the start of a formula. */
	const FORMULA_LEADERS = array( '=', '+', '-', '@', "\t", "\r" );

	/**
	 * @param array $days Output of ExperimentResult::by_day().
	 * @return string CSV text, BOM included.
	 */
	public static function build( array $days ) {
		$lines = array( self::row( array( 'date', 'arm', 'layout', 'views', 'orders', 'revenue' ) ) );

		foreach ( $days as $day ) {
			foreach ( (array) ( $day['arms'] ?? array() ) as $arm => $cell ) {
				$lines[] = self::row(
					array(
						(string) ( $day['date'] ?? '' ),
						(string) $arm,
						(string) ( $cell['label'] ?? $arm ),
						(string) (int) ( $cell['views'] ?? 0 ),
						(string) (int) ( $cell['orders'] ?? 0 ),
						number_format( (float) ( $cell['revenue'] ?? 0 ), 2, '.', '' ),
					)
				);
			}
		}

		// CRLF: the line ending every spreadsheet on every platform reads without being asked.
		return "\xEF\xBB\xBF" . implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * A filename that says what the file is without being opened.
	 *
	 * @param string $control Control document key.
	 * @param string $today   Y-m-d.
	 * @return string
	 */
	public static function filename( $control, $today ) {
		$slug = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $control );

		return 'ab-' . ( '' !== $slug ? $slug : 'homepage' ) . '-' . preg_replace( '/[^0-9-]/', '', (string) $today ) . '.csv';
	}

	/**
	 * @param string[] $cells Cells.
	 * @return string
	 */
	private static function row( array $cells ) {
		$out = array();

		foreach ( $cells as $cell ) {
			$out[] = self::cell( (string) $cell );
		}

		return implode( ',', $out );
	}

	/**
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function cell( $value ) {
		if ( '' !== $value && in_array( $value[0], self::FORMULA_LEADERS, true ) ) {
			$value = "'" . $value;
		}

		// Quote whenever the value could otherwise be misread, and double any quote inside it.
		if ( preg_match( '/[",\r\n]/', $value ) ) {
			return '"' . str_replace( '"', '""', $value ) . '"';
		}

		return $value;
	}
}
