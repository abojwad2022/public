<?php
/**
 * Yazan Dashboard — WooCommerce webhooks.
 *
 * CRUD over WC_Webhook so the store can notify external systems (fulfilment, accounting, a CRM)
 * when orders, products, customers or coupons change.
 *
 * The delivery URL is validated and restricted to http/https, and the secret is WRITE-ONLY: it can
 * be set or rotated but is never returned in a response. A webhook secret signs outgoing payloads;
 * echoing it back would let anyone who can read one API response forge deliveries.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * /webhooks endpoints.
 */
class Yazan_REST_Webhooks {

	/** Webhooks can exfiltrate store data, so they are administrator-level. */
	const CAP = 'manage_woocommerce';

	/**
	 * Topics we allow. WooCommerce accepts more, but these are the meaningful, documented ones —
	 * and an allow-list stops a typo creating a webhook that silently never fires.
	 *
	 * @return array<string,string>
	 */
	public static function topics() {
		return array(
			'order.created'    => __( 'Order created', 'yazan' ),
			'order.updated'    => __( 'Order updated', 'yazan' ),
			'order.deleted'    => __( 'Order deleted', 'yazan' ),
			'order.restored'   => __( 'Order restored', 'yazan' ),
			'product.created'  => __( 'Product created', 'yazan' ),
			'product.updated'  => __( 'Product updated', 'yazan' ),
			'product.deleted'  => __( 'Product deleted', 'yazan' ),
			'product.restored' => __( 'Product restored', 'yazan' ),
			'customer.created' => __( 'Customer created', 'yazan' ),
			'customer.updated' => __( 'Customer updated', 'yazan' ),
			'customer.deleted' => __( 'Customer deleted', 'yazan' ),
			'coupon.created'   => __( 'Coupon created', 'yazan' ),
			'coupon.updated'   => __( 'Coupon updated', 'yazan' ),
			'coupon.deleted'   => __( 'Coupon deleted', 'yazan' ),
		);
	}

	/**
	 * Register routes. Hook: rest_api_init.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$ns = Yazan_Dashboard_Auth::NS;

		register_rest_route(
			$ns,
			'/webhooks',
			array(
				Yazan_REST_Guard::args( WP_REST_Server::READABLE, array( __CLASS__, 'index' ), 'webhooks.view' ),
				Yazan_REST_Guard::args( WP_REST_Server::CREATABLE, array( __CLASS__, 'create' ), 'webhooks.create' ),
			)
		);

		register_rest_route(
			$ns,
			'/webhooks/(?P<id>\d+)',
			array(
				Yazan_REST_Guard::args( WP_REST_Server::EDITABLE, array( __CLASS__, 'update' ), 'webhooks.edit' ),
				Yazan_REST_Guard::args( WP_REST_Server::DELETABLE, array( __CLASS__, 'destroy' ), 'webhooks.delete' ),
			)
		);
	}

	/**
	 * GET /webhooks.
	 *
	 * @return WP_REST_Response
	 */
	public static function index() {
		$items = array();

		foreach ( self::all_ids() as $id ) {
			$webhook = new WC_Webhook( $id );
			if ( ! $webhook->get_id() ) {
				continue;
			}
			$items[] = self::payload( $webhook );
		}

		return new WP_REST_Response(
			array(
				'items'    => $items,
				'topics'   => self::topics(),
				'statuses' => wc_get_webhook_statuses(),
			),
			200
		);
	}

	/**
	 * POST /webhooks.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create( WP_REST_Request $request ) {
		$validated = self::validate( $request, true );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$webhook = new WC_Webhook();
		$webhook->set_name( $validated['name'] );
		$webhook->set_topic( $validated['topic'] );
		$webhook->set_delivery_url( $validated['delivery_url'] );
		$webhook->set_status( $validated['status'] );
		$webhook->set_user_id( get_current_user_id() );
		// A blank secret makes WooCommerce sign with the consumer secret; give it a real one.
		$webhook->set_secret( '' !== $validated['secret'] ? $validated['secret'] : wp_generate_password( 32, false ) );
		$webhook->save();

		Yazan_Dashboard_Audit::log(
			'webhook.create',
			'settings',
			$webhook->get_id(),
			array( 'topic' => $validated['topic'], 'url' => $validated['delivery_url'] )
		);

		return new WP_REST_Response( self::payload( new WC_Webhook( $webhook->get_id() ) ), 201 );
	}

	/**
	 * PUT /webhooks/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update( WP_REST_Request $request ) {
		$webhook = new WC_Webhook( absint( $request['id'] ) );
		if ( ! $webhook->get_id() ) {
			return self::not_found();
		}

		$validated = self::validate( $request, false );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		if ( null !== $request->get_param( 'name' ) ) {
			$webhook->set_name( $validated['name'] );
		}
		if ( null !== $request->get_param( 'topic' ) ) {
			$webhook->set_topic( $validated['topic'] );
		}
		if ( null !== $request->get_param( 'delivery_url' ) ) {
			$webhook->set_delivery_url( $validated['delivery_url'] );
		}
		if ( null !== $request->get_param( 'status' ) ) {
			$webhook->set_status( $validated['status'] );
		}
		// Only rotate the secret when a non-empty one is sent.
		if ( '' !== $validated['secret'] ) {
			$webhook->set_secret( $validated['secret'] );
		}
		$webhook->save();

		Yazan_Dashboard_Audit::log( 'webhook.update', 'settings', $webhook->get_id(), array( 'topic' => $webhook->get_topic() ) );

		return new WP_REST_Response( self::payload( new WC_Webhook( $webhook->get_id() ) ), 200 );
	}

	/**
	 * DELETE /webhooks/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function destroy( WP_REST_Request $request ) {
		$id      = absint( $request['id'] );
		$webhook = new WC_Webhook( $id );
		if ( ! $webhook->get_id() ) {
			return self::not_found();
		}

		$topic = $webhook->get_topic();
		$webhook->delete( true );

		Yazan_Dashboard_Audit::log( 'webhook.delete', 'settings', $id, array( 'topic' => $topic ) );

		return new WP_REST_Response( array( 'deleted' => true, 'id' => $id ), 200 );
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Validate the payload. `$require` enforces the fields needed to create.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param bool            $require Whether required fields must be present.
	 * @return array|WP_Error
	 */
	private static function validate( WP_REST_Request $request, $require ) {
		$name   = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$topic  = sanitize_text_field( (string) $request->get_param( 'topic' ) );
		$url    = trim( (string) $request->get_param( 'delivery_url' ) );
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$secret = (string) $request->get_param( 'secret' );

		if ( $require && '' === trim( $name ) ) {
			return new WP_Error( 'yazan_invalid', __( 'A webhook name is required.', 'yazan' ), array( 'status' => 400 ) );
		}
		if ( ( $require || '' !== $topic ) && ! array_key_exists( $topic, self::topics() ) ) {
			return new WP_Error( 'yazan_invalid', __( 'That is not a supported webhook topic.', 'yazan' ), array( 'status' => 400 ) );
		}
		if ( $require || '' !== $url ) {
			$clean_url = esc_url_raw( $url, array( 'http', 'https' ) );
			if ( '' === $clean_url || ! wp_http_validate_url( $clean_url ) ) {
				return new WP_Error(
					'yazan_invalid',
					__( 'The delivery URL must be a valid http:// or https:// address.', 'yazan' ),
					array( 'status' => 400 )
				);
			}
			$url = $clean_url;
		}
		if ( '' !== $status && ! array_key_exists( $status, wc_get_webhook_statuses() ) ) {
			return new WP_Error( 'yazan_invalid', __( 'Unknown webhook status.', 'yazan' ), array( 'status' => 400 ) );
		}

		return array(
			'name'         => $name,
			'topic'        => $topic,
			'delivery_url' => $url,
			'status'       => '' !== $status ? $status : 'active',
			'secret'       => trim( $secret ),
		);
	}

	/**
	 * Webhook shaped for the UI. The secret is never included.
	 *
	 * @param WC_Webhook $w Webhook.
	 * @return array
	 */
	private static function payload( WC_Webhook $w ) {
		return array(
			'id'            => $w->get_id(),
			'name'          => $w->get_name(),
			'status'        => $w->get_status(),
			'topic'         => $w->get_topic(),
			'delivery_url'  => $w->get_delivery_url(),
			'failure_count' => (int) $w->get_failure_count(),
			'date_created'  => $w->get_date_created() ? $w->get_date_created()->date( 'Y-m-d H:i' ) : '',
			// Presence only — the value itself is deliberately withheld.
			'has_secret'    => '' !== (string) $w->get_secret(),
		);
	}

	/**
	 * All webhook ids, via the data store (which exposes this through __call).
	 *
	 * @return int[]
	 */
	private static function all_ids() {
		$store = WC_Data_Store::load( 'webhook' );
		$ids   = $store->get_webhooks_ids();
		return is_array( $ids ) ? array_map( 'absint', $ids ) : array();
	}

	/**
	 * @return WP_Error
	 */
	private static function not_found() {
		return new WP_Error( 'yazan_not_found', __( 'Webhook not found.', 'yazan' ), array( 'status' => 404 ) );
	}
}
