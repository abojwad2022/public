<?php
/**
 * Ambassador / member dashboard — My Account endpoint.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Frontend;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Contracts\Hookable;
use Yazan\Rewards\Core\Contracts\PointsLedgerInterface;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Core\Support\Assets;
use Yazan\Rewards\Modules\Achievement\AchievementRepository;
use Yazan\Rewards\Modules\Achievement\TierEngine;
use Yazan\Rewards\Modules\Activity\ActivityRepository;
use Yazan\Rewards\Modules\Ambassador\AmbassadorRepository;
use Yazan\Rewards\Modules\Campaigns\CampaignEligibility;
use Yazan\Rewards\Modules\Campaigns\CampaignTaskRepository;
use Yazan\Rewards\Modules\Campaigns\MarketingCampaignRepository;
use Yazan\Rewards\Modules\Campaigns\ParticipantRepository;
use Yazan\Rewards\Modules\Points\PointsRepository;
use Yazan\Rewards\Modules\Referral\ReferralCodes;
use Yazan\Rewards\Modules\Referral\ReferralRepository;
use Yazan\Rewards\Modules\Rewards\RewardRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a "Ambassador" tab to WooCommerce My Account at /my-account/yazan-ambassador
 * and renders a read-only member dashboard: current + lifetime points, membership
 * level (badge/colour/discount, driven by lifetime EARNED points), ranking,
 * campaigns, achievements, rewards, activity, referral stats, and the ambassador
 * panel. Every section resolves its source with container->has(), so the dashboard
 * degrades gracefully if an engine is disabled.
 */
final class AmbassadorDashboard implements Hookable {

	/** Endpoint slug under /my-account/. */
	public const ENDPOINT = 'yazan-ambassador';

	/**
	 * @param Container $container Service container.
	 */
	public function __construct( private Container $container ) {}

	/**
	 * @inheritDoc
	 */
	public function hooks(): array {
		return array(
			array( 'type' => 'action', 'hook' => 'init', 'method' => 'add_endpoint' ),
			array( 'type' => 'filter', 'hook' => 'woocommerce_get_query_vars', 'method' => 'add_query_var' ),
			array( 'type' => 'filter', 'hook' => 'woocommerce_account_menu_items', 'method' => 'add_menu_item' ),
			array( 'type' => 'action', 'hook' => 'woocommerce_account_' . self::ENDPOINT . '_endpoint', 'method' => 'render' ),
			array( 'type' => 'filter', 'hook' => 'woocommerce_endpoint_' . self::ENDPOINT . '_title', 'method' => 'endpoint_title' ),
			array( 'type' => 'action', 'hook' => 'wp_enqueue_scripts', 'method' => 'enqueue' ),
		);
	}

	/**
	 * Register the rewrite endpoint (also called from the module's activate()).
	 *
	 * @return void
	 */
	public function add_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Register the endpoint query var with WooCommerce.
	 *
	 * @param array $vars WC query vars.
	 * @return array
	 */
	public function add_query_var( $vars ) {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * Insert the "Ambassador" menu item near the top (after Dashboard).
	 *
	 * @param array $items Menu items.
	 * @return array
	 */
	public function add_menu_item( $items ) {
		$new = array();
		$inserted = false;
		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'dashboard' === $key ) {
				$new[ self::ENDPOINT ] = __( 'Ambassador', 'yazan-rewards' );
				$inserted = true;
			}
		}
		if ( ! $inserted ) {
			// No dashboard item — add before Sign Out (or at the end).
			$out = array();
			foreach ( $new as $key => $label ) {
				if ( 'customer-logout' === $key ) {
					$out[ self::ENDPOINT ] = __( 'Ambassador', 'yazan-rewards' );
				}
				$out[ $key ] = $label;
			}
			if ( ! isset( $out[ self::ENDPOINT ] ) ) {
				$out[ self::ENDPOINT ] = __( 'Ambassador', 'yazan-rewards' );
			}
			return $out;
		}
		return $new;
	}

	/**
	 * Endpoint page title.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	public function endpoint_title( $title ) {
		return __( 'Ambassador Dashboard', 'yazan-rewards' );
	}

	/**
	 * Enqueue dashboard assets on the account page.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}
		$assets = $this->container->get( Assets::class );
		wp_enqueue_style( 'yazan-ambassador', $assets->url( 'assets/css/ambassador.css' ), array(), $assets->version( 'assets/css/ambassador.css' ) );
		wp_enqueue_script( 'yazan-ambassador', $assets->url( 'assets/js/ambassador.js' ), array(), $assets->version( 'assets/js/ambassador.js' ), true );
		wp_localize_script(
			'yazan-ambassador',
			'YazanDashboard',
			array(
				'restUrl' => esc_url_raw( rest_url( 'yazan-rewards/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'copied'    => __( 'Link copied', 'yazan-rewards' ),
					'applying'  => __( 'Applying…', 'yazan-rewards' ),
					'applied'   => __( 'Application received', 'yazan-rewards' ),
					'error'     => __( 'Something went wrong.', 'yazan-rewards' ),
				),
			)
		);
	}

	/**
	 * Render the dashboard.
	 *
	 * @return void
	 */
	public function render(): void {
		$user_id  = get_current_user_id();
		$settings = $this->container->get( Settings::class );
		$points   = $this->container->get( PointsLedgerInterface::class );
		$repo     = $this->container->get( PointsRepository::class );

		$data = array(
			'points_balance' => $points->balance( $user_id ),
			'lifetime'       => $repo->lifetime_earned( $user_id ),
			'label_plural'   => (string) $settings->get( 'currency_name_plural', 'Points' ),
			'level'          => $this->level_data( $user_id ),
			'ladder'         => $this->ladder_data( $user_id ),
			'ranking'        => $this->ranking_data( $user_id ),
			'campaigns'      => $this->campaign_data( $user_id ),
			'achievements'   => $this->achievement_data( $user_id ),
			'rewards'        => $this->reward_data(),
			'activity'       => $this->activity_data( $user_id ),
			'referral'       => $this->referral_data( $user_id ),
			'ambassador'     => $this->ambassador_data( $user_id ),
			'rewards_url'    => function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'rewards' ) : '',
		);

		$template = YAZAN_REWARDS_DIR . 'templates/account/ambassador-dashboard.php';

		/**
		 * Filter the dashboard template path (theme override support).
		 *
		 * @param string $template Absolute path.
		 */
		$template = (string) apply_filters( 'yazan_rewards/ambassador_dashboard_template', $template );

		if ( is_readable( $template ) ) {
			include $template; // $data consumed inside.
		}
	}

	/* --------------------------------------------------------------------- */
	/* Section data builders (each degrades if its module/feature is off)      */
	/* --------------------------------------------------------------------- */

	/**
	 * Whether a section should render: its module must be bound AND its feature
	 * flag enabled. Modules always register() their services regardless of flags,
	 * so container->has() alone can't tell a disabled feature apart — we must not
	 * advertise perks (e.g. the tier discount/multiplier) a disabled engine no
	 * longer applies at checkout.
	 *
	 * @param string $feature Feature flag key.
	 * @param string $class   Service class the section needs.
	 * @return bool
	 */
	private function enabled( string $feature, string $class ): bool {
		return $this->container->has( $class )
			&& $this->container->get( Settings::class )->feature_enabled( $feature );
	}

	/**
	 * @param int $user_id User id.
	 * @return array<string,mixed>
	 */
	private function level_data( int $user_id ): array {
		if ( ! $this->enabled( 'achievement', TierEngine::class ) ) {
			return array( 'current' => array(), 'next' => null, 'lifetime' => 0 );
		}
		return $this->container->get( TierEngine::class )->level( $user_id );
	}

	/**
	 * @param int $user_id User id.
	 * @return array<int,array<string,mixed>>
	 */
	private function ladder_data( int $user_id ): array {
		return $this->enabled( 'achievement', TierEngine::class ) ? $this->container->get( TierEngine::class )->ladder( $user_id ) : array();
	}

	/**
	 * @param int $user_id User id.
	 * @return array{rank:int,total:int,lifetime:int}
	 */
	private function ranking_data( int $user_id ): array {
		$key    = 'yzrw_rank_' . $user_id;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$rank = $this->container->get( PointsRepository::class )->rank_by_lifetime( $user_id );
		set_transient( $key, $rank, 10 * MINUTE_IN_SECONDS );
		return $rank;
	}

	/**
	 * @param int $user_id User id.
	 * @return array{available:array,completed:array}
	 */
	private function campaign_data( int $user_id ): array {
		if ( ! $this->enabled( 'marketing', MarketingCampaignRepository::class ) ) {
			return array( 'available' => array(), 'completed' => array() );
		}
		$repo    = $this->container->get( MarketingCampaignRepository::class );
		$tasks   = $this->container->get( CampaignTaskRepository::class );
		$elig    = $this->container->get( CampaignEligibility::class );
		$parts   = $this->container->get( ParticipantRepository::class );

		// Completed first, so a finished campaign is never also listed as available.
		$completed     = array();
		$completed_ids = array();
		foreach ( $parts->campaigns_for_user( $user_id ) as $cid ) {
			$participant = $parts->get( $cid, $user_id );
			if ( $participant && 'completed' === $participant->status ) {
				$completed_ids[ (int) $cid ] = true;
				$campaign                    = $repo->get( $cid );
				if ( $campaign ) {
					$completed[] = array( 'id' => $cid, 'title' => $campaign->title, 'points' => (int) $participant->points_earned );
				}
			}
		}

		$available = array();
		foreach ( $repo->active() as $campaign ) {
			if ( isset( $completed_ids[ (int) $campaign->id ] ) ) {
				continue; // Already completed — don't offer it again.
			}
			if ( $elig->eligible( $user_id, $campaign->target ) ) {
				$available[] = array(
					'id'    => $campaign->id,
					'title' => $campaign->title,
					'ends'  => $campaign->ends_at,
					'tasks' => count( $tasks->for_campaign( $campaign->id ) ),
				);
			}
		}

		return array( 'available' => $available, 'completed' => $completed );
	}

	/**
	 * @param int $user_id User id.
	 * @return array<int,array<string,mixed>>
	 */
	private function achievement_data( int $user_id ): array {
		if ( ! $this->enabled( 'achievement', AchievementRepository::class ) ) {
			return array();
		}
		$repo     = $this->container->get( AchievementRepository::class );
		$unlocked = array_flip( $repo->unlocked_ids( $user_id ) );
		$out      = array();
		foreach ( $repo->all() as $a ) {
			$out[] = array(
				'name'        => (string) $a->name,
				'description' => (string) $a->description,
				'points'      => (int) $a->points_award,
				'unlocked'    => isset( $unlocked[ (int) $a->id ] ),
			);
		}
		return $out;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function reward_data(): array {
		if ( ! $this->enabled( 'rewards', RewardRepository::class ) ) {
			return array();
		}
		return array_map(
			static fn( $r ) => $r->to_array(),
			array_slice( $this->container->get( RewardRepository::class )->active(), 0, 6 )
		);
	}

	/**
	 * @param int $user_id User id.
	 * @return object[]
	 */
	private function activity_data( int $user_id ): array {
		if ( ! $this->container->has( ActivityRepository::class ) ) {
			return array();
		}
		return $this->container->get( ActivityRepository::class )->for_user( $user_id, 10, 1 )['items'];
	}

	/**
	 * @param int $user_id User id.
	 * @return array<string,mixed>
	 */
	private function referral_data( int $user_id ): array {
		if ( ! $this->enabled( 'referral', ReferralRepository::class ) || ! $this->container->has( ReferralCodes::class ) ) {
			return array();
		}
		$code = $this->container->get( ReferralCodes::class )->for_user( $user_id );

		// Downline counts require a BFS walk; cache briefly since this renders on every
		// My Account page load.
		$tree = array();
		if ( $this->container->has( \Yazan\Rewards\Modules\Referral\ReferralTree::class ) ) {
			$cache_key = 'yzrw_reftree_' . $user_id;
			$cached    = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				$tree = $cached;
			} else {
				$tree = $this->container->get( \Yazan\Rewards\Modules\Referral\ReferralTree::class )->downline_counts( $user_id, 2 );
				set_transient( $cache_key, $tree, 10 * MINUTE_IN_SECONDS );
			}
		}
		return array(
			'code'  => $code,
			'link'  => add_query_arg( 'ref', rawurlencode( $code ), home_url( '/' ) ),
			'stats' => $this->container->get( ReferralRepository::class )->stats_for_referrer( $user_id ),
			'tree'  => $tree,
		);
	}

	/**
	 * @param int $user_id User id.
	 * @return array<string,mixed>|null
	 */
	private function ambassador_data( int $user_id ): ?array {
		if ( ! $this->container->has( AmbassadorRepository::class ) ) {
			return null;
		}
		$ambassador = $this->container->get( AmbassadorRepository::class )->get_by_user( $user_id );
		if ( ! $ambassador ) {
			return array( 'is_ambassador' => false );
		}
		$data                  = $ambassador->to_array();
		$data['is_ambassador'] = true;
		$data['link']          = add_query_arg( 'ref', rawurlencode( $ambassador->code ), home_url( '/' ) );
		return $data;
	}
}
