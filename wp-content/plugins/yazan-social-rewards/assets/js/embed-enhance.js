/**
 * Yazan Rewards — dashboard-embed enhancer (shared across every embedded screen).
 *
 * On a phone the rewards admin tables are re-flowed by CSS into a stack of cards
 * (one per record, with its action buttons underneath). Two things need JS:
 *
 *   1. data-label — each `<td>` is given its column header text so the card can
 *      show "Label: value" rows (surfaced via `td::before` in embed-skin.css).
 *   2. pagination — on mobile, long lists are paged at PAGE_SIZE records with a
 *      compact ‹ prev / next › control, so the embed stays short instead of
 *      producing an endless scroll. Desktop is untouched (full table, no pager).
 *
 * Re-applied after a widget re-renders (they replace innerHTML on every action) via
 * a MutationObserver, and re-evaluated when crossing the mobile breakpoint. Paging
 * clicks only toggle row visibility + button state (no DOM structure change), so they
 * never feed back into the observer.
 */
( function () {
	'use strict';

	var SELECTOR  = 'table.yzrw-table, table.widefat, table.wp-list-table';
	var PAGE_SIZE = 5;
	var mq = window.matchMedia( '(max-width: 640px)' );

	function labelCells( table ) {
		var heads = table.querySelectorAll( 'thead th' );
		if ( ! heads.length ) { return; }
		var labels = [];
		for ( var h = 0; h < heads.length; h++ ) {
			labels.push( ( heads[ h ].textContent || '' ).trim() );
		}
		var rows = table.querySelectorAll( 'tbody tr' );
		for ( var r = 0; r < rows.length; r++ ) {
			var cells = rows[ r ].children;
			for ( var c = 0; c < cells.length; c++ ) {
				if ( 'TD' === cells[ c ].tagName && ! cells[ c ].hasAttribute( 'data-label' ) ) {
					cells[ c ].setAttribute( 'data-label', labels[ c ] || '' );
				}
			}
		}
	}

	function pagerButton( label, aria ) {
		var b = document.createElement( 'button' );
		b.type = 'button';
		b.className = 'button button-small yzrw-pager-btn';
		b.textContent = label;
		b.setAttribute( 'aria-label', aria );
		return b;
	}

	// The pager lives as the table's next sibling; build it once per table.
	function ensurePager( table ) {
		var sib = table.nextElementSibling;
		if ( sib && sib.classList && sib.classList.contains( 'yzrw-pager' ) ) {
			return sib.__yzrw;
		}
		var wrap = document.createElement( 'div' );
		wrap.className = 'yzrw-pager';
		var prev = pagerButton( '‹', 'Previous page' );
		var info = document.createElement( 'span' );
		info.className = 'yzrw-pager-info';
		var next = pagerButton( '›', 'Next page' );
		wrap.appendChild( prev );
		wrap.appendChild( info );
		wrap.appendChild( next );
		wrap.__yzrw = { wrap: wrap, prev: prev, info: info, next: next };
		table.parentNode.insertBefore( wrap, table.nextSibling );
		return wrap.__yzrw;
	}

	function enhance( table ) {
		labelCells( table );
		var body = table.tBodies[ 0 ];
		var rows = body ? [].slice.call( body.rows ) : [];
		var pager = ensurePager( table );

		// Desktop, or few enough rows: show everything, hide the pager.
		if ( ! mq.matches || rows.length <= PAGE_SIZE ) {
			for ( var i = 0; i < rows.length; i++ ) { rows[ i ].style.display = ''; }
			pager.wrap.style.display = 'none';
			return;
		}

		var pages = Math.ceil( rows.length / PAGE_SIZE );
		if ( null == table.__page || table.__page >= pages ) { table.__page = 0; }

		// Only toggles display / text / disabled — no childList change, so the
		// MutationObserver is never triggered by a page click.
		function show() {
			var cur = table.__page;
			for ( var j = 0; j < rows.length; j++ ) {
				rows[ j ].style.display = ( j >= cur * PAGE_SIZE && j < ( cur + 1 ) * PAGE_SIZE ) ? '' : 'none';
			}
			pager.info.textContent = ( cur + 1 ) + ' / ' + pages;
			pager.prev.disabled = 0 === cur;
			pager.next.disabled = cur === pages - 1;
		}

		pager.prev.onclick = function () { if ( table.__page > 0 ) { table.__page--; show(); } };
		pager.next.onclick = function () { if ( table.__page < pages - 1 ) { table.__page++; show(); } };
		pager.wrap.style.display = 'flex';
		show();
	}

	function run() {
		var tables = document.querySelectorAll( SELECTOR );
		for ( var i = 0; i < tables.length; i++ ) { enhance( tables[ i ] ); }
	}

	var observer = null;
	var scheduled = false;

	function schedule() {
		if ( scheduled ) { return; }
		scheduled = true;
		window.requestAnimationFrame( function () {
			scheduled = false;
			if ( observer ) { observer.disconnect(); }
			run();
			if ( observer ) { observer.observe( document.body, { childList: true, subtree: true } ); }
		} );
	}

	function start() {
		run();
		if ( 'MutationObserver' in window ) {
			observer = new MutationObserver( schedule );
			observer.observe( document.body, { childList: true, subtree: true } );
		}
		if ( mq.addEventListener ) { mq.addEventListener( 'change', run ); }
		else if ( mq.addListener ) { mq.addListener( run ); }
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
