<?php
/**
 * Named payload event.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A concrete Event whose name is supplied at construction. Engines dispatch these
 * with a name from {@see Events} and a payload map; subscribers read typed values
 * via get(). Avoids a class-per-event explosion while keeping names centralised.
 */
final class GenericEvent extends Event {

	/**
	 * @param string              $name    Event name (use an Events constant).
	 * @param array<string,mixed> $payload Context.
	 */
	public function __construct( private string $name, array $payload = array() ) {
		parent::__construct( $payload );
	}

	/**
	 * @inheritDoc
	 */
	public function name(): string {
		return $this->name;
	}
}
