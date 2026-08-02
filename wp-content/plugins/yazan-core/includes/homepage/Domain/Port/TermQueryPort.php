<?php
/**
 * Taxonomy boundary — categories, collections, brands.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads terms for section rendering and for the editor's pickers.
 */
interface TermQueryPort {

	/**
	 * @param string $taxonomy Taxonomy.
	 * @param int[]  $ids      Term ids; empty = all.
	 * @param int    $limit    Max terms.
	 * @return array<int,array{id:int,name:string,url:string,image:int,count:int}>
	 */
	public function terms( $taxonomy, array $ids = array(), $limit = 20 );
}
