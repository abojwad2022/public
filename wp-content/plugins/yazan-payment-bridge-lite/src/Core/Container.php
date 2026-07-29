<?php
/**
 * Minimal service container.
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

namespace Yazan\PaymentBridge\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tiny dependency-injection container: enough to wire the Bridge's services
 * without pulling in a framework. Mirrors the container shape already used by
 * yazan-social-rewards so the two plugins read the same way.
 */
final class Container {

	/** @var array<string,callable> Factories keyed by id. */
	private array $factories = array();

	/** @var array<string,mixed> Resolved singleton instances. */
	private array $instances = array();

	/** @var array<string,bool> Ids that should only ever be built once. */
	private array $shared = array();

	/** @var array<string,string> alias => concrete id. */
	private array $aliases = array();

	/**
	 * Register a factory that builds a new instance on every get().
	 *
	 * @param string   $id      Service id (usually a class-string).
	 * @param callable $factory fn( Container $c ): mixed.
	 * @return void
	 */
	public function bind( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->shared[ $id ], $this->instances[ $id ] );
	}

	/**
	 * Register a factory whose result is built once and reused.
	 *
	 * @param string   $id      Service id.
	 * @param callable $factory fn( Container $c ): mixed.
	 * @return void
	 */
	public function singleton( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		$this->shared[ $id ]    = true;
		unset( $this->instances[ $id ] );
	}

	/**
	 * Register an already-built instance.
	 *
	 * @param string $id       Service id.
	 * @param mixed  $instance The object.
	 * @return void
	 */
	public function instance( string $id, $instance ): void {
		$this->instances[ $id ] = $instance;
		$this->shared[ $id ]    = true;
	}

	/**
	 * Point a short name at a concrete id.
	 *
	 * @param string $alias    Short name (e.g. "logger").
	 * @param string $concrete Concrete id (usually a class-string).
	 * @return void
	 */
	public function alias( string $alias, string $concrete ): void {
		$this->aliases[ $alias ] = $concrete;
	}

	/**
	 * Whether an id (or alias) is registered.
	 *
	 * @param string $id Service id or alias.
	 * @return bool
	 */
	public function has( string $id ): bool {
		$id = $this->aliases[ $id ] ?? $id;
		return isset( $this->factories[ $id ] ) || isset( $this->instances[ $id ] );
	}

	/**
	 * Resolve a service.
	 *
	 * @param string $id Service id or alias.
	 * @return mixed
	 * @throws \RuntimeException When the id is unknown.
	 */
	public function get( string $id ) {
		$id = $this->aliases[ $id ] ?? $id;

		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new \RuntimeException( 'Unknown service: ' . $id );
		}

		$object = ( $this->factories[ $id ] )( $this );

		if ( isset( $this->shared[ $id ] ) ) {
			$this->instances[ $id ] = $object;
		}

		return $object;
	}
}
