<?php
/**
 * YAZAN product eligibility.
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

namespace Yazan\PaymentBridge\Payments;

use Yazan\PaymentBridge\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides which line items on an order require YAZAN services.
 *
 * Two independent signals, either of which qualifies a product:
 *
 *  1. The product carries a YAZAN serial (`_yz_serial`). This is the store's
 *     real identity scheme — yazan-core writes it and the public /verify-ring/
 *     certificate lookup reads it — so it is the primary signal.
 *  2. The SKU matches the configured pattern, applied as a strict anchored
 *     regular expression (never a substring test).
 *
 * Product meta is read through the WooCommerce CRUD API. HPOS concerns orders,
 * not products, but going through the data store keeps the plugin uniform.
 */
final class ProductEligibilityService {

	/** Product meta key holding the ring's serial number. */
	public const SERIAL_META = '_yz_serial';

	/**
	 * @param Settings $settings Settings reader (supplies the SKU pattern).
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * The meta key to read, preferring yazan-core's own constant when that
	 * plugin is present so the two can never drift apart.
	 *
	 * @return string
	 */
	public function serial_meta_key(): string {
		if ( class_exists( 'Yazan_Core_Verify' ) && defined( 'Yazan_Core_Verify::SERIAL_META' ) ) {
			return (string) constant( 'Yazan_Core_Verify::SERIAL_META' );
		}

		return self::SERIAL_META;
	}

	/**
	 * Evaluate every line item on an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return array{eligible:bool,items:array<int,array<string,mixed>>}
	 */
	public function evaluate( \WC_Order $order ): array {
		$items = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$serial = $this->resolve_serial( $product );
			$sku    = $this->resolve_sku( $product );

			if ( '' === $serial && ! $this->sku_matches( $sku ) ) {
				continue;
			}

			$items[] = array(
				'product_id' => $product->get_id(),
				'parent_id'  => (int) $product->get_parent_id(),
				'serial'     => $serial,
				'sku'        => $sku,
				'quantity'   => (int) $item->get_quantity(),
			);
		}

		/**
		 * Filter the eligible line items resolved for an order.
		 *
		 * @param array<int,array<string,mixed>> $items Eligible items.
		 * @param \WC_Order                      $order Order.
		 */
		$items = (array) apply_filters( 'yazan_payment_bridge/eligible_items', $items, $order );

		return array(
			'eligible' => array() !== $items,
			'items'    => $items,
		);
	}

	/**
	 * Whether an order contains at least one eligible product.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public function is_eligible( \WC_Order $order ): bool {
		$result = $this->evaluate( $order );
		return (bool) $result['eligible'];
	}

	/**
	 * Strict anchored SKU match. An empty or invalid pattern matches nothing,
	 * so eligibility then depends on the serial alone.
	 *
	 * @param string $sku Raw SKU.
	 * @return bool
	 */
	public function sku_matches( string $sku ): bool {
		$sku = strtoupper( trim( $sku ) );
		if ( '' === $sku ) {
			return false;
		}

		$pattern = $this->settings->sku_pattern();
		if ( '' === $pattern ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return 1 === @preg_match( '/' . $pattern . '/', $sku );
	}

	/**
	 * Serial for a product, falling back to the parent for variations.
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	private function resolve_serial( \WC_Product $product ): string {
		$key    = $this->serial_meta_key();
		$serial = trim( (string) $product->get_meta( $key ) );

		if ( '' === $serial && $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent instanceof \WC_Product ) {
				$serial = trim( (string) $parent->get_meta( $key ) );
			}
		}

		return $serial;
	}

	/**
	 * SKU for a product, falling back to the parent for variations.
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	private function resolve_sku( \WC_Product $product ): string {
		$sku = trim( (string) $product->get_sku() );

		if ( '' === $sku && $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent instanceof \WC_Product ) {
				$sku = trim( (string) $parent->get_sku() );
			}
		}

		return $sku;
	}
}
