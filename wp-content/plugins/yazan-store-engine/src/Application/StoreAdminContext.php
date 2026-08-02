<?php
/**
 * Which store an administrative request is acting on.
 *
 * THE DISTINCTION THIS CLASS EXISTS TO MAKE
 * -----------------------------------------
 * There are two different questions, and conflating them is how a multi-store admin panel becomes
 * a privilege-escalation surface:
 *
 *   1. "Which store is this REQUEST for?" — answered by the tenancy kernel from the Host header,
 *      before any plugin loads, and frozen. That is what the storefront renders.
 *   2. "Which store is this ADMINISTRATOR acting on?" — a choice they make in the dashboard, which
 *      has nothing to do with the hostname they happen to be viewing it from.
 *
 * The kernel cannot answer (2). It resolves on `muplugins_loaded` — before `determine_current_user()`
 * — so it does not know who is asking, and its own rules forbid it from calling the capability API
 * at all (any such call re-enters the `user_has_cap` filter and recurses until the process dies).
 * A kernel rung that read a cookie or user-meta would therefore have to trust it WITHOUT a
 * permission check, which is a primitive for becoming any store's administrator by writing one
 * value.
 *
 * So (2) is a REQUEST PARAMETER, not a kernel fact. Three steps, in this order, every time:
 *
 *   1. read the requested store (`?store=`, defaulting to the active one);
 *   2. check the permission IN THAT STORE — `Yazan_Permissions::can( $perm, null, $store_id )`;
 *   3. run the handler inside `Yazan_Store_Context::run_for( $store_id, … )`.
 *
 * Step 2 before step 3 is what makes this unable to fail open: a caller with no rights in the
 * target store is refused before any code runs with that store active. And because the check is
 * per-store, an administrator of store A gains nothing by asking for store B.
 *
 * ⚠️ This NEVER changes what the storefront renders. It scopes admin reads and writes only.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Application;

use Yazan\Stores\Core\Contracts\StoreRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves and authorises the store an admin request targets.
 */
final class StoreAdminContext {

	/** Query parameter naming the target store. */
	public const PARAM = 'store';

	/** Header equivalent, for clients that prefer not to touch the query string. */
	public const HEADER = 'X-Yazan-Store';

	public function __construct( private StoreRepositoryInterface $repository ) {}

	/**
	 * The store this request targets.
	 *
	 * Defaults to the kernel's answer, so a client that knows nothing about multi-store keeps
	 * working unchanged.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return int
	 */
	public function requested_store( \WP_REST_Request $request ): int {
		$raw = $request->get_param( self::PARAM );

		if ( null === $raw || '' === $raw ) {
			$raw = $request->get_header( self::HEADER );
		}

		if ( null === $raw || '' === $raw ) {
			return $this->active_store();
		}

		$store_id = absint( $raw );

		return $store_id > 0 ? $store_id : $this->active_store();
	}

	/**
	 * The kernel's answer for this request.
	 *
	 * @return int
	 */
	public function active_store(): int {
		return class_exists( 'Yazan_Store_Context' ) ? \Yazan_Store_Context::current() : 1;
	}

	/**
	 * May this user do this, in this store?
	 *
	 * Fails CLOSED when RBAC is unavailable: an engine that granted access because the authority was
	 * missing would be a fail-open, and a fail-open is invisible with one tenant and a breach with
	 * two.
	 *
	 * @param string $permission Slug.
	 * @param int    $store_id   Target store.
	 * @return true|\WP_Error
	 */
	public function authorise( string $permission, int $store_id ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'yazan_stores_unauthenticated',
				__( 'You must be signed in.', 'yazan-stores' ),
				array( 'status' => 401 )
			);
		}

		if ( ! class_exists( 'Yazan_Permissions' ) ) {
			return new \WP_Error(
				'yazan_stores_no_rbac',
				__( 'Permissions are unavailable, so this request was refused.', 'yazan-stores' ),
				array( 'status' => 503 )
			);
		}

		/*
		 * A store that does not exist is refused as "not found" rather than "forbidden": the caller
		 * may legitimately hold the permission, and confirming which ids exist is not information
		 * the error needs to carry either way.
		 */
		if ( null === $this->repository->find( $store_id ) ) {
			return new \WP_Error(
				'yazan_store_not_found',
				__( 'That store does not exist.', 'yazan-stores' ),
				array( 'status' => 404 )
			);
		}

		// THE CHECK. The third argument is the whole point — permission is asked for IN the target
		// store, so administering store A grants nothing over store B.
		if ( ! \Yazan_Permissions::can( $permission, null, $store_id ) ) {
			return new \WP_Error(
				'yazan_stores_forbidden',
				__( 'You do not have permission to do that in this store.', 'yazan-stores' ),
				array(
					'status'     => 403,
					'permission' => $permission,
					'store'      => $store_id,
				)
			);
		}

		return true;
	}

	/**
	 * Authorise, then run the callback with that store active.
	 *
	 * The single entry point every admin route uses, so the ordering (authorise → switch → run)
	 * cannot be got wrong at a call site.
	 *
	 * `run_for()` restores the previous store in a `finally`, so a handler that throws cannot leave
	 * the rest of the request re-tenanted.
	 *
	 * @param \WP_REST_Request $request    Request.
	 * @param string           $permission Required permission.
	 * @param callable         $handler    fn( int $store_id ): mixed
	 * @return mixed|\WP_Error
	 */
	public function run( \WP_REST_Request $request, string $permission, callable $handler ) {
		$store_id = $this->requested_store( $request );

		$allowed = $this->authorise( $permission, $store_id );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		if ( ! class_exists( 'Yazan_Store_Context' ) ) {
			return $handler( $store_id );
		}

		return \Yazan_Store_Context::run_for( $store_id, static fn() => $handler( $store_id ) );
	}

	/**
	 * The stores this user may administer, for the switcher.
	 *
	 * Filtered by an actual per-store permission check rather than by "is an admin", so the list a
	 * user sees is exactly the list they can act on — a switcher offering a store that then refuses
	 * every write is worse than one that never offered it.
	 *
	 * Archived stores are excluded: they are finished, not merely off the web.
	 *
	 * @param string $permission Permission to require. Defaults to view.
	 * @return array<int,array<string,mixed>>
	 */
	public function switchable_stores( string $permission = StorePermissions::VIEW ): array {
		if ( ! class_exists( 'Yazan_Permissions' ) ) {
			return array();
		}

		$out = array();

		foreach ( $this->repository->all( false ) as $store ) {
			if ( \Yazan\Stores\Domain\Store\StoreStatus::ARCHIVED === $store->status() ) {
				continue;
			}

			if ( ! \Yazan_Permissions::can( $permission, null, $store->id() ) ) {
				continue;
			}

			$out[] = array(
				'id'     => $store->id(),
				'slug'   => $store->slug(),
				'name'   => $store->name(),
				'status' => $store->status(),
			);
		}

		return $out;
	}
}
