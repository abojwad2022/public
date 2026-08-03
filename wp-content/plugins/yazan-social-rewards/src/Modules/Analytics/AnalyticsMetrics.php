<?php
/**
 * Analytics metric aggregator.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Analytics;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Database\Database;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Core\Support\Money;
use Yazan\Rewards\Modules\Ambassador\AmbassadorRepository;
use Yazan\Rewards\Modules\Achievement\TierRepository;
use Yazan\Rewards\Modules\Campaigns\CampaignAnalytics;
use Yazan\Rewards\Modules\Campaigns\MarketingCampaignRepository;
use Yazan\Rewards\Modules\Referral\ReferralRepository;
use Yazan\Rewards\Modules\Rewards\RedemptionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Computes every dashboard metric on demand, reusing each engine's repositories
 * (resolved optionally so a disabled engine degrades to zeros). The heavy WooCommerce
 * order aggregate (retention + CLV) is a single read-only GROUP BY on the HPOS orders
 * table, cached in a transient, so the dashboard is cheap to reload. Read-only — no
 * writes, no schema.
 */
final class AnalyticsMetrics {

	/** Order-aggregate cache key. */
	private const ORDER_CACHE = 'yzrw_analytics_orders';

	/**
	 * The store this request belongs to.
	 *
	 * @return int
	 */
	private function store_id(): int {
		return \class_exists( 'Yazan_Store_Context' ) ? \Yazan_Store_Context::current() : 1;
	}

	/** Full-overview cache key prefix (per range). */
	private const OVERVIEW_CACHE = 'yzrw_analytics_overview_';

	/**
	 * @param Container            $container Service container.
	 * @param Database             $db        Database.
	 * @param ReportRepository     $reports   Rollup repo.
	 * @param LiabilityCalculator  $liability Liability calculator.
	 * @param Money                $money     Money helper.
	 * @param Settings             $settings  Settings.
	 */
	public function __construct(
		private Container $container,
		private Database $db,
		private ReportRepository $reports,
		private LiabilityCalculator $liability,
		private Money $money,
		private Settings $settings
	) {}

	/**
	 * The full dashboard payload for a range (in days; 0 = all-time).
	 *
	 * @param int  $range_days Range in days.
	 * @param bool $fresh      Bypass caches.
	 * @return array<string,mixed>
	 */
	public function overview( int $range_days = 30, bool $fresh = false ): array {
		/*
		 * ⚠️ THE STORE IS IN THE KEY. Without it this transient held the ENTIRE analytics payload
		 * — customer counts, campaign performance, outstanding liability, the revenue series —
		 * under a key that named only the day range. The first store to warm it served its
		 * commercial position to every other tenant that opened the same screen.
		 */
		$cache_key = self::OVERVIEW_CACHE . $this->store_id() . '_' . $range_days;
		if ( ! $fresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		list( $from, $to ) = $this->range( $range_days );
		$payload = array(
			'range'     => array( 'days' => $range_days, 'from' => $from, 'to' => $to ),
			'customers' => $this->customers(),
			'campaigns' => $this->campaigns(),
			'business'  => $this->business( $fresh ),
			'series'    => $this->series( $from, $to ),
		);

		// Cache the whole payload (count_users + the campaigns aggregate + liability +
		// series) so a dashboard reload — and export(), which re-requests overview() —
		// is cheap. 15-min TTL; $fresh bypasses.
		set_transient( $cache_key, $payload, 15 * MINUTE_IN_SECONDS );
		return $payload;
	}

	/* ---- Customers ---- */

	/**
	 * @return array<string,mixed>
	 */
	public function customers(): array {
		$counts = count_users();
		$total  = (int) ( $counts['avail_roles']['customer'] ?? 0 );

		$tiers      = $this->tier_breakdown();
		$vip_slugs  = $this->vip_slugs();
		$vip        = 0;
		foreach ( $tiers as $t ) {
			if ( in_array( $t['slug'], $vip_slugs, true ) ) {
				$vip += (int) $t['count'];
			}
		}

		$ambassadors = $this->container->has( AmbassadorRepository::class )
			? $this->container->get( AmbassadorRepository::class )->count_active()
			: 0;

		return array(
			'total'             => $total,
			'vip'               => $vip,
			'active_ambassadors' => $ambassadors,
			'tiers'             => $tiers,
		);
	}

	/**
	 * Customers per tier (from the cached `_yzrw_tier` meta).
	 *
	 * @return array<int,array{slug:string,label:string,count:int}>
	 */
	private function tier_breakdown(): array {
		$wpdb = $this->db->wpdb();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_value AS tier, COUNT(*) AS n FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> '' GROUP BY meta_value", '_yzrw_tier' ) );

		$labels = array();
		if ( $this->container->has( TierRepository::class ) ) {
			foreach ( $this->container->get( TierRepository::class )->all() as $tier ) {
				$labels[ (string) $tier->slug ] = (string) $tier->name;
			}
		}
		$out = array();
		foreach ( (array) $rows as $r ) {
			$slug  = (string) $r->tier;
			$out[] = array( 'slug' => $slug, 'label' => $labels[ $slug ] ?? ucfirst( $slug ), 'count' => (int) $r->n );
		}
		return $out;
	}

	/**
	 * The VIP tier slugs — the two highest-threshold tiers (Gold + Platinum), so the
	 * definition follows the configured levels even if renamed.
	 *
	 * @return string[]
	 */
	private function vip_slugs(): array {
		if ( ! $this->container->has( TierRepository::class ) ) {
			return array( 'gold', 'platinum' );
		}
		$tiers = $this->container->get( TierRepository::class )->all(); // ascending by threshold.
		$slugs = array_map( static fn( $t ) => (string) $t->slug, $tiers );
		return array_slice( $slugs, -2 ); // top two.
	}

	/* ---- Campaigns ---- */

	/**
	 * @return array<string,mixed>
	 */
	public function campaigns(): array {
		$empty = array( 'total_campaigns' => 0, 'participation' => 0, 'content_generated' => 0, 'engagement_rate' => 0.0, 'best' => array() );
		if ( ! $this->container->has( MarketingCampaignRepository::class ) || ! $this->container->has( CampaignAnalytics::class ) ) {
			return $empty;
		}
		$repo      = $this->container->get( MarketingCampaignRepository::class );
		$analytics = $this->container->get( CampaignAnalytics::class );

		$campaigns     = $repo->all();
		$participation = 0;
		$content       = 0;
		$eng_sum       = 0.0;
		$eng_n         = 0;
		$best          = array();

		foreach ( $campaigns as $campaign ) {
			$a               = $analytics->for_campaign( (int) $campaign->id );
			$participation  += (int) $a['participants'];
			$content        += (int) $a['submissions'];
			if ( $a['participants'] > 0 ) {
				$eng_sum += (float) $a['engagement_rate'];
				$eng_n++;
			}
			$best[] = array(
				'id'               => (int) $campaign->id,
				'title'            => (string) $campaign->title,
				'participants'     => (int) $a['participants'],
				'submissions'      => (int) $a['submissions'],
				'points_generated' => (int) $a['points_generated'],
				'engagement_rate'  => (float) $a['engagement_rate'],
			);
		}

		usort( $best, static fn( $x, $y ) => $y['points_generated'] <=> $x['points_generated'] );

		return array(
			'total_campaigns'   => count( $campaigns ),
			'participation'     => $participation,
			'content_generated' => $content,
			'engagement_rate'   => $eng_n > 0 ? round( $eng_sum / $eng_n, 4 ) : 0.0,
			'best'              => array_slice( $best, 0, 8 ),
		);
	}

	/* ---- Business ---- */

	/**
	 * @param bool $fresh Bypass the order cache.
	 * @return array<string,mixed>
	 */
	public function business( bool $fresh = false ): array {
		$ratio = (int) $this->settings->get( 'wallet.points_to_currency', 100 );
		$ratio = $ratio > 0 ? $ratio : 100;

		$referral_revenue = '0';
		if ( $this->container->has( ReferralRepository::class ) ) {
			$referral_revenue = $this->container->get( ReferralRepository::class )->global_stats()['revenue'];
		}

		$reward_points = 0;
		$redemptions   = 0;
		if ( $this->container->has( RedemptionRepository::class ) ) {
			$rr            = $this->container->get( RedemptionRepository::class );
			$reward_points = $rr->total_points_spent();
			$redemptions   = $rr->total_redemptions();
		}

		$orders = $this->order_aggregate( $fresh );

		return array(
			'referral_revenue' => $this->money->format( $referral_revenue ),
			'reward_cost'      => array(
				'points'      => $reward_points,
				'value'       => $this->money->points_to_currency( $reward_points, $ratio ),
				'redemptions' => $redemptions,
			),
			'point_liability'  => $this->liability->snapshot(),
			'retention_rate'   => $orders['retention_rate'],
			'clv'              => $orders['clv'],
			'revenue_total'    => $orders['total_revenue'],
			'purchasing_customers' => $orders['purchasing_customers'],
		);
	}

	/**
	 * Read-only per-customer order aggregate (HPOS orders table when enabled, else the
	 * post/meta store), for retention + CLV. Cached ~30 min.
	 *
	 * @param bool $fresh Bypass cache.
	 * @return array{purchasing_customers:int,repeat_customers:int,retention_rate:float,total_revenue:string,clv:string}
	 */
	private function order_aggregate( bool $fresh = false ): array {
		if ( ! $fresh ) {
			$cached = get_transient( self::ORDER_CACHE . '_' . $this->store_id() );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$wpdb   = $this->db->wpdb();
		$hpos   = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$rows   = array();

		if ( $hpos ) {
			$orders_table = $wpdb->prefix . 'wc_orders';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				"SELECT customer_id, COUNT(*) AS orders, COALESCE(SUM(total_amount),0) AS total
				 FROM {$orders_table}
				 WHERE type = 'shop_order' AND status IN ('wc-completed','wc-processing') AND customer_id > 0
				 GROUP BY customer_id"
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				"SELECT cu.meta_value AS customer_id, COUNT(*) AS orders, COALESCE(SUM(ot.meta_value+0),0) AS total
				 FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} cu ON cu.post_id = p.ID AND cu.meta_key = '_customer_user'
				 JOIN {$wpdb->postmeta} ot ON ot.post_id = p.ID AND ot.meta_key = '_order_total'
				 WHERE p.post_type = 'shop_order' AND p.post_status IN ('wc-completed','wc-processing') AND cu.meta_value > 0
				 GROUP BY cu.meta_value"
			);
		}

		$purchasing = 0;
		$repeat     = 0;
		$revenue    = 0.0;
		foreach ( (array) $rows as $r ) {
			$purchasing++;
			$revenue += (float) $r->total;
			if ( (int) $r->orders >= 2 ) {
				$repeat++;
			}
		}

		$result = array(
			'purchasing_customers' => $purchasing,
			'repeat_customers'     => $repeat,
			'retention_rate'       => $purchasing > 0 ? round( $repeat / $purchasing, 4 ) : 0.0,
			'total_revenue'        => $this->money->format( $revenue ),
			'clv'                  => $this->money->format( $purchasing > 0 ? $revenue / $purchasing : 0 ),
		);
		set_transient( self::ORDER_CACHE . '_' . $this->store_id(), $result, 30 * MINUTE_IN_SECONDS );
		return $result;
	}

	/* ---- Time series ---- */

	/**
	 * Headline daily series for the charts.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array<string,array>
	 */
	public function series( string $from, string $to ): array {
		return array(
			'points_issued' => $this->reports->series( 'points_issued', $from, $to ),
			'points_spent'  => $this->reports->series( 'points_spent', $from, $to ),
			'redemptions'   => $this->reports->series( 'redemptions', $from, $to ),
			'orders'        => $this->reports->series( 'orders_rewarded', $from, $to ),
		);
	}

	/**
	 * Resolve a range in days to [from, to] Y-m-d (0/large = all-time).
	 *
	 * @param int $days Range days.
	 * @return array{0:string,1:string}
	 */
	public function range( int $days ): array {
		$to   = current_time( 'Y-m-d' );
		$days = ( $days <= 0 || $days > 3650 ) ? 3650 : $days;
		$from = gmdate( 'Y-m-d', strtotime( $to . ' -' . $days . ' days' ) );
		return array( $from, $to );
	}
}
