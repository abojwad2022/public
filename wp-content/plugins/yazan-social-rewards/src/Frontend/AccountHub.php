<?php
/**
 * My Account "Rewards" hub.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Frontend;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Contracts\Hookable;
use Yazan\Rewards\Core\Contracts\PointsLedgerInterface;
use Yazan\Rewards\Core\Contracts\WalletServiceInterface;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Modules\Points\PointsRepository;
use Yazan\Rewards\Modules\Rewards\RewardRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a "Rewards" tab to WooCommerce My Account and renders a server-side hub
 * (balance, wallet, catalog, history). Rendering server-side means the page is
 * complete without JavaScript; a small script (see Assets) progressively enhances
 * the redeem buttons via REST.
 */
final class AccountHub implements Hookable {

	/** Endpoint slug under /my-account/. */
	public const ENDPOINT = 'rewards';

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
		);
	}

	/**
	 * Register the rewrite endpoint. Also called from the module's activate() so
	 * the Activator's rewrite flush includes it.
	 *
	 * @return void
	 */
	public function add_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Register the endpoint's query var with WooCommerce.
	 *
	 * @param array $vars WC query vars.
	 * @return array
	 */
	public function add_query_var( $vars ) {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * Insert the "Rewards" item just before "Sign Out".
	 *
	 * @param array $items Menu items.
	 * @return array
	 */
	public function add_menu_item( $items ) {
		$new = array();
		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key ) {
				$new[ self::ENDPOINT ] = __( 'Rewards', 'yazan-rewards' );
			}
			$new[ $key ] = $label;
		}
		if ( ! isset( $new[ self::ENDPOINT ] ) ) {
			$new[ self::ENDPOINT ] = __( 'Rewards', 'yazan-rewards' );
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
		return __( 'Rewards', 'yazan-rewards' );
	}

	/**
	 * Render the hub.
	 *
	 * @return void
	 */
	public function render(): void {
		$user_id  = get_current_user_id();
		$settings = $this->container->get( Settings::class );
		$points   = $this->container->get( PointsLedgerInterface::class );
		$wallet   = $this->container->has( WalletServiceInterface::class ) ? $this->container->get( WalletServiceInterface::class ) : null;
		$rewards  = $this->container->get( RewardRepository::class );

		$data = array(
			'balance'        => $points->balance( $user_id ),
			'label_singular' => (string) $settings->get( 'currency_name_singular', 'Point' ),
			'label_plural'   => (string) $settings->get( 'currency_name_plural', 'Points' ),
			'wallet_enabled' => null !== $wallet,
			'wallet_balance' => $wallet ? $wallet->balance( $user_id ) : '0',
			'wallet_html'    => ( $wallet && function_exists( 'wc_price' ) ) ? wc_price( (float) $wallet->balance( $user_id ) ) : '',
			'rewards'        => $rewards->active(),
			'history'        => $this->container->get( PointsRepository::class )->history( $user_id, 10, 1 ),
			'campaigns'      => $this->active_campaigns( $user_id ),
			// Cross-link to the membership dashboard — only when that endpoint is
			// actually registered (the Ambassador feature is on), so the link can't 404.
			'ambassador_url' => ( $settings->feature_enabled( 'ambassador' ) && function_exists( 'wc_get_account_endpoint_url' ) )
				? wc_get_account_endpoint_url( 'yazan-ambassador' )
				: '',
		);

		$template = YAZAN_REWARDS_DIR . 'templates/account/rewards-hub.php';

		/**
		 * Filter the account-hub template path (theme override support).
		 *
		 * @param string $template Absolute path.
		 */
		$template = (string) apply_filters( 'yazan_rewards/account_hub_template', $template );

		if ( is_readable( $template ) ) {
			// $data is consumed inside the template.
			include $template;
		}
	}

	/**
	 * Active campaigns the current user is eligible for, with their tasks +
	 * per-task submission status. Returns [] when the marketing engine is off.
	 *
	 * @param int $user_id User id.
	 * @return array<int,array<string,mixed>>
	 */
	private function active_campaigns( int $user_id ): array {
		$repo_class = \Yazan\Rewards\Modules\Campaigns\MarketingCampaignRepository::class;
		if ( $user_id <= 0 || ! $this->container->has( $repo_class ) ) {
			return array();
		}
		$repo        = $this->container->get( $repo_class );
		$tasks_repo  = $this->container->get( \Yazan\Rewards\Modules\Campaigns\CampaignTaskRepository::class );
		$subs_repo   = $this->container->get( \Yazan\Rewards\Modules\Campaigns\SubmissionRepository::class );
		$eligibility = $this->container->get( \Yazan\Rewards\Modules\Campaigns\CampaignEligibility::class );

		$out = array();
		foreach ( $repo->active() as $campaign ) {
			if ( ! $eligibility->eligible( $user_id, $campaign->target ) ) {
				continue;
			}
			$mine = array();
			foreach ( $subs_repo->for_user_campaign( $campaign->id, $user_id ) as $s ) {
				$mine[ (int) $s->task_id ] = (string) $s->status;
			}
			$tasks = array();
			foreach ( $tasks_repo->for_campaign( $campaign->id ) as $t ) {
				$tasks[] = array(
					'id'        => (int) $t->id,
					'name'      => (string) $t->name,
					'points'    => (int) $t->points_award,
					'my_status' => $mine[ (int) $t->id ] ?? 'none',
				);
			}
			$out[] = array(
				'id'          => $campaign->id,
				'title'       => $campaign->title,
				'description' => $campaign->description,
				'ends_at'     => $campaign->ends_at,
				'reward'      => $campaign->reward_amount,
				'tasks'       => $tasks,
			);
		}
		return $out;
	}
}
