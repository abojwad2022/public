<?php
/**
 * Modules this plugin loads, in no particular order — the registry sorts by dependency.
 *
 * An entry is just a class-string; all metadata lives on the class.
 *
 * @package Yazan\Stores
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	\Yazan\Stores\Modules\Store\StoreModule::class,
);
