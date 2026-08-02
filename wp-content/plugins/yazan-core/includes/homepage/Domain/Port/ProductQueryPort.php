<?php
/**
 * WooCommerce boundary.
 *
 * Product-backed components never touch wc_get_products() directly: they describe WHAT they want
 * with a ProductQuerySpec and this port decides HOW. That is what makes a recommendation engine or
 * a personalisation layer a new strategy rather than a rewrite of every component.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves product source specifications to product data.
 */
interface ProductQueryPort {

	/**
	 * @param array $spec  source, limit, category, tag, attribute, ids, orderby, order.
	 * @return array<int,array>
	 */
	public function resolve( array $spec );

	/** @return string[] Registered source keys. */
	public function sources();
}
