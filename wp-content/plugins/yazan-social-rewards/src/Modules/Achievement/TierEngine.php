<?php
/**
 * Loyalty tier engine.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Achievement;

use Yazan\Rewards\Core\Contracts\Hookable;
use Yazan\Rewards\Core\Contracts\SubscriberInterface;
use Yazan\Rewards\Core\Events\Event;
use Yazan\Rewards\Core\Events\Events;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Events\GenericEvent;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Core\Support\Money;
use Yazan\Rewards\Modules\Points\PointsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Places each customer in a loyalty tier based on lifetime points earned, caches
 * it in user meta, and grants the tier's earning multiplier via the shared
 * `earn_amount` filter. Recalculates whenever points are credited.
 *
 * Implements both Hookable (the multiplier filter) and SubscriberInterface (the
 * recalc trigger) — one object, two registration paths.
 */
final class TierEngine implements Hookable, SubscriberInterface {

	/** User-meta keys for the cached tier. */
	public const META_SLUG     = '_yzrw_tier';
	public const META_NAME     = '_yzrw_tier_name';
	public const META_MULT     = '_yzrw_tier_mult';
	public const META_DISCOUNT = '_yzrw_tier_discount';

	/**
	 * @param TierRepository   $tiers    Tier definitions.
	 * @param PointsRepository $points   Points repository (lifetime earned).
	 * @param EventBus         $bus      Event bus.
	 * @param Settings         $settings Settings.
	 * @param Money            $money    Rounding helper.
	 */
	public function __construct(
		private TierRepository $tiers,
		private PointsRepository $points,
		private EventBus $bus,
		private Settings $settings,
		private Money $money
	) {}

	/**
	 * @inheritDoc
	 */
	public function hooks(): array {
		return array(
			array(
				'type'     => 'filter',
				'hook'     => 'yazan_rewards/points/earn_amount',
				'method'   => 'apply_multiplier',
				'priority' => 10, // Before campaigns (20).
				'args'     => 3,
			),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function subscribed_events(): array {
		return array(
			Events::POINTS_CREDITED => array( 'on_points_credited' ),
		);
	}

	/**
	 * Recalculate the tier after a credit.
	 *
	 * @param Event $event points_credited.
	 * @return void
	 */
	public function on_points_credited( Event $event ): void {
		$this->recalculate( (int) $event->get( 'user_id' ) );
	}

	/**
	 * Apply the earning user's tier multiplier.
	 *
	 * @param int    $points  Points.
	 * @param string $event   Event key.
	 * @param array  $context Context (expects user_id).
	 * @return int
	 */
	public function apply_multiplier( $points, $event, $context ) {
		$points  = (int) $points;
		$user_id = (int) ( $context['user_id'] ?? 0 );
		if ( $points <= 0 || $user_id <= 0 || ! $this->settings->feature_enabled( 'achievement' ) ) {
			return $points;
		}

		$mult = (float) get_user_meta( $user_id, self::META_MULT, true );
		if ( $mult <= 1.0 ) {
			return $points;
		}
		return $this->money->round( $points * $mult, 'floor' );
	}

	/**
	 * Determine and cache a user's tier from lifetime points. Dispatches
	 * TIER_CHANGED on a transition.
	 *
	 * @param int $user_id User id.
	 * @return array{slug:string,name:string,multiplier:float}
	 */
	public function recalculate( int $user_id ): array {
		$blank = array( 'slug' => '', 'name' => '', 'multiplier' => 1.0 );
		if ( $user_id <= 0 ) {
			return $blank;
		}

		$lifetime = $this->points->lifetime_earned( $user_id );
		$current  = $blank;
		$discount = 0.0;

		foreach ( $this->tiers->all() as $tier ) {
			if ( $lifetime >= (int) $tier->threshold_points ) {
				$current = array(
					'slug'       => (string) $tier->slug,
					'name'       => (string) $tier->name,
					'multiplier' => (float) $tier->multiplier,
				);
				$discount = (float) ( $tier->discount_percent ?? 0 );
			}
		}

		$previous = (string) get_user_meta( $user_id, self::META_SLUG, true );

		// Derived values are always kept fresh (cheap), so the cached multiplier +
		// discount stay correct even without a level change (e.g. after a re-seed).
		update_user_meta( $user_id, self::META_MULT, $current['multiplier'] );
		update_user_meta( $user_id, self::META_DISCOUNT, $discount );

		if ( $previous !== $current['slug'] ) {
			update_user_meta( $user_id, self::META_SLUG, $current['slug'] );
			update_user_meta( $user_id, self::META_NAME, $current['name'] );

			if ( '' !== $current['slug'] ) {
				$this->bus->dispatch(
					new GenericEvent(
						Events::TIER_CHANGED,
						array(
							'user_id'  => $user_id,
							'from'     => $previous,
							'to'       => $current['slug'],
							'tier'     => $current,
							'lifetime' => $lifetime,
						)
					)
				);
			}
		}

		return $current;
	}

	/**
	 * Force a user into a specific tier by slug (the `upgrade_level` rule action).
	 * Overrides the points-derived tier until the next recalculation lifts them
	 * above it. Dispatches TIER_CHANGED on a real transition.
	 *
	 * @param int    $user_id User id.
	 * @param string $slug    Target tier slug.
	 * @return bool True when the tier was set.
	 */
	public function set_tier( int $user_id, string $slug ): bool {
		if ( $user_id <= 0 || '' === $slug ) {
			return false;
		}
		$target = null;
		foreach ( $this->tiers->all() as $tier ) {
			if ( (string) $tier->slug === $slug ) {
				$target = $tier;
				break;
			}
		}
		if ( ! $target ) {
			return false;
		}

		$previous = (string) get_user_meta( $user_id, self::META_SLUG, true );
		update_user_meta( $user_id, self::META_SLUG, (string) $target->slug );
		update_user_meta( $user_id, self::META_NAME, (string) $target->name );
		update_user_meta( $user_id, self::META_MULT, (float) $target->multiplier );
		update_user_meta( $user_id, self::META_DISCOUNT, (float) ( $target->discount_percent ?? 0 ) );

		if ( $previous !== (string) $target->slug ) {
			$this->bus->dispatch(
				new GenericEvent(
					Events::TIER_CHANGED,
					array(
						'user_id' => $user_id,
						'from'    => $previous,
						'to'      => (string) $target->slug,
						'tier'    => array( 'slug' => (string) $target->slug, 'name' => (string) $target->name, 'multiplier' => (float) $target->multiplier ),
						'forced'  => true,
					)
				)
			);
		}
		return true;
	}

	/**
	 * The current cached tier for a user (for display).
	 *
	 * @param int $user_id User id.
	 * @return array{slug:string,name:string,multiplier:float}
	 */
	public function current( int $user_id ): array {
		return array(
			'slug'       => (string) get_user_meta( $user_id, self::META_SLUG, true ),
			'name'       => (string) get_user_meta( $user_id, self::META_NAME, true ),
			'multiplier' => (float) get_user_meta( $user_id, self::META_MULT, true ) ?: 1.0,
		);
	}

	/**
	 * The member's live discount percent, read from the tiers table for the level
	 * their LIFETIME earned points place them in. Computed live (not from the
	 * cached `_yzrw_tier_discount` meta) so the checkout fee always matches what
	 * the dashboard shows — including for members who reached their level before
	 * this column existed and haven't earned again since (their cached meta would
	 * be empty/stale). Single source of truth shared with {@see self::level()}.
	 *
	 * @param int $user_id User id.
	 * @return float
	 */
	public function discount_percent( int $user_id ): float {
		if ( $user_id <= 0 ) {
			return 0.0;
		}
		$tier = $this->matched_tier( $this->points->lifetime_earned( $user_id ) );
		return $tier ? (float) ( $tier->discount_percent ?? 0 ) : 0.0;
	}

	/**
	 * The highest tier whose threshold the given lifetime meets, or null. The one
	 * place "which level is this?" is decided, so level(), ladder(), and the
	 * checkout discount can never disagree.
	 *
	 * @param int $lifetime Lifetime earned points.
	 * @return object|null Tier row.
	 */
	private function matched_tier( int $lifetime ): ?object {
		$matched = null;
		foreach ( $this->tiers->all() as $tier ) { // ascending by threshold.
			if ( $lifetime >= (int) $tier->threshold_points ) {
				$matched = $tier;
			}
		}
		return $matched;
	}

	/**
	 * Full membership-level detail for the dashboard: the current level (badge,
	 * color, multiplier, discount, benefits) driven by lifetime EARNED points, plus
	 * progress to the next level.
	 *
	 * @param int $user_id User id.
	 * @return array<string,mixed>
	 */
	public function level( int $user_id ): array {
		$lifetime   = $this->points->lifetime_earned( $user_id );
		$currentRow = $this->matched_tier( $lifetime );

		$nextRow = null;
		foreach ( $this->tiers->all() as $tier ) { // ascending by threshold.
			if ( $lifetime < (int) $tier->threshold_points ) {
				$nextRow = $tier; // first level not yet reached.
				break;
			}
		}

		$current = $currentRow ? $this->format_tier( $currentRow ) : array(
			'slug' => '', 'name' => '', 'badge' => '', 'color' => '', 'multiplier' => 1.0, 'discount_percent' => 0.0, 'threshold_points' => 0, 'benefits' => array(),
		);

		$next = null;
		if ( $nextRow ) {
			$threshold = (int) $nextRow->threshold_points;
			$span      = max( 1, $threshold - (int) ( $currentRow->threshold_points ?? 0 ) );
			$next      = array(
				'name'      => (string) $nextRow->name,
				'threshold' => $threshold,
				'remaining' => max( 0, $threshold - $lifetime ),
				'progress'  => min( 1.0, round( ( $lifetime - (int) ( $currentRow->threshold_points ?? 0 ) ) / $span, 4 ) ),
			);
		}

		return array(
			'lifetime' => $lifetime,
			'current'  => $current,
			'next'     => $next,
		);
	}

	/**
	 * The full level ladder, with the user's current level flagged.
	 *
	 * @param int $user_id User id.
	 * @return array<int,array<string,mixed>>
	 */
	public function ladder( int $user_id ): array {
		// Derive the current level from lifetime EARNED points (not cached meta),
		// so the ladder always agrees with level() even before a recalculate().
		$current      = $this->matched_tier( $this->points->lifetime_earned( $user_id ) );
		$current_slug = $current ? (string) $current->slug : '';

		return array_map(
			function ( $tier ) use ( $current_slug ) {
				$row               = $this->format_tier( $tier );
				$row['is_current'] = ( '' !== $current_slug && $current_slug === (string) $tier->slug );
				return $row;
			},
			$this->tiers->all()
		);
	}

	/**
	 * Format a tier row for output.
	 *
	 * @param object $tier Tier row.
	 * @return array<string,mixed>
	 */
	private function format_tier( object $tier ): array {
		$benefits = json_decode( (string) ( $tier->benefits ?? '' ), true );
		return array(
			'slug'             => (string) $tier->slug,
			'name'             => (string) $tier->name,
			'badge'            => (string) ( $tier->badge ?? '' ),
			'color'            => (string) ( $tier->color ?? '' ),
			'multiplier'       => (float) $tier->multiplier,
			'discount_percent' => (float) ( $tier->discount_percent ?? 0 ),
			'threshold_points' => (int) $tier->threshold_points,
			'benefits'         => is_array( $benefits ) ? $benefits : array(),
		);
	}
}
