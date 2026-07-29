/**
 * Yazan Rewards — Fraud review admin (cases, states, approve/reject/clawback).
 * Vanilla JS against yazan-rewards/v1/admin/fraud.
 */
( function () {
	'use strict';
	var cfg = window.YazanFraudAdmin || {};
	var root = document.getElementById( 'yzrw-fraud-app' );
	if ( ! root || ! cfg.restUrl ) {
		return;
	}
	var state = { filter: '' };

	function api( path, method ) {
		return fetch( cfg.restUrl + ( path || '' ), {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce }
		} ).then( function ( r ) { return r.json().then( function ( j ) { return { ok: r.ok, body: j }; } ); } );
	}

	function el( tag, attrs, kids ) {
		var n = document.createElement( tag );
		attrs = attrs || {};
		Object.keys( attrs ).forEach( function ( k ) {
			if ( k === 'text' ) { n.textContent = attrs[ k ]; }
			else { n.setAttribute( k, attrs[ k ] ); }
		} );
		( kids || [] ).forEach( function ( c ) { if ( c ) { n.appendChild( c ); } } );
		return n;
	}

	function badge( status ) {
		return el( 'span', { 'class': 'yzrw-pill yzrw-pill--' + status, text: status.replace( '_', ' ' ) } );
	}

	function renderStats( stats, open ) {
		var wrap = el( 'div', { 'class': 'yzrw-card' } );
		wrap.appendChild( el( 'h2', { text: 'Overview (' + open + ' open)' } ) );
		var row = el( 'p' );
		[ [ 'suspicious', 'Suspicious' ], [ 'manual_review', 'Manual review' ], [ 'approved', 'Approved' ], [ 'rejected', 'Rejected' ] ].forEach( function ( s ) {
			row.appendChild( el( 'span', { style: 'margin-inline-end:1.5rem', text: s[ 1 ] + ': ' + ( stats[ s[ 0 ] ] || 0 ) } ) );
		} );
		wrap.appendChild( row );

		var filters = el( 'p' );
		[ [ '', 'All' ], [ 'manual_review', 'Needs review' ], [ 'suspicious', 'Suspicious' ], [ 'approved', 'Approved' ], [ 'rejected', 'Rejected' ] ].forEach( function ( f ) {
			var b = el( 'button', { 'class': 'button button-small' + ( state.filter === f[ 0 ] ? ' button-primary' : '' ), text: f[ 1 ], style: 'margin-inline-end:.3rem' } );
			b.addEventListener( 'click', function () { state.filter = f[ 0 ]; boot(); } );
			filters.appendChild( b );
		} );
		wrap.appendChild( filters );
		return wrap;
	}

	function renderCases( items ) {
		var wrap = el( 'div', { 'class': 'yzrw-card' } );
		wrap.appendChild( el( 'h2', { text: 'Cases (' + items.length + ')' } ) );
		if ( ! items.length ) {
			wrap.appendChild( el( 'p', { 'class': 'description', text: 'No cases match.' } ) );
			return wrap;
		}
		var table = el( 'table', { 'class': 'widefat striped' } );
		table.appendChild( el( 'thead', {}, [ el( 'tr', {}, [
			el( 'th', { text: 'Customer' } ), el( 'th', { text: 'Type' } ), el( 'th', { text: 'Severity' } ),
			el( 'th', { text: 'State' } ), el( 'th', { text: 'Score' } ), el( 'th', { text: 'Reward' } ), el( 'th', { text: 'Action' } )
		] ) ] ) );
		var tb = el( 'tbody' );
		items.forEach( function ( it ) {
			var reward = it.reward && it.reward.kind !== 'none'
				? ( it.reward.kind === 'points' ? ( parseInt( it.reward.amount, 10 ) + ' pts' ) : ( parseFloat( it.reward.amount ).toFixed( 2 ) + ' credit' ) )
				: '—';
			var actions = el( 'td' );
			if ( it.status === 'suspicious' || it.status === 'manual_review' ) {
				var approve = el( 'button', { 'class': 'button button-small button-primary', text: 'Approve' } );
				var reject = el( 'button', { 'class': 'button button-small', text: 'Reject', style: 'margin-inline-start:.3rem' } );
				var tr;
				function act( verb, confirmMsg ) {
					if ( confirmMsg && ! window.confirm( confirmMsg ) ) { return; }
					approve.disabled = reject.disabled = true;
					api( '/' + it.id + '/' + verb, 'POST' ).then( function ( out ) {
						if ( out.ok ) { boot(); } else { approve.disabled = reject.disabled = false; }
					} );
				}
				approve.addEventListener( 'click', function () { act( 'approve' ); } );
				reject.addEventListener( 'click', function () { act( 'reject', 'Reject this case? The reward will be reversed and the account flagged.' ); } );
				actions.appendChild( approve );
				actions.appendChild( reject );
			} else {
				actions.appendChild( el( 'span', { 'class': 'description', text: it.note || '—' } ) );
			}
			tb.appendChild( el( 'tr', {}, [
				el( 'td', {}, [ el( 'a', { href: cfg.restUrl + '/user/' + it.user_id, onclick: 'return false', text: it.user } ) ] ),
				el( 'td', { text: it.type + ( it.source ? ( ' · ' + it.source + '#' + it.source_id ) : '' ) } ),
				el( 'td', { text: it.severity } ),
				el( 'td', {}, [ badge( it.status ) ] ),
				el( 'td', { text: String( it.score ) } ),
				el( 'td', { text: reward } ),
				actions
			] ) );
		} );
		table.appendChild( tb );
		wrap.appendChild( table );
		return wrap;
	}

	function boot() {
		root.textContent = 'Loading…';
		api( state.filter ? ( '?status=' + encodeURIComponent( state.filter ) ) : '' ).then( function ( out ) {
			root.textContent = '';
			var b = out.body || {};
			root.appendChild( renderStats( b.stats || {}, b.open_count || 0 ) );
			root.appendChild( renderCases( b.items || [] ) );
		} ).catch( function () { root.textContent = 'Failed to load.'; } );
	}

	boot();
} )();
