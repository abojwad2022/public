<?php
/**
 * The four simulated gateways.
 *
 * Each is pure identity — every behaviour lives in Yazan_Test_Gateway.
 *
 * @package Yazan_Test_Gateways
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Simulated card payment — stands in for WooPayments / PayPal Advanced Card.
 */
class Yazan_Test_Gateway_Card extends Yazan_Test_Gateway {

	/**
	 * Set identity, then hand off to the base constructor.
	 */
	public function __construct() {
		$this->gateway_id    = 'yz_test_card';
		$this->default_title = __( 'Credit or debit card', 'yazan-test-gateways' );
		$this->txn_prefix    = 'tst_card';

		parent::__construct();
	}
}

/**
 * Simulated Apple Pay.
 *
 * Real Apple Pay is an express button requiring HTTPS and a verified public domain,
 * so it can never run on yazan.local. This stands in as an ordinary payment option
 * purely so the post-approval flow can be tested.
 */
class Yazan_Test_Gateway_Apple_Pay extends Yazan_Test_Gateway {

	/**
	 * Set identity, then hand off to the base constructor.
	 */
	public function __construct() {
		$this->gateway_id    = 'yz_test_apple_pay';
		$this->default_title = __( 'Apple Pay', 'yazan-test-gateways' );
		$this->txn_prefix    = 'tst_applepay';

		parent::__construct();
	}
}

/**
 * Simulated Google Pay. Same constraints as Apple Pay above.
 */
class Yazan_Test_Gateway_Google_Pay extends Yazan_Test_Gateway {

	/**
	 * Set identity, then hand off to the base constructor.
	 */
	public function __construct() {
		$this->gateway_id    = 'yz_test_google_pay';
		$this->default_title = __( 'Google Pay', 'yazan-test-gateways' );
		$this->txn_prefix    = 'tst_googlepay';

		parent::__construct();
	}
}

/**
 * Simulated PayPal — no redirect to PayPal, the approval is local.
 */
class Yazan_Test_Gateway_PayPal extends Yazan_Test_Gateway {

	/**
	 * Set identity, then hand off to the base constructor.
	 */
	public function __construct() {
		$this->gateway_id    = 'yz_test_paypal';
		$this->default_title = __( 'PayPal', 'yazan-test-gateways' );
		$this->txn_prefix    = 'tst_paypal';

		parent::__construct();
	}
}
