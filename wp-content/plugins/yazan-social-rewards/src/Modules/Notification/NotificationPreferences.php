<?php
/**
 * Per-customer notification preferences.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Notification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the per-customer notification preference matrix (stored in the user meta
 * `_yzrw_notif_prefs`): for each category, an email mode (immediate|digest|off)
 * and an on-site toggle. REQUIRED categories (transactional — service, reward) are
 * always delivered immediately and cannot be turned off. Also the single source of
 * truth for which template belongs to which category + priority.
 */
final class NotificationPreferences {

	/** User meta key holding the preference matrix. */
	public const META_KEY = '_yzrw_notif_prefs';

	private const EMAIL_MODES = array( 'immediate', 'digest', 'off' );

	/**
	 * Category metadata: key => [ label, required, default_email ]. Filterable.
	 *
	 * @var array<string,array{label:string,required:bool,default_email:string}>|null
	 */
	private ?array $categories = null;

	/**
	 * The notification categories (labels are translated on read). `default_email`
	 * is the email mode a customer starts on for that category (points defaults to
	 * `digest` so per-earn notifications don't flood the inbox).
	 *
	 * @return array<string,array{label:string,required:bool,default_email:string}>
	 */
	public function categories(): array {
		if ( null !== $this->categories ) {
			return $this->categories;
		}
		$categories = array(
			'service'     => array( 'label' => __( 'Service requests & fulfilment', 'yazan-rewards' ), 'required' => true, 'default_email' => 'immediate' ),
			'reward'      => array( 'label' => __( 'Reward redemptions', 'yazan-rewards' ), 'required' => true, 'default_email' => 'immediate' ),
			'points'      => array( 'label' => __( 'Points activity', 'yazan-rewards' ), 'required' => false, 'default_email' => 'digest' ),
			'referral'    => array( 'label' => __( 'Referrals & commissions', 'yazan-rewards' ), 'required' => false, 'default_email' => 'immediate' ),
			'tier'        => array( 'label' => __( 'Membership level changes', 'yazan-rewards' ), 'required' => false, 'default_email' => 'immediate' ),
			'achievement' => array( 'label' => __( 'Achievements', 'yazan-rewards' ), 'required' => false, 'default_email' => 'immediate' ),
			'campaign'    => array( 'label' => __( 'Campaigns', 'yazan-rewards' ), 'required' => false, 'default_email' => 'immediate' ),
			'system'      => array( 'label' => __( 'Account & system updates', 'yazan-rewards' ), 'required' => false, 'default_email' => 'immediate' ),
		);

		/**
		 * Filter the notification categories.
		 *
		 * @param array<string,array{label:string,required:bool}> $categories Categories.
		 */
		$this->categories = (array) apply_filters( 'yazan_rewards/notification/categories', $categories );
		return $this->categories;
	}

	/**
	 * Template → category map (unmapped templates fall back to "system").
	 *
	 * @return array<string,string>
	 */
	private function template_map(): array {
		return (array) apply_filters(
			'yazan_rewards/notification/template_categories',
			array(
				'reward_redeemed'              => 'reward',
				'points_earned'                => 'points',
				'points_expiring'              => 'points',
				'points_expired'               => 'points',
				'service_requested'            => 'service',
				'service_fulfilled'            => 'service',
				'referral_converted'           => 'referral',
				'commission_earned'            => 'referral',
				'tier_changed'                 => 'tier',
				'achievement_unlocked'         => 'achievement',
				'campaign_new'                 => 'campaign',
				'campaign_ending'              => 'campaign',
				'campaign_submission_approved' => 'campaign',
				'campaign_submission_rejected' => 'campaign',
				'campaign_completed'           => 'campaign',
				'custom'                       => 'system',
			)
		);
	}

	/**
	 * The category a template belongs to.
	 *
	 * @param string $template Template key.
	 * @return string
	 */
	public function category_for_template( string $template ): string {
		$map = $this->template_map();
		$cat = $map[ $template ] ?? 'system';
		return isset( $this->categories()[ $cat ] ) ? $cat : 'system';
	}

	/**
	 * A template's priority. Urgent templates are never deferred into a digest.
	 *
	 * @param string $template Template key.
	 * @return string urgent|normal
	 */
	public function priority_for_template( string $template ): string {
		$urgent = (array) apply_filters(
			'yazan_rewards/notification/urgent_templates',
			array( 'reward_redeemed', 'service_requested', 'service_fulfilled' )
		);
		return in_array( $template, $urgent, true ) ? 'urgent' : 'normal';
	}

	/**
	 * A user's full preference matrix, defaults merged with saved values and with
	 * required categories forced on.
	 *
	 * @param int $user_id User id.
	 * @return array<string,array{email:string,onsite:bool}>
	 */
	public function get( int $user_id ): array {
		$saved = get_user_meta( $user_id, self::META_KEY, true );
		$saved = is_array( $saved ) ? $saved : array();

		$out = array();
		foreach ( $this->categories() as $key => $meta ) {
			$required = ! empty( $meta['required'] );
			$row      = is_array( $saved[ $key ] ?? null ) ? $saved[ $key ] : array();
			$fallback = (string) ( $meta['default_email'] ?? 'immediate' );

			$email = isset( $row['email'] ) && in_array( $row['email'], self::EMAIL_MODES, true ) ? (string) $row['email'] : $fallback;
			$onsite = array_key_exists( 'onsite', $row ) ? (bool) $row['onsite'] : true;

			if ( $required ) {
				$email  = 'immediate';
				$onsite = true;
			}
			$out[ $key ] = array( 'email' => $email, 'onsite' => $onsite );
		}
		return $out;
	}

	/**
	 * Persist a user's preferences (only known categories/values; required ones
	 * are always forced on).
	 *
	 * @param int   $user_id User id.
	 * @param array $input   Raw { category => { email, onsite } }.
	 * @return array<string,array{email:string,onsite:bool}>
	 */
	public function set( int $user_id, array $input ): array {
		$clean = array();
		foreach ( $this->categories() as $key => $meta ) {
			$row      = is_array( $input[ $key ] ?? null ) ? $input[ $key ] : array();
			$fallback = (string) ( $meta['default_email'] ?? 'immediate' );

			$email = isset( $row['email'] ) && in_array( $row['email'], self::EMAIL_MODES, true ) ? (string) $row['email'] : $fallback;
			$onsite = array_key_exists( 'onsite', $row ) ? (bool) $row['onsite'] : true;

			if ( ! empty( $meta['required'] ) ) {
				$email  = 'immediate';
				$onsite = true;
			}
			$clean[ $key ] = array( 'email' => $email, 'onsite' => $onsite );
		}
		update_user_meta( $user_id, self::META_KEY, $clean );
		return $this->get( $user_id );
	}

	/**
	 * Whether a customer channel may deliver this category to this user.
	 *
	 * @param int    $user_id  User id.
	 * @param string $category Category.
	 * @param string $channel  onsite|email.
	 * @return bool
	 */
	public function allows( int $user_id, string $category, string $channel ): bool {
		$prefs = $this->get( $user_id );
		$row   = $prefs[ $category ] ?? array( 'email' => 'immediate', 'onsite' => true );

		if ( 'onsite' === $channel ) {
			return (bool) $row['onsite'];
		}
		if ( 'email' === $channel ) {
			return 'off' !== $row['email'];
		}
		return true;
	}

	/**
	 * A user's email delivery mode for a category.
	 *
	 * @param int    $user_id  User id.
	 * @param string $category Category.
	 * @return string immediate|digest|off
	 */
	public function email_mode( int $user_id, string $category ): string {
		$prefs = $this->get( $user_id );
		return (string) ( $prefs[ $category ]['email'] ?? 'immediate' );
	}
}
