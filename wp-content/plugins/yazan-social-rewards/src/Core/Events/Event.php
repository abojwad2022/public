<?php
/**
 * Base domain event.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable value object carried on the EventBus. Concrete events extend this
 * and expose typed getters; the base holds an arbitrary payload map for
 * forward-compatibility and third-party listeners.
 */
abstract class Event {

	/**
	 * Arbitrary contextual payload.
	 *
	 * @var array<string,mixed>
	 */
	protected array $payload;

	/**
	 * @param array<string,mixed> $payload Context.
	 */
	public function __construct( array $payload = array() ) {
		$this->payload = $payload;
	}

	/**
	 * The event name used for dispatch/subscription. Defaults to the short class
	 * name in snake_case; concrete events may override with a stable constant.
	 *
	 * @return string
	 */
	public function name(): string {
		$short = ( new \ReflectionClass( $this ) )->getShortName();
		return strtolower( (string) preg_replace( '/(?<!^)[A-Z]/', '_$0', $short ) );
	}

	/**
	 * Full payload.
	 *
	 * @return array<string,mixed>
	 */
	public function payload(): array {
		return $this->payload;
	}

	/**
	 * Read a single payload value.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Default when absent.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		return $this->payload[ $key ] ?? $default;
	}
}
