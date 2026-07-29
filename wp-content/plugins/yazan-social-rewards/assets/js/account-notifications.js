/**
 * Yazan Rewards — My Account notifications panel (inbox + preferences).
 * Reuses the YazanRewards global (restUrl + wp_rest nonce) from account.js.
 */
( function () {
	'use strict';
	var cfg = window.YazanRewards || {};
	var mount = document.querySelector( '[data-yzrw-notifications]' );
	if ( ! cfg.restUrl || ! mount ) { return; }

	function api( path, method, body ) {
		return fetch( cfg.restUrl + path, {
			method: method || 'GET', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( r ) { return r.json().then( function ( j ) { if ( ! r.ok ) { throw new Error( ( j && j.message ) || 'Error' ); } return j; } ); } );
	}
	function el( t, a, c ) {
		var n = document.createElement( t ); a = a || {};
		Object.keys( a ).forEach( function ( k ) { if ( k === 'text' ) { n.textContent = a[ k ]; } else { n.setAttribute( k, a[ k ] ); } } );
		( c || [] ).forEach( function ( x ) { if ( x ) { n.appendChild( x ); } } );
		return n;
	}
	function parsePayload( raw ) { try { var p = JSON.parse( raw || '{}' ); return p && typeof p === 'object' ? p : {}; } catch ( e ) { return {}; } }
	function unread( row ) { return ! row.read_at || row.read_at.indexOf( '0000' ) === 0; }

	var inbox = null, prefs = null;

	function load() {
		mount.textContent = '';
		Promise.all( [ api( '/notifications' ), api( '/notifications/preferences' ) ] ).then( function ( res ) {
			inbox = res[ 0 ] || {}; prefs = ( res[ 1 ] && res[ 1 ].categories ) || []; render();
		} ).catch( function ( e ) { mount.appendChild( el( 'p', { class: 'yzrw-notif__err', text: e.message } ) ); } );
	}

	function render() {
		mount.innerHTML = '';
		mount.appendChild( inboxSection() );
		mount.appendChild( prefsSection() );
	}

	function inboxSection() {
		var items = ( inbox && inbox.items ) || [];
		var wrap = el( 'div', { class: 'yzrw-notif__inbox' } );
		var head = el( 'div', { class: 'yzrw-notif__head' } );
		head.appendChild( el( 'h4', { text: 'Inbox' + ( inbox.unread ? ' (' + inbox.unread + ' new)' : '' ) } ) );
		if ( items.length ) {
			var markAll = el( 'button', { type: 'button', class: 'yzrw-notif__link', text: 'Mark all read' } );
			markAll.addEventListener( 'click', function () { api( '/notifications/read', 'POST', {} ).then( load ); } );
			head.appendChild( markAll );
		}
		wrap.appendChild( head );

		if ( ! items.length ) {
			wrap.appendChild( el( 'p', { class: 'yzrw-notif__empty', text: 'No notifications yet.' } ) );
			return wrap;
		}
		var list = el( 'ul', { class: 'yzrw-notif__list' } );
		items.forEach( function ( row ) {
			var p = parsePayload( row.payload );
			var li = el( 'li', { class: 'yzrw-notif__item' + ( unread( row ) ? ' is-unread' : '' ) }, [
				el( 'span', { class: 'yzrw-notif__dot' } ),
				el( 'div', { class: 'yzrw-notif__body' }, [
					el( 'span', { class: 'yzrw-notif__subject', text: p.subject || row.template || 'Update' } ),
					el( 'span', { class: 'yzrw-notif__text', text: p.body || '' } ),
					el( 'span', { class: 'yzrw-notif__date', text: ( row.created_at || '' ).slice( 0, 16 ) } )
				] )
			] );
			if ( unread( row ) ) {
				li.addEventListener( 'click', function () {
					api( '/notifications/' + row.id + '/read', 'POST', {} ).then( function () { li.classList.remove( 'is-unread' ); } );
				} );
			}
			list.appendChild( li );
		} );
		wrap.appendChild( list );
		return wrap;
	}

	function prefsSection() {
		var wrap = el( 'div', { class: 'yzrw-notif__prefs' } );
		wrap.appendChild( el( 'h4', { text: 'Preferences' } ) );
		wrap.appendChild( el( 'p', { class: 'yzrw-notif__hint', text: 'Choose how each type of update reaches you.' } ) );

		var table = el( 'table', { class: 'yzrw-notif__pref-table' } );
		table.appendChild( el( 'thead', {}, [ el( 'tr', {}, [ el( 'th', { text: 'Update' } ), el( 'th', { text: 'Email' } ), el( 'th', { text: 'On-site' } ) ] ) ] ) );
		var tb = el( 'tbody' );
		var controls = {};
		prefs.forEach( function ( cat ) {
			var sel = el( 'select', {} );
			[ [ 'immediate', 'Immediately' ], [ 'digest', 'Daily digest' ], [ 'off', 'Off' ] ].forEach( function ( o ) {
				var opt = el( 'option', { value: o[0], text: o[1] } ); if ( cat.email === o[0] ) { opt.selected = true; } sel.appendChild( opt );
			} );
			var chk = el( 'input', { type: 'checkbox' } ); chk.checked = !! cat.onsite;
			if ( cat.required ) { sel.disabled = true; chk.disabled = true; }
			controls[ cat.key ] = { email: sel, onsite: chk };
			tb.appendChild( el( 'tr', {}, [
				el( 'td', { text: cat.label + ( cat.required ? ' *' : '' ) } ),
				el( 'td', {}, [ sel ] ),
				el( 'td', { class: 'yzrw-notif__center' }, [ chk ] )
			] ) );
		} );
		table.appendChild( tb );
		wrap.appendChild( table );
		wrap.appendChild( el( 'p', { class: 'yzrw-notif__note', text: '* Required transactional updates are always sent.' } ) );

		var msg = el( 'span', { class: 'yzrw-notif__msg' } );
		var save = el( 'button', { type: 'button', class: 'button', text: 'Save preferences' } );
		save.addEventListener( 'click', function () {
			var out = {};
			Object.keys( controls ).forEach( function ( k ) { out[ k ] = { email: controls[ k ].email.value, onsite: controls[ k ].onsite.checked }; } );
			save.disabled = true; msg.textContent = 'Saving…';
			api( '/notifications/preferences', 'POST', { prefs: out } ).then( function () { msg.textContent = 'Saved.'; save.disabled = false; } )
				.catch( function ( e ) { msg.textContent = e.message; save.disabled = false; } );
		} );
		wrap.appendChild( el( 'p', { class: 'yzrw-notif__save' }, [ save, msg ] ) );
		return wrap;
	}

	load();
} )();
