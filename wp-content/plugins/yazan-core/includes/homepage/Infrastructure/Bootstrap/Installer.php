<?php
/**
 * Install / upgrade for the Homepage Manager.
 *
 * Runs on `init` priority 2 — right after Yazan_RBAC_Boot::maybe_install() at priority 1, so the
 * RBAC tables and the permission catalog exist before this module asks anything of them. The store
 * can be operated entirely from /dashboard without ever loading wp-admin, so relying on
 * `admin_init` (which is where Yazan_Core_Installer lives) would mean the tables might never be
 * created at all.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Infrastructure\Bootstrap;

use Yazan\Homepage\Infrastructure\Permission\PermissionCatalog;
use Yazan\Homepage\Infrastructure\Persistence\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Idempotent installer.
 */
final class Installer {

	/** Autoloaded install signature — read on every request, so it must be cheap. */
	const READY_OPTION = 'yazan_homepage_ready';

	/** Short lock so two concurrent first requests do not both install. */
	const LOCK_TRANSIENT = 'yazan_homepage_installing';

	/**
	 * Install when the stored signature does not match this build.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		$signature = self::signature();

		if ( get_option( self::READY_OPTION ) === $signature ) {
			return;
		}

		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return;
		}

		set_transient( self::LOCK_TRANSIENT, 1, MINUTE_IN_SECONDS );
		self::install();
		delete_transient( self::LOCK_TRANSIENT );
	}

	/**
	 * What this build expects to find installed: the table schema plus a fingerprint of every
	 * permission this module contributes.
	 *
	 * Folding the permission fingerprint in is what makes "register a component, get its
	 * permission" true without anyone remembering to bump a version constant by hand.
	 *
	 * @return string
	 */
	public static function signature() {
		return Schema::SCHEMA_VERSION . ':' . PermissionCatalog::signature( ComponentBootstrap::registry() );
	}

	/**
	 * Create the tables and make sure the permission catalog knows about this module.
	 *
	 * @return bool
	 */
	public static function install() {
		if ( ! Schema::install() ) {
			return false;
		}

		/*
		 * Force a catalog re-sync. The platform normally syncs only when REGISTRY_VERSION changes;
		 * our slugs change when COMPONENTS change, which that constant knows nothing about.
		 */
		if ( class_exists( 'Yazan_Permission_Registry' ) ) {
			\Yazan_Permission_Registry::sync_catalog( true );
		}

		if ( class_exists( 'Yazan_Permissions' ) ) {
			\Yazan_Permissions::bump();
		}

		update_option( self::READY_OPTION, self::signature(), true );

		return true;
	}

	/**
	 * Health summary, mirroring Yazan_RBAC_Boot::status().
	 *
	 * @return array
	 */
	public static function status() {
		$registry = ComponentBootstrap::registry();

		return array(
			'installed'      => Schema::is_installed(),
			'schema_version' => (string) get_option( Schema::SCHEMA_OPTION, '' ),
			'components'     => count( $registry->all() ),
			'permissions'    => count( $registry->permissions() ) + count( PermissionCatalog::actions() ),
		);
	}
}
