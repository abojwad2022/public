<?php
/**
 * Yazan Dashboard — full-site backup & restore REST controller (yazan/v1).
 *
 * Thin REST surface over {@see Yazan_Backup_Engine}. Every route requires `manage_options`, because a
 * backup archive contains the entire database — password hashes, secret keys, customer data — and a
 * restore overwrites the whole site. This is the single most privileged surface in the dashboard, so
 * it is deliberately administrator-only, one step above the store-manager routes elsewhere.
 *
 * Large-file download is a two-step dance: the SPA authenticates with its nonce to mint a short-lived,
 * single-use token, then the browser navigates to a plain URL carrying that token. This lets a ~700 MB
 * archive stream straight from disk with a `Content-Disposition` header, instead of being marshalled
 * through fetch() into a Blob (which would blow the browser's memory budget).
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * /backup endpoints.
 */
class Yazan_REST_Backup {

	/** Backups expose the whole database and overwrite the whole site — administrator only. */
	const CAP = 'manage_options';

	/** Transient prefix for one-time download tokens. */
	const TOKEN_PREFIX = 'yazan_backup_dl_';

	/** Download token lifetime (seconds). */
	const TOKEN_TTL = 120;

	/**
	 * Register routes. Hook: rest_api_init.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$ns = Yazan_Dashboard_Auth::NS;

		register_rest_route(
			$ns,
			'/backup',
			array(
				Yazan_REST_Guard::args( WP_REST_Server::READABLE, array( __CLASS__, 'index' ), 'backup.view' ),
				Yazan_REST_Guard::args( WP_REST_Server::CREATABLE, array( __CLASS__, 'create' ), 'backup.create' ),
			)
		);

		register_rest_route(
			$ns,
			'/backup/(?P<id>[A-Za-z0-9\-]+)',
			Yazan_REST_Guard::args( WP_REST_Server::DELETABLE, array( __CLASS__, 'delete' ), 'backup.delete' )
		);

		register_rest_route(
			$ns,
			'/backup/(?P<id>[A-Za-z0-9\-]+)/restore',
			Yazan_REST_Guard::args( WP_REST_Server::CREATABLE, array( __CLASS__, 'restore' ), 'backup.restore' )
		);

		register_rest_route(
			$ns,
			'/backup/(?P<id>[A-Za-z0-9\-]+)/download-token',
			Yazan_REST_Guard::args( WP_REST_Server::CREATABLE, array( __CLASS__, 'download_token' ), 'backup.download' )
		);

		// Token-authenticated binary stream — no nonce (it's a top-level browser navigation), so
		// RBAC cannot apply here either. The permission check happened when download_token() minted
		// the single-use 120-second token, and download() re-verifies the user from its payload.
		register_rest_route(
			$ns,
			'/backup/download',
			Yazan_REST_Guard::public_args(
				WP_REST_Server::READABLE,
				array( __CLASS__, 'download' ),
				'Top-level navigation with no nonce; authorised by a single-use signed token instead.'
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Read                                                                    */
	/* --------------------------------------------------------------------- */

	/**
	 * GET /backup — environment + the stored backup list.
	 *
	 * @return WP_REST_Response
	 */
	public static function index() {
		return new WP_REST_Response(
			array(
				'supported'   => Yazan_Backup_Engine::is_supported(),
				'backups'     => Yazan_Backup_Engine::all(),
				'upload_max'  => wp_max_upload_size(),
				'admin_url'   => admin_url( 'tools.php?page=' . Yazan_Backup_Admin::SLUG ),
			),
			200
		);
	}

	/* --------------------------------------------------------------------- */
	/* Create                                                                  */
	/* --------------------------------------------------------------------- */

	/**
	 * POST /backup — build a new backup. Body: { scope: 'full'|'db', keep?: int }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create( WP_REST_Request $request ) {
		$scope = 'db' === sanitize_key( (string) $request->get_param( 'scope' ) ) ? 'db' : 'full';

		$result = Yazan_Backup_Engine::create( $scope );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Optional retention: keep only the newest N archives.
		$keep = $request->get_param( 'keep' );
		if ( null !== $keep ) {
			Yazan_Backup_Engine::prune( absint( $keep ) );
		}

		Yazan_Dashboard_Audit::log( 'backup.create', 'settings', 0, array( 'scope' => $scope, 'id' => $result['id'], 'size' => $result['size'] ) );

		return new WP_REST_Response(
			array(
				'backup'  => $result,
				'backups' => Yazan_Backup_Engine::all(),
			),
			201
		);
	}

	/* --------------------------------------------------------------------- */
	/* Delete                                                                  */
	/* --------------------------------------------------------------------- */

	/**
	 * DELETE /backup/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete( WP_REST_Request $request ) {
		$id = (string) $request->get_param( 'id' );
		if ( ! Yazan_Backup_Engine::path_for( $id ) ) {
			return new WP_Error( 'yazan_not_found', __( 'That backup could not be found.', 'yazan' ), array( 'status' => 404 ) );
		}

		Yazan_Backup_Engine::delete( $id );
		Yazan_Dashboard_Audit::log( 'backup.delete', 'settings', 0, array( 'id' => $id ) );

		return new WP_REST_Response( array( 'deleted' => true, 'backups' => Yazan_Backup_Engine::all() ), 200 );
	}

	/* --------------------------------------------------------------------- */
	/* Restore                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * POST /backup/{id}/restore — overwrite the site from a stored backup.
	 *
	 * Body: { confirm: 'RESTORE', safety?: bool }. The typed confirmation is a deliberate speed-bump
	 * on an irreversible, whole-site operation.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function restore( WP_REST_Request $request ) {
		$id = (string) $request->get_param( 'id' );
		if ( ! Yazan_Backup_Engine::path_for( $id ) ) {
			return new WP_Error( 'yazan_not_found', __( 'That backup could not be found.', 'yazan' ), array( 'status' => 404 ) );
		}

		if ( 'RESTORE' !== (string) $request->get_param( 'confirm' ) ) {
			return new WP_Error( 'yazan_confirm', __( 'Restore must be confirmed — this overwrites your live site and cannot be undone.', 'yazan' ), array( 'status' => 400 ) );
		}

		$safety = null === $request->get_param( 'safety' ) ? true : wc_string_to_bool( $request->get_param( 'safety' ) );

		Yazan_Dashboard_Audit::log( 'backup.restore', 'settings', 0, array( 'id' => $id ) );

		$result = Yazan_Backup_Engine::restore( $id, $safety );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( array( 'restored' => true, 'summary' => $result ), 200 );
	}

	/* --------------------------------------------------------------------- */
	/* Download (two-step token flow)                                          */
	/* --------------------------------------------------------------------- */

	/**
	 * POST /backup/{id}/download-token — mint a single-use, short-lived download token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function download_token( WP_REST_Request $request ) {
		$id = (string) $request->get_param( 'id' );
		if ( ! Yazan_Backup_Engine::path_for( $id ) ) {
			return new WP_Error( 'yazan_not_found', __( 'That backup could not be found.', 'yazan' ), array( 'status' => 404 ) );
		}

		/*
		 * Bind the token to the client that asked for it.
		 *
		 * This token is an unauthenticated bearer credential for a full-database archive, and it
		 * travels in a URL query string — so it lands in nginx access logs, in browser history,
		 * and in the Referer header of anything the download page subsequently loads. Single use
		 * and a 120-second TTL bound the window; binding to the requesting client bounds who can
		 * use it inside that window.
		 *
		 * The user agent is hashed rather than stored: it is request-supplied data with no reason
		 * to sit in the options table in the clear, and only equality is ever needed.
		 */
		$token = wp_generate_password( 40, false, false );
		set_transient(
			self::TOKEN_PREFIX . $token,
			array(
				'id'   => $id,
				'user' => get_current_user_id(),
				'ip'   => self::client_ip(),
				'ua'   => self::client_ua_hash(),
			),
			self::TOKEN_TTL
		);

		return new WP_REST_Response(
			array(
				'token' => $token,
				'url'   => add_query_arg( 'token', $token, rest_url( Yazan_Dashboard_Auth::NS . '/backup/download' ) ),
				'ttl'   => self::TOKEN_TTL,
			),
			200
		);
	}

	/**
	 * GET /backup/download?token=… — stream the archive, then burn the token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Error|void Exits on success.
	 */
	public static function download( WP_REST_Request $request ) {
		$token = (string) $request->get_param( 'token' );
		$key   = self::TOKEN_PREFIX . preg_replace( '/[^A-Za-z0-9]/', '', $token );
		$data  = $token ? get_transient( $key ) : false;

		if ( ! is_array( $data ) || empty( $data['id'] ) ) {
			return new WP_Error( 'yazan_bad_token', __( 'This download link has expired. Please try again.', 'yazan' ), array( 'status' => 403 ) );
		}
		delete_transient( $key ); // Single use.

		/*
		 * The token only works from the client that minted it. A leaked link — copied out of an
		 * access log, a Referer header or shoulder-surfed from the URL bar — is inert elsewhere.
		 *
		 * Tokens minted before this check shipped carry no 'ip'/'ua' and are allowed through on
		 * those keys alone; they expire within two minutes anyway.
		 */
		if ( isset( $data['ip'] ) && $data['ip'] !== self::client_ip() ) {
			return new WP_Error( 'yazan_bad_token', __( 'This download link has expired. Please try again.', 'yazan' ), array( 'status' => 403 ) );
		}

		if ( isset( $data['ua'] ) && ! hash_equals( (string) $data['ua'], self::client_ua_hash() ) ) {
			return new WP_Error( 'yazan_bad_token', __( 'This download link has expired. Please try again.', 'yazan' ), array( 'status' => 403 ) );
		}

		// Re-check the capability of the user the token was minted for — belt and braces.
		if ( ! user_can( (int) $data['user'], self::CAP ) ) {
			return new WP_Error( 'yazan_forbidden', __( 'You are not allowed to download backups.', 'yazan' ), array( 'status' => 403 ) );
		}

		$path = Yazan_Backup_Engine::path_for( (string) $data['id'] );
		if ( ! $path ) {
			return new WP_Error( 'yazan_not_found', __( 'That backup could not be found.', 'yazan' ), array( 'status' => 404 ) );
		}

		self::stream_file( $path );
	}

	/**
	 * The requesting client's IP.
	 *
	 * REMOTE_ADDR only — proxy headers are attacker-controlled unless a specific, trusted reverse
	 * proxy is known to rewrite them, and there is none here. The same choice is made in
	 * Yazan_Dashboard_Auth::client_ip() for the login rate limiter.
	 *
	 * @return string
	 */
	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		$ip = filter_var( $ip, FILTER_VALIDATE_IP );

		return $ip ? (string) $ip : '0.0.0.0';
	}

	/**
	 * A stable hash of the requesting client's user agent.
	 *
	 * @return string
	 */
	private static function client_ua_hash() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';

		return hash( 'sha256', $ua );
	}

	/**
	 * Stream a file to the browser as a download and exit. Shared with the wp-admin page.
	 *
	 * @param string $path Absolute file path (already validated to be within the backups dir).
	 * @return void
	 */
	public static function stream_file( $path ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );

		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( $handle ) {
			while ( ! feof( $handle ) ) {
				echo fread( $handle, 1024 * 512 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw binary stream.
				flush();
			}
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		exit;
	}
}
