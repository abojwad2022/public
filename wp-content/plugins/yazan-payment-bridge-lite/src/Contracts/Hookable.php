<?php
/**
 * Declarative WordPress hook registration contract.
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

namespace Yazan\PaymentBridge\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A class that wants WordPress hooks declares them instead of calling
 * add_action()/add_filter() itself, so registration is inspectable and testable.
 */
interface Hookable {

	/**
	 * Hook declarations.
	 *
	 * Each entry: array{
	 *   type: 'action'|'filter',
	 *   hook: string,
	 *   method: string,
	 *   priority?: int,
	 *   args?: int
	 * }
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function hooks(): array;
}
