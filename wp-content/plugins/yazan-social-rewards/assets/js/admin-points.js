/**
 * Yazan Rewards — Points ledger admin (pending queue + manual adjust).
 */
( function () {
	'use strict';
	var cfg = window.YazanPointsAdmin || {};
	var root = document.getElementById( 'yzrw-points-app' );
	if ( ! cfg.restUrl || ! root ) { return; }

	function api( path, method, body ) {
		return fetch( cfg.restUrl + path, {
			method: method || 'GET', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( r ) { return r.json().then( function ( j ) { if ( ! r.ok ) { throw new Error( ( j && j.message ) || 'Error' ); } return j; } ); } );
	}
	function el( t, a, c ) { var n = document.createElement( t ); a = a || {}; Object.keys( a ).forEach( function ( k ) { if ( k === 'text' ) { n.textContent = a[ k ]; } else if ( k === 'html' ) { n.innerHTML = a[ k ]; } else { n.setAttribute( k, a[ k ] ); } } ); ( c || [] ).forEach( function ( x ) { if ( x ) { n.appendChild( x ); } } ); return n; }

	/**
	 * A user typeahead: search WordPress users by username / display name / email
	 * and resolve the picked one to a numeric ID. A pure integer is accepted as a
	 * literal ID too, so power users can still paste one. Returns { wrap, getId }.
	 */
	function userSearch() {
		var selectedId = 0, results = [], active = -1, timer = null;
		var input = el( 'input', { type: 'search', class: 'regular-text', placeholder: 'Search by name, username or email…', autocomplete: 'off', role: 'combobox', 'aria-expanded': 'false', 'aria-autocomplete': 'list' } );
		var list = el( 'ul', { class: 'yzrw-user-results', role: 'listbox' } );
		list.style.display = 'none';
		var wrap = el( 'span', { class: 'yzrw-user-search' }, [ input, list ] );

		function close() { list.style.display = 'none'; list.innerHTML = ''; results = []; active = -1; input.setAttribute( 'aria-expanded', 'false' ); }

		function choose( u ) { selectedId = u.id; input.value = u.name + ' (#' + u.id + ')'; close(); }

		function draw() {
			list.innerHTML = '';
			if ( ! results.length ) { close(); return; }
			results.forEach( function ( u, i ) {
				var li = el( 'li', { role: 'option', class: i === active ? 'is-active' : '' }, [
					el( 'span', { class: 'yzrw-user-name', text: u.name || u.username } ),
					el( 'span', { class: 'yzrw-user-meta', text: '@' + u.username + ( u.email ? ' · ' + u.email : '' ) } )
				] );
				li.addEventListener( 'mousedown', function ( ev ) { ev.preventDefault(); choose( u ); } );
				list.appendChild( li );
			} );
			list.style.display = '';
			input.setAttribute( 'aria-expanded', 'true' );
		}

		function search( v ) {
			api( '/users?q=' + encodeURIComponent( v ), 'GET' )
				.then( function ( d ) { results = ( d && d.items ) || []; active = -1; draw(); } )
				.catch( function () { close(); } );
		}

		input.addEventListener( 'input', function () {
			selectedId = 0; // typing invalidates any prior pick
			var v = input.value.trim();
			window.clearTimeout( timer );
			if ( /^\d+$/.test( v ) ) { selectedId = parseInt( v, 10 ); close(); return; } // raw ID accepted
			if ( v.length < 2 ) { close(); return; }
			timer = window.setTimeout( function () { search( v ); }, 250 );
		} );

		input.addEventListener( 'keydown', function ( ev ) {
			if ( 'none' === list.style.display ) { return; }
			if ( 'ArrowDown' === ev.key ) { ev.preventDefault(); active = Math.min( active + 1, results.length - 1 ); draw(); }
			else if ( 'ArrowUp' === ev.key ) { ev.preventDefault(); active = Math.max( active - 1, 0 ); draw(); }
			else if ( 'Enter' === ev.key ) { if ( active >= 0 && results[ active ] ) { ev.preventDefault(); choose( results[ active ] ); } }
			else if ( 'Escape' === ev.key ) { close(); }
		} );

		// Close after a click elsewhere (delayed so an option's mousedown wins).
		input.addEventListener( 'blur', function () { window.setTimeout( close, 150 ); } );

		return { wrap: wrap, getId: function () { return selectedId; } };
	}

	function load() {
		root.textContent = 'Loading…';
		api( '/pending' ).then( function ( d ) { render( ( d && d.items ) || [] ); } ).catch( function ( e ) { root.textContent = e.message; } );
	}

	function render( items ) {
		root.innerHTML = '';
		// Adjust form.
		var card = el( 'div', { class: 'yzrw-card' } );
		card.appendChild( el( 'h2', { text: 'Manual adjustment' } ) );
		var user = userSearch();
		var pts = el( 'input', { type: 'number', class: 'small-text', placeholder: '± points' } );
		var reason = el( 'input', { type: 'text', class: 'regular-text', placeholder: 'Reason' } );
		var pending = el( 'input', { type: 'checkbox' } );
		var msg = el( 'span', { class: 'yzrw-msg' } );
		var save = el( 'button', { class: 'button button-primary', text: 'Apply' } );
		save.addEventListener( 'click', function () {
			var uid = user.getId();
			if ( ! uid ) { msg.textContent = 'Select a user.'; return; }
			msg.textContent = '';
			save.disabled = true;
			api( '/adjust', 'POST', { user_id: uid, points: parseInt( pts.value, 10 ), reason: reason.value, pending: pending.checked } )
				.then( function () { load(); } ).catch( function ( e ) { msg.textContent = e.message; save.disabled = false; } );
		} );
		card.appendChild( el( 'p', { class: 'yzrw-field' }, [ el( 'label', { text: 'User' } ), user.wrap ] ) );
		card.appendChild( el( 'p', { class: 'yzrw-field' }, [ el( 'label', { text: 'Points (+ credit / − debit)' } ), pts ] ) );
		card.appendChild( el( 'p', { class: 'yzrw-field' }, [ el( 'label', { text: 'Reason' } ), reason ] ) );
		card.appendChild( el( 'p', { class: 'yzrw-field' }, [ el( 'label', { text: 'Create as Pending' } ), pending ] ) );
		card.appendChild( el( 'p', { class: 'yzrw-save' }, [ save, msg ] ) );
		root.appendChild( card );

		// Pending queue.
		var q = el( 'div', { class: 'yzrw-card' } );
		q.appendChild( el( 'h2', { text: 'Pending points (' + items.length + ')' } ) );
		if ( ! items.length ) { q.appendChild( el( 'p', { class: 'description', text: 'No pending entries.' } ) ); root.appendChild( q ); return; }
		var table = el( 'table', { class: 'widefat striped yzrw-table' } );
		table.appendChild( el( 'thead', {}, [ el( 'tr', {}, [ el( 'th', { text: 'User' } ), el( 'th', { text: 'Points' } ), el( 'th', { text: 'Reason' } ), el( 'th', { text: 'Date' } ), el( 'th', { text: '' } ) ] ) ] ) );
		var tb = el( 'tbody' );
		items.forEach( function ( r ) {
			var ap = el( 'button', { class: 'button button-small button-primary', text: 'Approve' } );
			ap.addEventListener( 'click', function () { api( '/' + r.id + '/approve', 'POST' ).then( load ); } );
			var rj = el( 'button', { class: 'button button-small', text: 'Reject' } );
			rj.addEventListener( 'click', function () { api( '/' + r.id + '/reject', 'POST' ).then( load ); } );
			tb.appendChild( el( 'tr', {}, [
				el( 'td', { text: r.user + ' (#' + r.user_id + ')' } ),
				el( 'td', { text: String( r.points ) } ),
				el( 'td', { text: r.reason || r.source } ),
				el( 'td', { text: r.created_at } ),
				el( 'td', { class: 'yzrw-actions-cell' }, [ ap, rj ] )
			] ) );
		} );
		table.appendChild( tb );
		q.appendChild( table );
		root.appendChild( q );
	}

	load();
} )();
