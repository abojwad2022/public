<?php
/**
 * Campaign target eligibility.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Campaigns;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides whether a user is in a campaign's target audience.
 * target = { type: all | tier | role | users, values: [...] }.
 */
final class CampaignEligibility {

	/** Hard cap on a broadcast audience, so a scan can never fan out unbounded. */
	public const AUDIENCE_CAP = 5000;

	/**
	 * @param int                 $user_id User id.
	 * @param array<string,mixed> $target  Target spec.
	 * @return bool
	 */
	public function eligible( int $user_id, array $target ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		$type   = (string) ( $target['type'] ?? 'all' );
		$values = array_map( 'strval', (array) ( $target['values'] ?? array() ) );

		switch ( $type ) {
			case 'all':
				return true;

			case 'tier':
				$tier = (string) get_user_meta( $user_id, '_yzrw_tier', true );
				return in_array( $tier, $values, true );

			case 'role':
				$user = get_userdata( $user_id );
				$roles = $user ? array_map( 'strval', (array) $user->roles ) : array();
				return (bool) array_intersect( $roles, $values );

			case 'users':
				return in_array( (string) $user_id, $values, true );

			default:
				/**
				 * Filter eligibility for a custom target type.
				 *
				 * @param bool   $eligible Default false.
				 * @param int    $user_id  User id.
				 * @param array  $target   Target spec.
				 */
				return (bool) apply_filters( 'yazan_rewards/campaign/eligible', false, $user_id, $target );
		}
	}

	/**
	 * Enumerate the user ids in a campaign's target audience — the reverse of
	 * {@see eligible()} — for broadcasting. Capped at {@see AUDIENCE_CAP}; if the
	 * audience is larger the result is truncated and `yazan_rewards/campaign/audience_capped`
	 * fires so the cap is never silent.
	 *
	 * @param array<string,mixed> $target Target spec.
	 * @return int[] User ids.
	 */
	public function audience( array $target ): array {
		$type   = (string) ( $target['type'] ?? 'all' );
		$values = array_map( 'strval', (array) ( $target['values'] ?? array() ) );
		$cap    = self::AUDIENCE_CAP;

		switch ( $type ) {
			case 'users':
				$ids = array_values( array_unique( array_map( 'intval', $values ) ) );
				$ids = array_values( array_filter( $ids, static fn( $id ) => $id > 0 ) );
				break;

			case 'role':
				$ids = get_users( array( 'role__in' => $values, 'fields' => 'ID', 'number' => $cap + 1 ) );
				break;

			case 'tier':
				$ids = get_users( array(
					'fields'     => 'ID',
					'number'     => $cap + 1,
					'meta_query' => array(
						array( 'key' => '_yzrw_tier', 'value' => $values, 'compare' => 'IN' ),
					),
				) );
				break;

			case 'all':
				$ids = get_users( array( 'role' => 'customer', 'fields' => 'ID', 'number' => $cap + 1 ) );
				break;

			default:
				/**
				 * Filter a custom target type's audience (user ids).
				 *
				 * @param int[] $ids    Default empty.
				 * @param array $target Target spec.
				 */
				$ids = (array) apply_filters( 'yazan_rewards/campaign/audience', array(), $target );
				break;
		}

		$ids = array_map( 'intval', (array) $ids );
		if ( count( $ids ) > $cap ) {
			$total = count( $ids );
			$ids   = array_slice( $ids, 0, $cap );
			/**
			 * Fires when a broadcast audience is truncated to the cap (not silent).
			 *
			 * @param string $type  Target type.
			 * @param int    $total Full audience size.
			 * @param int    $cap   Applied cap.
			 */
			do_action( 'yazan_rewards/campaign/audience_capped', $type, $total, $cap );
		}
		return $ids;
	}
}
