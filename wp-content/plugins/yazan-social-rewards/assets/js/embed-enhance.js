/**
 * Yazan Rewards — dashboard-embed enhancer (shared across every embedded screen).
 *
 * The rewards admin widgets render plain wp-admin tables. Inside the narrow
 * `/dashboard` iframe (which is `scrolling="no"`) a table with many columns would
 * overflow the card and get clipped. Rather than edit every `admin-*.js`, this shim
 * wraps each table in a `.yzrw-tablewrap` (overflow-x:auto) so ONLY the table scrolls
 * horizontally — the card, the page, and any absolutely-positioned menus (e.g. the
 * Points user typeahead) are untouched (wrapping the card instead would clip them).
 *
 * A MutationObserver re-wraps after a widget re-renders (they replace innerHTML on
 * every action). Wrapping is idempotent — an already-wrapped table is skipped — and
 * the observer is disconnected while wrapping so the insert can't feed back into it.
 */
( function () {
	'use strict';

	var SELECTOR = 'table.yzrw-table, table.widefat, table.wp-list-table';

	function wrapTables() {
		var tables = document.querySelectorAll( SELECTOR );
		for ( var i = 0; i < tables.length; i++ ) {
			var table = tables[ i ];
			var parent = table.parentNode;
			if ( parent && parent.classList && parent.classList.contains( 'yzrw-tablewrap' ) ) {
				continue; // already wrapped
			}
			var wrap = document.createElement( 'div' );
			wrap.className = 'yzrw-tablewrap';
			parent.insertBefore( wrap, table );
			wrap.appendChild( table );
		}
	}

	var observer = null;
	var scheduled = false;

	function schedule() {
		if ( scheduled ) { return; }
		scheduled = true;
		window.requestAnimationFrame( function () {
			scheduled = false;
			if ( observer ) { observer.disconnect(); }
			wrapTables();
			if ( observer ) { observer.observe( document.body, { childList: true, subtree: true } ); }
		} );
	}

	function start() {
		wrapTables();
		if ( 'MutationObserver' in window ) {
			observer = new MutationObserver( schedule );
			observer.observe( document.body, { childList: true, subtree: true } );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
