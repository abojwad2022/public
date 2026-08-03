<?php
/**
 * Plugin Name: Yazan Cache Groups
 * Description: Declares how every Yazan cache group must behave once a persistent object cache exists.
 *
 * WHY THIS FILE EXISTS AT ALL
 * ---------------------------
 * Today there is no `object-cache.php` drop-in, so `wp_cache_*` is a per-request array and every one
 * of these groups is harmlessly ephemeral. The day Redis is installed, that flips — and it flips for
 * every group at once, silently, with no code change and no deploy.
 *
 * ⚠️ AND THE DEFAULT IS THE DANGEROUS DIRECTION. Under a drop-in, an unregistered group becomes
 * PERSISTENT. A memo written to hold a value for the length of one request starts outliving the
 * request, the visitor, and the store — so the next request in another tenant reads it. The page
 * returns 200 and renders correctly; it is simply the wrong shop's data. That is not a performance
 * regression, it is a data leak that arrives as a side effect of an infrastructure change nobody
 * associated with application behaviour.
 *
 * So the declaration is made NOW, while it is inert and verifiable, rather than during the Redis
 * rollout when a mistake would be indistinguishable from a Redis problem.
 *
 * WHY AN MU-PLUGIN, AND WHY SO EARLY
 * ----------------------------------
 * `wp_cache_add_non_persistent_groups()` only takes effect for reads that happen AFTER it runs.
 * yazan-core boots its modules synchronously at plugin-load and the theme reads caches on `init`, so
 * anything later than `muplugins_loaded` is already too late for some group.
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( function_exists( 'yazan_register_cache_groups' ) ) {
	return;
}

/**
 * Groups that must NOT survive the request.
 *
 * Every entry here holds something derived from the ACTIVE STORE and is keyed for one request. A
 * store id inside the key would make persistence safe — these are the ones where it is not there,
 * or where the value is meaningless to a later request even in the same store.
 *
 * @return string[]
 */
function yazan_non_persistent_cache_groups() {
	return array(
		/*
		 * Counters, not values. `yazan_log_counts` is an hourly error tally that a later request in
		 * another store must not inherit; it is rebuilt cheaply and being wrong about it is worse
		 * than losing it.
		 */
		'yazan_log_counts',
	);
}

/**
 * Groups whose keys already carry the store, and are therefore safe to persist.
 *
 * Listed explicitly rather than left to the default, because "safe by accident" and "safe by
 * decision" look identical in a diff and only one of them survives the next change.
 *
 * @return array<string,string> group => the reason it is safe.
 */
function yazan_persistent_cache_groups() {
	return array(
		'yazan_rbac'        => 'Key is user|store|version AND the group name carries the store for stores > 1.',
		'yazan_homepage'    => 'Key is yzhp:{store}:{version}:{key}.',
		'yazan_rate_limit'  => 'Key is md5(store|bucket|actor).',
		'yazan_ai_rate'     => 'Key carries the store since the scalability phase.',
		'yazan_rewards'     => 'Reserved; the class that used it is a delegation shim.',
	);
}

/**
 * Declare the groups.
 *
 * @return void
 */
function yazan_register_cache_groups() {
	if ( function_exists( 'wp_cache_add_non_persistent_groups' ) ) {
		wp_cache_add_non_persistent_groups( yazan_non_persistent_cache_groups() );
	}

	/*
	 * NOT declared global. `wp_cache_add_global_groups()` strips the blog prefix from a key, which is
	 * exactly the wrong thing for a group whose whole safety property is that its keys are
	 * per-tenant. The only Yazan value that is genuinely platform-wide — the host map — lives in an
	 * autoloaded option and is read before plugins load, so it never touches the object cache.
	 */
}

add_action( 'muplugins_loaded', 'yazan_register_cache_groups', 1 );

// Also run immediately: `muplugins_loaded` has already fired for anything loaded after this file,
// and a second call is idempotent.
yazan_register_cache_groups();
