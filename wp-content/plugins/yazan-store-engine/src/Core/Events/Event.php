<?php
/**
 * A domain event: a name and an immutable payload.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Core\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable event value object.
 */
final class Event {

	/**
	 * @param string               $name    Event name, from Events.
	 * @param array<string,mixed>  $payload Event data.
	 */
	public function __construct(
		private string $name,
		private array $payload = array()
	) {}

	public function name(): string {
		return $this->name;
	}

	/** @return array<string,mixed> */
	public function payload(): array {
		return $this->payload;
	}

	/**
	 * @param string $key     Payload key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		return $this->payload[ $key ] ?? $default;
	}
}
