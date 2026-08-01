<?php
/**
 * Yazan Dashboard — inventory bulk editing (Phase 2).
 *
 * Saves stock changes for many products in one request, so the Inventory screen can behave like a
 * spreadsheet: edit several rows, press save once.
 *
 * Two WooCommerce behaviours are handled explicitly, because getting them wrong is the classic
 * inventory bug:
 *  1. For a stock-MANAGED product WooCommerce derives stock_status from the quantity on save, so a
 *     status sent on its own would be silently discarded — the quantity is moved with it.
 *  2. Variable products hold stock on their variations, so setting a quantity on the parent is
 *     meaningless; those rows are reported as skipped rather than pretending to succeed.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * /inventory endpoints.
 */
class Yazan_REST_Inventory {

	/** Maximum rows accepted in a single save, to bound the request. */
	const MAX_ROWS = 200;

	/**
	 * Register routes. Hook: rest_api_init.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			Yazan_Dashboard_Auth::NS,
			'/inventory/bulk',
			Yazan_REST_Guard::args( WP_REST_Server::CREATABLE, array( __CLASS__, 'bulk_save' ), 'inventory.edit' )
		);
	}

	/**
	 * POST /inventory/bulk — apply per-row stock changes.
	 *
	 * Body: { items: [ { id, manage_stock?, stock_quantity?, stock_status?, low_stock_amount? } ] }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function bulk_save( WP_REST_Request $request ) {
		$rows = (array) $request->get_param( 'items' );
		if ( empty( $rows ) ) {
			return new WP_Error( 'yazan_invalid', __( 'Nothing to save.', 'yazan' ), array( 'status' => 400 ) );
		}
		if ( count( $rows ) > self::MAX_ROWS ) {
			return new WP_Error(
				'yazan_too_many',
				sprintf(
					/* translators: %d: maximum number of rows. */
					__( 'Please save at most %d rows at a time.', 'yazan' ),
					self::MAX_ROWS
				),
				array( 'status' => 400 )
			);
		}

		$updated = array();
		$skipped = array();

		foreach ( $rows as $row ) {
			$id      = absint( $row['id'] ?? 0 );
			$product = $id ? wc_get_product( $id ) : null;

			if ( ! $product instanceof WC_Product ) {
				$skipped[] = array( 'id' => $id, 'reason' => 'not_found' );
				continue;
			}
			if ( $product->is_type( 'variable' ) ) {
				$skipped[] = array(
					'id'     => $id,
					'name'   => $product->get_name(),
					'reason' => 'variable_parent',
				);
				continue;
			}

			if ( array_key_exists( 'manage_stock', $row ) ) {
				$product->set_manage_stock( wc_string_to_bool( $row['manage_stock'] ) );
			}

			$manages = $product->get_manage_stock();

			if ( $manages && array_key_exists( 'stock_quantity', $row ) ) {
				$qty = $row['stock_quantity'];
				$product->set_stock_quantity( ( '' === $qty || null === $qty ) ? null : wc_stock_amount( $qty ) );
			}

			if ( array_key_exists( 'stock_status', $row ) ) {
				$status = sanitize_key( (string) $row['stock_status'] );
				if ( in_array( $status, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
					// Managed stock derives its status from the quantity — move the quantity too,
					// otherwise WooCommerce recalculates and throws this status away.
					if ( $manages && ! array_key_exists( 'stock_quantity', $row ) ) {
						if ( 'outofstock' === $status ) {
							$product->set_stock_quantity( 0 );
						} elseif ( 'instock' === $status && (int) $product->get_stock_quantity() <= 0 ) {
							$product->set_stock_quantity( 1 );
						}
					}
					$product->set_stock_status( $status );
				}
			}

			if ( array_key_exists( 'low_stock_amount', $row ) ) {
				$low = $row['low_stock_amount'];
				$product->set_low_stock_amount( ( '' === $low || null === $low ) ? '' : wc_stock_amount( $low ) );
			}

			$product->save();

			$fresh     = wc_get_product( $id );
			$updated[] = self::row( $fresh );
		}

		Yazan_Dashboard_Audit::log(
			'inventory.bulk',
			'product',
			0,
			array( 'updated' => count( $updated ), 'skipped' => count( $skipped ) )
		);

		return new WP_REST_Response(
			array(
				'updated' => $updated,
				'skipped' => $skipped,
				'count'   => count( $updated ),
			),
			200
		);
	}

	/**
	 * Post-save row echoed back so the UI can re-sync from the server's truth.
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	private static function row( WC_Product $product ) {
		return array(
			'id'               => $product->get_id(),
			'name'             => $product->get_name(),
			'sku'              => $product->get_sku(),
			'manage_stock'     => $product->get_manage_stock(),
			'stock_quantity'   => $product->get_stock_quantity(),
			'stock_status'     => $product->get_stock_status(),
			'low_stock_amount' => $product->get_low_stock_amount(),
		);
	}
}
