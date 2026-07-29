<?php
/**
 * Lightweight service container (PSR-11 flavoured).
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A minimal dependency-injection container. No external dependencies.
 *
 * - bind():      lazy factory, a new instance every get().
 * - singleton(): lazy factory resolved once and cached.
 * - instance():  store a pre-built object.
 *
 * Services are keyed by an interface name or a short string alias so consumers
 * depend on contracts, never concrete engine classes.
 */
final class Container {

	/**
	 * Factories keyed by id.
	 *
	 * @var array<string,callable>
	 */
	private array $factories = array();

	/**
	 * Which ids are singletons.
	 *
	 * @var array<string,bool>
	 */
	private array $shared = array();

	/**
	 * Resolved instances (singletons + explicit instances).
	 *
	 * @var array<string,mixed>
	 */
	private array $resolved = array();

	/**
	 * Register a transient factory.
	 *
	 * @param string   $id      Service id.
	 * @param callable $factory fn(Container $c): mixed.
	 * @return void
	 */
	public function bind( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->shared[ $id ], $this->resolved[ $id ] );
	}

	/**
	 * Register a shared (singleton) factory.
	 *
	 * @param string   $id      Service id.
	 * @param callable $factory fn(Container $c): mixed.
	 * @return void
	 */
	public function singleton( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		$this->shared[ $id ]    = true;
		unset( $this->resolved[ $id ] );
	}

	/**
	 * Store an already-built object as a shared instance.
	 *
	 * @param string $id       Service id.
	 * @param mixed  $instance Object.
	 * @return void
	 */
	public function instance( string $id, $instance ): void {
		$this->resolved[ $id ] = $instance;
		$this->shared[ $id ]   = true;
	}

	/**
	 * Alias one id to another already-registered id.
	 *
	 * @param string $alias  New id.
	 * @param string $target Existing id.
	 * @return void
	 */
	public function alias( string $alias, string $target ): void {
		$this->singleton(
			$alias,
			static function ( Container $c ) use ( $target ) {
				return $c->get( $target );
			}
		);
	}

	/**
	 * Resolve a service.
	 *
	 * @param string $id Service id.
	 * @return mixed
	 * @throws \RuntimeException When the id is unknown.
	 */
	public function get( string $id ) {
		if ( array_key_exists( $id, $this->resolved ) ) {
			return $this->resolved[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new \RuntimeException( sprintf( 'Yazan Rewards: service "%s" is not registered.', $id ) );
		}

		$object = ( $this->factories[ $id ] )( $this );

		if ( ! empty( $this->shared[ $id ] ) ) {
			$this->resolved[ $id ] = $object;
		}

		return $object;
	}

	/**
	 * Whether an id is registered.
	 *
	 * @param string $id Service id.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] ) || array_key_exists( $id, $this->resolved );
	}
}
