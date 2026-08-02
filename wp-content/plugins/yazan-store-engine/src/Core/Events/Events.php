<?php
/**
 * Event name constants.
 *
 * Constants rather than literals so a typo is a fatal at the call site instead of a subscriber
 * that silently never fires.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Core\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Domain events published by the store engine.
 */
final class Events {

	public const STORE_CREATED   = 'store/created';
	public const STORE_UPDATED   = 'store/updated';
	public const STORE_ACTIVATED = 'store/activated';
	public const STORE_SUSPENDED = 'store/suspended';
	public const STORE_ARCHIVED  = 'store/archived';
	public const STORE_CLONED    = 'store/cloned';
	public const USERS_CHANGED   = 'store/users_changed';
	public const DOMAIN_ADDED    = 'store/domain_added';
	public const DOMAIN_REMOVED  = 'store/domain_removed';
	public const SETTING_CHANGED = 'store/setting_changed';

	/** Fired after the hostmap option has been rewritten from the registry. */
	public const HOSTMAP_PROJECTED = 'store/hostmap_projected';
}
