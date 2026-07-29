/**
 * Yazan Rewards — service fulfillment queue admin.
 */
( function () {
	'use strict';
	var cfg = window.YazanServiceAdmin || {};
	var root = document.getElementById( 'yzrw-service-app' );
	if ( ! cfg.restUrl || ! root ) { return; }

	function api( path, method, body ) {
		return fetch( cfg.restUrl + path, {
			method: method || 'GET', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( r ) { return r.json().then( function ( j ) { if ( ! r.ok ) { throw new Error( ( j && j.message ) || 'Error' ); } return j; } ); } );
	}
	function el( t, a, c ) { var n = document.createElement( t ); a = a || {}; Object.keys( a ).forEach( function ( k ) { if ( k === 'text' ) { n.textContent = a[ k ]; } else if ( k === 'html' ) { n.innerHTML = a[ k ]; } else { n.setAttribute( k, a[ k ] ); } } ); ( c || [] ).forEach( function ( x ) { if ( x ) { n.appendChild( x ); } } ); return n; }

	function load() {
		root.textContent = 'Loading…';
		api( '' ).then( function ( d ) { render( ( d && d.items ) || [] ); } ).catch( function ( e ) { root.textContent = e.message; } );
	}

	function statusBtn( id, status, label, primary ) {
		var b = el( 'button', { class: 'button button-small' + ( primary ? ' button-primary' : '' ), text: label } );
		b.addEventListener( 'click', function () { api( '/' + id + '/status', 'POST', { status: status } ).then( load ); } );
		return b;
	}

	function render( items ) {
		root.innerHTML = '';
		var card = el( 'div', { class: 'yzrw-card' } );
		card.appendChild( el( 'h2', { text: 'Open service vouchers (' + items.length + ')' } ) );
		if ( ! items.length ) { card.appendChild( el( 'p', { class: 'description', text: 'Nothing to fulfill right now.' } ) ); root.appendChild( card ); return; }
		var table = el( 'table', { class: 'widefat striped yzrw-table' } );
		table.appendChild( el( 'thead', {}, [ el( 'tr', {}, [ el( 'th', { text: 'Customer' } ), el( 'th', { text: 'Service' } ), el( 'th', { text: 'Voucher' } ), el( 'th', { text: 'Status' } ), el( 'th', { text: 'Requested' } ), el( 'th', { text: '' } ) ] ) ] ) );
		var tb = el( 'tbody' );
		items.forEach( function ( v ) {
			var actions = [];
			if ( v.status === 'requested' ) { actions.push( statusBtn( v.id, 'scheduled', 'Schedule' ) ); }
			actions.push( statusBtn( v.id, 'fulfilled', 'Fulfilled', true ) );
			actions.push( statusBtn( v.id, 'cancelled', 'Cancel' ) );
			tb.appendChild( el( 'tr', {}, [
				el( 'td', { text: v.user + ' (#' + v.user_id + ')' } ),
				el( 'td', { text: ( v.type || '' ).replace( /_/g, ' ' ) } ),
				el( 'td', {}, [ el( 'code', { text: v.code } ) ] ),
				el( 'td', {}, [ el( 'span', { class: 'yzrw-pill ' + v.status, text: v.status } ) ] ),
				el( 'td', { text: v.created_at } ),
				el( 'td', { class: 'yzrw-actions-cell' }, actions )
			] ) );
		} );
		table.appendChild( tb );
		card.appendChild( table );
		root.appendChild( card );
	}

	load();
} )();
