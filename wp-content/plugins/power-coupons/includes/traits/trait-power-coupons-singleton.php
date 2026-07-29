<?php
/**
 * Singleton Trait
 *
 * Provides singleton pattern implementation for all plugin classes.
 * Eliminates duplicate singleton code across multiple classes.
 *
 * @package Power_Coupons
 * @since 1.0.0
 */

namespace Power_Coupons\Includes\Traits;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait Power_Coupons_Singleton
 *
 * @since 1.0.0
 */
trait Power_Coupons_Singleton {

	/**
	 * Instance storage
	 *
	 * @var array<string, static>
	 */
	private static $instances = array();

	/**
	 * Get singleton instance
	 *
	 * @return static
	 */
	public static function get_instance() {
		$class = static::class;

		if ( ! isset( self::$instances[ $class ] ) ) {
			// @phpstan-ignore-next-line - Safe usage of new static() in singleton pattern
			self::$instances[ $class ] = new static();
		}

		/**
		 * Return type assertion.
		 *
		 * @var static
		 */
		return self::$instances[ $class ];
	}

	/**
	 * Protected constructor to prevent direct instantiation
	 */
	protected function __construct() {
		// Classes using this trait can override if needed.
	}

	/**
	 * Prevent cloning of singleton instance
	 *
	 * @return void
	 */
	private function __clone() {
		// Prevent cloning.
	}

	/**
	 * Prevent unserialization of singleton instance
	 *
	 * @return void
	 * @throws \Exception When attempting to unserialize singleton.
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton' );
	}
}
