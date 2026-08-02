<?php
/**
 * Yazan Dashboard — activity log viewer (Phase 4).
 *
 * Read-only over the audit trail, plus a purge for retention. The log is append-only by design:
 * individual entries can never be edited or deleted through the API, only aged out in bulk — an
 * audit trail you can quietly edit row-by-row is not an audit trail.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * /audit endpoints.
 */
class Yazan_REST_Audit {

	/** Reading the log needs WooCommerce-admin level trust. */
	const CAP = 'manage_woocommerce';

	/** Purging history is destructive — restrict to full administrators. */
	const CAP_PURGE = 'manage_options';

	/**
	 * Register routes. Hook: rest_api_init.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$ns = Yazan_Dashboard_Auth::NS;

		register_rest_route(
			$ns,
			'/audit',
			Yazan_REST_Guard::args( WP_REST_Server::READABLE, array( __CLASS__, 'index' ), 'audit.view' )
		);

		register_rest_route(
			$ns,
			'/audit/purge',
			Yazan_REST_Guard::args( WP_REST_Server::CREATABLE, array( __CLASS__, 'purge' ), 'audit.purge' )
		);
	}

	/**
	 * GET /audit — filtered, paginated log.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function index( WP_REST_Request $request ) {
		$result = Yazan_Dashboard_Audit::query(
			array(
				'search'      => $request->get_param( 'search' ),
				'action'      => $request->get_param( 'action_filter' ),
				'object_type' => $request->get_param( 'object_type' ),
				'user_id'     => $request->get_param( 'user_id' ),
				'object_id'   => $request->get_param( 'object_id' ),
				'date_from'   => $request->get_param( 'date_from' ),
				'date_to'     => $request->get_param( 'date_to' ),
				'page'        => $request->get_param( 'page' ),
				'per_page'    => $request->get_param( 'per_page' ),
			)
		);

		$users = array();
		$items = array();

		foreach ( $result['items'] as $row ) {
			$uid = (int) $row->user_id;
			if ( $uid && ! isset( $users[ $uid ] ) ) {
				$user           = get_userdata( $uid );
				$users[ $uid ]  = $user ? $user->display_name : sprintf( '#%d', $uid );
			}

			$items[] = array(
				'id'          => (int) $row->id,
				'user_id'     => $uid,
				'user'        => $uid ? $users[ $uid ] : __( 'System', 'yazan' ),
				'action'      => $row->action,
				'object_type' => $row->object_type,
				'object_id'   => (int) $row->object_id,
				'changes'     => self::decode( $row->changes ),
				'ip'          => $row->ip,
				// Present only on rows written since schema v2; older entries return ''.
				'user_agent'  => isset( $row->user_agent ) ? (string) $row->user_agent : '',
				'store_id'    => (int) ( $row->store_id ?? 1 ),
				'date'        => $row->created_at,
			);
		}

		return new WP_REST_Response(
			array(
				'items'       => $items,
				'total'       => $result['total'],
				'total_pages' => $result['total_pages'],
				'page'        => $result['page'],
				'per_page'    => $result['per_page'],
				'facets'      => Yazan_Dashboard_Audit::facets(),
			),
			200
		);
	}

	/**
	 * POST /audit/purge — delete entries older than N days.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function purge( WP_REST_Request $request ) {
		$days = absint( $request->get_param( 'days' ) );
		if ( $days < 1 ) {
			return new WP_Error(
				'yazan_invalid',
				__( 'Specify how many days of history to keep (at least 1).', 'yazan' ),
				array( 'status' => 400 )
			);
		}

		$removed = Yazan_Dashboard_Audit::purge_older_than( $days );

		// Record the purge itself, so the trail always explains its own gaps.
		Yazan_Dashboard_Audit::log( 'audit.purge', 'settings', 0, array( 'days_kept' => $days, 'removed' => $removed ) );

		return new WP_REST_Response( array( 'removed' => $removed, 'days_kept' => $days ), 200 );
	}

	/**
	 * Decode the stored JSON diff back to an array (never throws on bad data).
	 *
	 * @param string|null $json Raw column value.
	 * @return array
	 */
	private static function decode( $json ) {
		if ( empty( $json ) ) {
			return array();
		}
		$decoded = json_decode( (string) $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
