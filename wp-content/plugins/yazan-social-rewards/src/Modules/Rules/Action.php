<?php
/**
 * Rule action value object.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Rules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One action in a rule's ACTIONS list: a type + its parameters. Immutable.
 */
final class Action {

	public const ADD_POINTS        = 'add_points';
	public const REMOVE_POINTS     = 'remove_points';
	public const UPGRADE_LEVEL     = 'upgrade_level';
	public const GIVE_BADGE        = 'give_badge';
	public const CREATE_COUPON     = 'create_coupon';
	public const SEND_NOTIFICATION = 'send_notification';

	/**
	 * @param string              $type   One of the constants above.
	 * @param array<string,mixed> $params Type-specific parameters.
	 */
	public function __construct(
		public string $type,
		public array $params = array()
	) {}

	/**
	 * Build from a stored array ({ type, params } or { type, ...params }).
	 *
	 * @param mixed $raw Raw action entry.
	 * @return Action|null
	 */
	public static function from_array( $raw ): ?Action {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$type = sanitize_key( (string) ( $raw['type'] ?? '' ) );
		if ( '' === $type ) {
			return null;
		}
		$params = isset( $raw['params'] ) && is_array( $raw['params'] ) ? $raw['params'] : $raw;
		unset( $params['type'] );
		return new self( $type, $params );
	}

	/**
	 * A single param with a default.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public function param( string $key, $default = null ) {
		return $this->params[ $key ] ?? $default;
	}
}
