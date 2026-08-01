<?php
/**
 * Login / Register Form — Yazan override.
 *
 * This template is now a placement, not a design. Everything inside the card — the two-step email
 * flow, the fields, the provider tiles, the no-JavaScript fallback — is `yazan_signin_card()` in
 * inc/signin-card.php, and the very same call renders the dialog the header account icon opens on
 * every other page of the store. One component, two surfaces: the account page and the dialog
 * cannot drift apart, because there is only one of them.
 *
 * WooCommerce ships login and register as side-by-side columns, which meant the page showed two
 * forms asking for the same email and password. The card asks for the address once and decides
 * which of the two the shopper actually needs.
 *
 * All form logic is still untouched core: field names, nonces (woocommerce-login /
 * woocommerce-register) and every do_action hook are emitted by the card, so authentication and
 * third-party integrations keep working — including the social sign-in buttons, which the card
 * renders itself as icon tiles rather than through woocommerce_login_form_start.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package Yazan
 * @version 9.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

do_action( 'woocommerce_before_customer_login_form' );
?>

<div class="yz-signin-page">
	<?php
	// h1 here: on this page the card's title is the page's own heading.
	yazan_signin_card(
		array(
			'instance' => 'page',
			'tag'      => 'h1',
		)
	);
	?>
</div>

<?php do_action( 'woocommerce_after_customer_login_form' ); ?>
