<?php
/**
 * An object that declares its WordPress hooks as data.
 *
 * Declarative rather than imperative so a reader can see every hook an object attaches without
 * following calls, and so tests can assert the wiring without firing it.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Core\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Declares hooks for HookLoader to wire.
 */
interface Hookable {

	/**
	 * Each entry:
	 *   [ 'type' => 'action'|'filter', 'hook' => string, 'method' => string,
	 *     'priority' => int (default 10), 'args' => int (default 1) ]
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function hooks(): array;
}
