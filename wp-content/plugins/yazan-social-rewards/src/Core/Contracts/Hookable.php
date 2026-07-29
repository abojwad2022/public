<?php
/**
 * Hookable contract.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An object that declares its WordPress hooks instead of calling add_action()
 * imperatively. The {@see \Yazan\Rewards\Core\Hooks\HookLoader} reads these and
 * wires them, which keeps hook registration declarative and unit-testable.
 */
interface Hookable {

	/**
	 * Return the hooks this object binds.
	 *
	 * Each entry:
	 *   [ 'type' => 'action'|'filter', 'hook' => string, 'method' => string,
	 *     'priority' => int (default 10), 'args' => int (default 1) ]
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function hooks(): array;
}
