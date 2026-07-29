<?php
/**
 * My Account Dashboard — Yazan override.
 *
 * Replaces WooCommerce's generic greeting with a brand-voiced welcome and a set of quick-link
 * cards (Orders · Addresses · Account Details · Verify a Ring). Preserves the
 * woocommerce_account_dashboard action hooks so plugins that inject into the dashboard keep working.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package Yazan
 * @version 4.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$yz_name = ( isset( $current_user ) && $current_user->display_name ) ? $current_user->display_name : wp_get_current_user()->display_name;

/* Static, first-party inline icons (stroke = currentColor) — nothing to escape. */
$yz_card_icons = array(
	'orders'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>',
	'address' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>',
	'account' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>',
	'verify'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg>',
);

$yz_cards = array(
	array(
		'icon'  => $yz_card_icons['orders'],
		'title' => __( 'Your Orders', 'yazan' ),
		'desc'  => __( 'Revisit the pieces you have acquired and follow any that are on their way.', 'yazan' ),
		'url'   => wc_get_endpoint_url( 'orders' ),
	),
	array(
		'icon'  => $yz_card_icons['address'],
		'title' => __( 'Addresses', 'yazan' ),
		'desc'  => __( 'Where your rings travel to — keep your delivery and billing details current.', 'yazan' ),
		'url'   => wc_get_endpoint_url( 'edit-address' ),
	),
	array(
		'icon'  => $yz_card_icons['account'],
		'title' => __( 'Account Details', 'yazan' ),
		'desc'  => __( 'Your name, email, and password — the essentials of your Yazan account.', 'yazan' ),
		'url'   => wc_get_endpoint_url( 'edit-account' ),
	),
	array(
		'icon'  => $yz_card_icons['verify'],
		'title' => __( 'Verify a Ring', 'yazan' ),
		'desc'  => __( 'Confirm the serial and certificate of authenticity of any Yazan piece.', 'yazan' ),
		'url'   => home_url( '/verify-ring/' ),
	),
);
?>

<p class="yz-dash-greeting">
	<?php
	/* translators: %s: customer display name. */
	printf( esc_html__( 'Welcome back, %s.', 'yazan' ), '<strong>' . esc_html( $yz_name ) . '</strong>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- name is escaped inline.
	?>
	<a href="<?php echo esc_url( wc_logout_url() ); ?>"><?php esc_html_e( 'Sign out', 'yazan' ); ?></a>
</p>

<p class="yz-dash-lede">
	<?php esc_html_e( 'This is your account — a quiet place to follow your orders and keep your details in order. Each Yazan ring is a single Yemeni agate, set by hand in sterling silver, and entirely one of a kind.', 'yazan' ); ?>
</p>

<div class="yz-dash-cards">
	<?php foreach ( $yz_cards as $yz_card ) : ?>
		<a class="yz-dash-card" href="<?php echo esc_url( $yz_card['url'] ); ?>">
			<span class="yz-dash-card__icon"><?php echo $yz_card['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static first-party SVG constant. ?></span>
			<span class="yz-dash-card__title"><?php echo esc_html( $yz_card['title'] ); ?></span>
			<span class="yz-dash-card__desc"><?php echo esc_html( $yz_card['desc'] ); ?></span>
		</a>
	<?php endforeach; ?>
</div>

<?php
	/**
	 * My Account dashboard.
	 *
	 * @since 2.6.0
	 */
	do_action( 'woocommerce_account_dashboard' );

	// Deprecated actions kept for back-compat with plugins that still fire on them.
	do_action( 'woocommerce_before_my_account' );
	do_action( 'woocommerce_after_my_account' );

/* Omit closing PHP tag to avoid "headers already sent" issues. */
