/**
 * Yazan Rewards — Social connectors admin (keys, verification, review queue).
 * Vanilla JS against yazan-rewards/v1/admin/social.
 */
( function () {
	'use strict';
	var cfg = window.YazanSocialAdmin || {};
	var root = document.getElementById( 'yzrw-social-app' );
	if ( ! root || ! cfg.restUrl ) {
		return;
	}

	function api( path, method, body ) {
		return fetch( cfg.restUrl + ( path || '' ), {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: body ? JSON.stringify( body ) : undefined
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

	function checkbox( checked ) {
		var i = el( 'input', { type: 'checkbox' } );
		i.checked = !! checked;
		return i;
	}

	/* ---- Connectors ---- */
	function renderConnectors( items ) {
		var card = el( 'div', { 'class': 'yzrw-card' } );
		card.appendChild( el( 'h2', { text: 'Networks' } ) );
		card.appendChild( el( 'p', { 'class': 'description', text: 'Enter each network’s OAuth app Client ID + Secret to enable API verification. Leave the secret blank to keep the stored one. Networks without keys still accept manual submissions.' } ) );

		var table = el( 'table', { 'class': 'widefat striped' } );
		table.appendChild( el( 'thead', {}, [ el( 'tr', {}, [
			el( 'th', { text: 'Network' } ), el( 'th', { text: 'Client ID' } ), el( 'th', { text: 'Client Secret' } ),
			el( 'th', { text: 'Enabled' } ), el( 'th', { text: 'Points' } ), el( 'th', { text: '' } )
		] ) ] ) );
		var tb = el( 'tbody' );

		items.forEach( function ( it ) {
			var idInput = el( 'input', { type: 'text', value: it.id_last4 ? ( '••••' + it.id_last4 ) : '', placeholder: 'Client ID', style: 'width:11rem' } );
			var secretInput = el( 'input', { type: 'password', value: '', placeholder: it.secret_last4 ? ( 'set · ••••' + it.secret_last4 ) : 'Client secret', style: 'width:11rem' } );
			var enabled = checkbox( it.enabled );
			var points = el( 'input', { type: 'number', min: '0', value: it.points != null ? String( it.points ) : '', placeholder: 'default', style: 'width:5rem' } );
			var save = el( 'button', { 'class': 'button button-small', text: 'Save' } );
			var status = el( 'span', { 'class': 'yzrw-msg', style: 'margin-inline-start:.5rem' } );

			save.addEventListener( 'click', function () {
				save.disabled = true;
				var body = { enabled: enabled.checked };
				if ( points.value !== '' ) { body.points = parseInt( points.value, 10 ) || 0; }
				// Only send client_id if the admin typed a fresh (non-masked) value.
				if ( idInput.value && idInput.value.indexOf( '•' ) === -1 ) { body.client_id = idInput.value; }
				if ( secretInput.value ) { body.client_secret = secretInput.value; }
				api( '/connectors/' + it.network, 'POST', body ).then( function ( out ) {
					save.disabled = false;
					status.textContent = out.ok ? 'Saved' : ( ( out.body && out.body.message ) || 'Error' );
					if ( out.ok && out.body.status ) {
						it.configured = out.body.status.configured;
					}
				} );
			} );

			tb.appendChild( el( 'tr', {}, [
				el( 'td', {}, [ el( 'strong', { text: it.label } ), el( 'div', { 'class': 'description', text: it.configured ? ( 'configured · ' + it.source ) : 'manual only' } ) ] ),
				el( 'td', {}, [ idInput ] ),
				el( 'td', {}, [ secretInput ] ),
				el( 'td', {}, [ enabled ] ),
				el( 'td', {}, [ points ] ),
				el( 'td', {}, [ save, status ] )
			] ) );
		} );
		table.appendChild( tb );
		card.appendChild( table );
		return card;
	}

	/* ---- Verification settings ---- */
	function renderSettings( v ) {
		v = v || {};
		var card = el( 'div', { 'class': 'yzrw-card' } );
		card.appendChild( el( 'h2', { text: 'Verification' } ) );

		var auto = checkbox( v.auto_approve !== false );
		var fetch = checkbox( v.manual_fetch !== false );
		var hashtag = el( 'input', { type: 'text', value: v.required_hashtag || '', placeholder: '#YazanJewelry', style: 'width:12rem' } );
		var mention = el( 'input', { type: 'text', value: v.required_mention || '', placeholder: '@yazan or a link', style: 'width:12rem' } );
		var windowDays = el( 'input', { type: 'number', min: '0', value: v.window_days != null ? String( v.window_days ) : '30', style: 'width:5rem' } );

		function row( label, node ) { return el( 'p', {}, [ el( 'label', {}, [ document.createTextNode( label + ' ' ), node ] ) ] ); }
		card.appendChild( row( 'Auto-approve when all required checks pass', auto ) );
		card.appendChild( row( 'Fetch the post URL to run checks', fetch ) );
		card.appendChild( row( 'Required hashtag', hashtag ) );
		card.appendChild( row( 'Required mention / link', mention ) );
		card.appendChild( row( 'Post must be within (days)', windowDays ) );

		var save = el( 'button', { 'class': 'button button-primary', text: 'Save verification' } );
		var msg = el( 'span', { 'class': 'yzrw-msg', style: 'margin-inline-start:.6rem' } );
		save.addEventListener( 'click', function () {
			save.disabled = true;
			api( '/settings', 'POST', { verify: {
				auto_approve: auto.checked, manual_fetch: fetch.checked,
				required_hashtag: hashtag.value, required_mention: mention.value,
				window_days: parseInt( windowDays.value, 10 ) || 0
			} } ).then( function ( out ) { save.disabled = false; msg.textContent = out.ok ? 'Saved.' : 'Error'; } );
		} );
		card.appendChild( el( 'p', {}, [ save, msg ] ) );
		return card;
	}

	/* ---- Review queue ---- */
	function fmtChecks( checks ) {
		return Object.keys( checks || {} ).map( function ( k ) {
			var v = checks[ k ];
			var mark = v === true ? '✓' : ( v === false ? '✗' : '–' );
			return k + ':' + mark;
		} ).join( '  ' );
	}
	function renderQueue( items ) {
		var card = el( 'div', { 'class': 'yzrw-card' } );
		card.appendChild( el( 'h2', { text: 'Submissions to review (' + items.length + ')' } ) );
		if ( ! items.length ) {
			card.appendChild( el( 'p', { 'class': 'description', text: 'Nothing awaiting review.' } ) );
			return card;
		}
		var table = el( 'table', { 'class': 'widefat striped' } );
		table.appendChild( el( 'thead', {}, [ el( 'tr', {}, [
			el( 'th', { text: 'Customer' } ), el( 'th', { text: 'Network' } ), el( 'th', { text: 'Post' } ),
			el( 'th', { text: 'Checks' } ), el( 'th', { text: 'Action' } )
		] ) ] ) );
		var tb = el( 'tbody' );
		items.forEach( function ( it ) {
			var link = el( 'a', { href: it.url, target: '_blank', rel: 'noopener', text: 'view' } );
			var approve = el( 'button', { 'class': 'button button-small button-primary', text: 'Approve' } );
			var reject = el( 'button', { 'class': 'button button-small', text: 'Reject', style: 'margin-inline-start:.4rem' } );
			var tr = el( 'tr', {}, [
				el( 'td', { text: it.user } ),
				el( 'td', { text: it.network + ' · ' + ( it.media_type || 'photo' ) } ),
				el( 'td', {}, [ link ] ),
				el( 'td', { text: fmtChecks( it.checks ) + ( it.method ? ( '  [' + it.method + ']' ) : '' ) } ),
				el( 'td', {}, [ approve, reject ] )
			] );
			function act( verb ) {
				approve.disabled = reject.disabled = true;
				api( '/submissions/' + it.id + '/' + verb, 'POST' ).then( function ( out ) {
					if ( out.ok ) { tr.parentNode.removeChild( tr ); }
					else { approve.disabled = reject.disabled = false; }
				} );
			}
			approve.addEventListener( 'click', function () { act( 'approve' ); } );
			reject.addEventListener( 'click', function () { if ( window.confirm( 'Reject this submission?' ) ) { act( 'reject' ); } } );
			tb.appendChild( tr );
		} );
		table.appendChild( tb );
		card.appendChild( table );
		return card;
	}

	function boot() {
		root.textContent = 'Loading…';
		Promise.all( [ api( '/connectors' ), api( '/settings' ), api( '/pending' ) ] ).then( function ( res ) {
			root.textContent = '';
			root.appendChild( renderConnectors( ( res[ 0 ].body && res[ 0 ].body.items ) || [] ) );
			root.appendChild( renderSettings( ( res[ 1 ].body && res[ 1 ].body.verify ) || {} ) );
			root.appendChild( renderQueue( ( res[ 2 ].body && res[ 2 ].body.items ) || [] ) );
		} ).catch( function () { root.textContent = 'Failed to load.'; } );
	}

	boot();
} )();
