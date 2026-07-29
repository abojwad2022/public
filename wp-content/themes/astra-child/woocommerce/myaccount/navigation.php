<?php
/**
 * My Account navigation — Yazan override.
 *
 * Adds a brand identity block (monogram · name · member-since) above WooCommerce's account menu,
 * a small line icon per endpoint, and a "Verify a Ring" utility link to the public serial-lookup
 * page. Endpoint list, URLs, active state and labels remain WooCommerce's (labels are relabelled
 * via the woocommerce_account_menu_items filter in inc/my-account.php) so this stays update-safe.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package Yazan
 * @version 9.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

do_action( 'woocommerce_before_account_navigation' );

$yz_user    = wp_get_current_user();
$yz_name    = $yz_user->display_name ? $yz_user->display_name : $yz_user->user_login;
$yz_initial = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $yz_name, 0, 1 ) ) : strtoupper( substr( $yz_name, 0, 1 ) );
$yz_since   = $yz_user->user_registered ? date_i18n( 'Y', strtotime( $yz_user->user_registered ) ) : '';

/*
 * Static, first-party inline icons (stroke = currentColor). Keyed by account endpoint. These are
 * hardcoded constants — not user input — so they are printed verbatim; there is nothing to escape.
 */
$yz_icons = array(
	'dashboard'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>',
	'orders'          => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>',
	'downloads'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/></svg>',
	'edit-address'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>',
	'edit-account'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>',
	'customer-logout' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/></svg>',
	'verify'          => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg>',
	'favourites'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20.8 8.6c0 5.4-8.8 11-8.8 11s-8.8-5.6-8.8-11a4.8 4.8 0 0 1 8.8-2.7 4.8 4.8 0 0 1 8.8 2.7Z"/></svg>',
);
?>

<nav class="woocommerce-MyAccount-navigation" aria-label="<?php esc_attr_e( 'Account pages', 'yazan' ); ?>">

	<div class="yz-account-id">
		<span class="yz-account-id__avatar" aria-hidden="true"><?php echo esc_html( $yz_initial ); ?></span>
		<span class="yz-account-id__name"><?php echo esc_html( $yz_name ); ?></span>
		<?php if ( $yz_since ) : ?>
			<span class="yz-account-id__since">
				<?php
				/* translators: %s: year the customer registered. */
				printf( esc_html__( 'Member since %s', 'yazan' ), esc_html( $yz_since ) );
				?>
			</span>
		<?php endif; ?>
	</div>

	<ul>
		<?php foreach ( wc_get_account_menu_items() as $endpoint => $label ) : ?>
			<li class="<?php echo esc_attr( wc_get_account_menu_item_classes( $endpoint ) ); ?>">
				<a href="<?php echo esc_url( wc_get_account_endpoint_url( $endpoint ) ); ?>" <?php echo wc_is_current_account_menu_item( $endpoint ) ? 'aria-current="page"' : ''; ?>>
					<?php
					if ( isset( $yz_icons[ $endpoint ] ) ) {
						echo $yz_icons[ $endpoint ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static first-party SVG constant.
					}
					?>
					<span><?php echo esc_html( $label ); ?></span>
				</a>
			</li>
		<?php endforeach; ?>

		<li class="yz-account-nav__extra">
			<a href="<?php echo esc_url( home_url( '/verify-ring/' ) ); ?>">
				<?php echo $yz_icons['verify']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static first-party SVG constant. ?>
				<span><?php esc_html_e( 'Verify a Ring', 'yazan' ); ?></span>
			</a>
		</li>
	</ul>

</nav>

<?php do_action( 'woocommerce_after_account_navigation' ); ?>
