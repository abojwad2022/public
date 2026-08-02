<?php
/**
 * Minimal service container.
 *
 * No autowiring and no reflection, on purpose: every binding is an explicit factory, so the
 * dependency graph is readable by grepping one file (StoreProvider) rather than by tracing
 * constructor signatures. Mirrors the container proven in yazan-social-rewards.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lazy service container.
 */
final class Container {

	/** @var array<string,callable> */
	private array $factories = array();

	/** @var array<string,bool> */
	private array $shared = array();

	/** @var array<string,mixed> */
	private array $resolved = array();

	/**
	 * Register a factory that produces a NEW instance on every get().
	 *
	 * @param string   $id      Service id, normally a class-string.
	 * @param callable $factory fn(Container $c): mixed
	 * @return void
	 */
	public function bind( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->shared[ $id ], $this->resolved[ $id ] );
	}

	/**
	 * Register a factory resolved once and cached.
	 *
	 * @param string   $id      Service id.
	 * @param callable $factory fn(Container $c): mixed
	 * @return void
	 */
	public function singleton( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		$this->shared[ $id ]    = true;
		unset( $this->resolved[ $id ] );
	}

	/**
	 * Store an already-built object.
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
	 * Resolve a service.
	 *
	 * Throws rather than returning null: a missing binding is a programming error that should
	 * surface at the point of use, not become a null-check three frames away.
	 *
	 * @param string $id Service id.
	 * @return mixed
	 * @throws \RuntimeException When the id is not registered.
	 */
	public function get( string $id ) {
		if ( array_key_exists( $id, $this->resolved ) ) {
			return $this->resolved[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new \RuntimeException( sprintf( 'Yazan Stores: service "%s" is not registered.', $id ) );
		}

		$object = ( $this->factories[ $id ] )( $this );

		if ( ! empty( $this->shared[ $id ] ) ) {
			$this->resolved[ $id ] = $object;
		}

		return $object;
	}

	/**
	 * Is this service registered?
	 *
	 * @param string $id Service id.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] ) || array_key_exists( $id, $this->resolved );
	}
}
