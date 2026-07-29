/**
 * Yazan Rewards — Referrals admin (reward rules + payout review + revenue).
 * Vanilla JS against yazan-rewards/v1/admin/referrals.
 */
( function () {
	'use strict';
	var cfg = window.YazanReferralAdmin || {};
	var root = document.getElementById( 'yzrw-referrals-app' );
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

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		attrs = attrs || {};
		Object.keys( attrs ).forEach( function ( k ) {
			if ( k === 'text' ) { node.textContent = attrs[ k ]; }
			else if ( k === 'html' ) { node.innerHTML = attrs[ k ]; }
			else { node.setAttribute( k, attrs[ k ] ); }
		} );
		( children || [] ).forEach( function ( c ) { if ( c ) { node.appendChild( c ); } } );
		return node;
	}

	function money( v ) { return ( parseFloat( v ) || 0 ).toFixed( 2 ); }

	function specRow( label, key, spec ) {
		spec = spec || { kind: 'points', mode: 'fixed', value: 0 };
		var kind = el( 'select', { 'data-spec': key, 'data-field': 'kind' } );
		[ [ 'points', 'Points' ], [ 'credit', 'Store credit' ] ].forEach( function ( o ) {
			var opt = el( 'option', { value: o[ 0 ], text: o[ 1 ] } );
			if ( spec.kind === o[ 0 ] ) { opt.selected = true; }
			kind.appendChild( opt );
		} );
		var mode = el( 'select', { 'data-spec': key, 'data-field': 'mode' } );
		[ [ 'fixed', 'Fixed' ], [ 'percent', '% of order' ] ].forEach( function ( o ) {
			var opt = el( 'option', { value: o[ 0 ], text: o[ 1 ] } );
			if ( spec.mode === o[ 0 ] ) { opt.selected = true; }
			mode.appendChild( opt );
		} );
		var value = el( 'input', { type: 'number', step: '0.01', min: '0', value: String( spec.value != null ? spec.value : 0 ), 'data-spec': key, 'data-field': 'value' } );
		return el( 'tr', {}, [
			el( 'th', { text: label } ),
			el( 'td', {}, [ kind ] ),
			el( 'td', {}, [ mode ] ),
			el( 'td', {}, [ value ] )
		] );
	}

	function collectSpec( key ) {
		var out = {};
		[ 'kind', 'mode', 'value' ].forEach( function ( f ) {
			var node = root.querySelector( '[data-spec="' + key + '"][data-field="' + f + '"]' );
			if ( node ) { out[ f ] = f === 'value' ? parseFloat( node.value ) || 0 : node.value; }
		} );
		return out;
	}

	function renderSettings( s ) {
		s = s || {};
		var wrap = el( 'div', { 'class': 'yzrw-card' } );
		wrap.appendChild( el( 'h2', { text: 'Reward rules' } ) );

		var grid = el( 'p' );
		function numField( label, key, val, extra ) {
			var input = el( 'input', Object.assign( { type: 'number', min: '0', value: String( val != null ? val : 0 ), 'data-setting': key, style: 'width:6rem' }, extra || {} ) );
			var lab = el( 'label', { style: 'margin-inline-end:1.5rem' }, [ document.createTextNode( label + ' ' ), input ] );
			grid.appendChild( lab );
		}
		numField( 'Reward levels (1–2)', 'max_levels', s.max_levels, { max: '2' } );
		numField( 'Min order total', 'min_order_total', s.min_order_total, { step: '0.01' } );
		numField( 'Cookie days', 'cookie_days', s.cookie_days );

		var abuse = el( 'select', { 'data-setting': 'abuse_action' } );
		[ [ 'hold', 'Hold for review' ], [ 'block', 'Block' ], [ 'flag', 'Flag only (still pay)' ] ].forEach( function ( o ) {
			var opt = el( 'option', { value: o[ 0 ], text: o[ 1 ] } );
			if ( s.abuse_action === o[ 0 ] ) { opt.selected = true; }
			abuse.appendChild( opt );
		} );
		grid.appendChild( el( 'label', { style: 'margin-inline-end:1.5rem' }, [ document.createTextNode( 'On suspicious referral ' ), abuse ] ) );
		wrap.appendChild( grid );

		var table = el( 'table', { 'class': 'widefat striped', style: 'max-width:640px' } );
		table.appendChild( el( 'thead', {}, [ el( 'tr', {}, [
			el( 'th', { text: 'Reward' } ), el( 'th', { text: 'Kind' } ), el( 'th', { text: 'Mode' } ), el( 'th', { text: 'Value' } )
		] ) ] ) );
		var tb = el( 'tbody', {}, [
			specRow( 'New customer', 'referred', s.referred ),
			specRow( 'Direct referrer (L1)', 'referrer', s.referrer ),
			specRow( 'Grand-referrer (L2)', 'level2', s.level2 )
		] );
		table.appendChild( tb );
		wrap.appendChild( table );

		var save = el( 'button', { 'class': 'button button-primary', text: 'Save rules', style: 'margin-top:1rem' } );
		var msg = el( 'span', { 'class': 'yzrw-msg', style: 'margin-inline-start:1rem' } );
		save.addEventListener( 'click', function () {
			var payload = { referral: {
				max_levels: parseInt( root.querySelector( '[data-setting="max_levels"]' ).value, 10 ) || 1,
				min_order_total: parseFloat( root.querySelector( '[data-setting="min_order_total"]' ).value ) || 0,
				cookie_days: parseInt( root.querySelector( '[data-setting="cookie_days"]' ).value, 10 ) || 30,
				abuse_action: root.querySelector( '[data-setting="abuse_action"]' ).value,
				referred: collectSpec( 'referred' ),
				referrer: collectSpec( 'referrer' ),
				level2: collectSpec( 'level2' )
			} };
			save.disabled = true;
			api( '/settings', 'POST', payload ).then( function ( out ) {
				save.disabled = false;
				msg.textContent = out.ok ? 'Saved.' : ( ( out.body && out.body.message ) || 'Error' );
			} );
		} );
		wrap.appendChild( el( 'p', {}, [ save, msg ] ) );
		return wrap;
	}

	function renderPending( items ) {
		var wrap = el( 'div', { 'class': 'yzrw-card' } );
		wrap.appendChild( el( 'h2', { text: 'Rewards held for review (' + items.length + ')' } ) );
		if ( ! items.length ) {
			wrap.appendChild( el( 'p', { 'class': 'description', text: 'No rewards are awaiting review.' } ) );
			return wrap;
		}
		var table = el( 'table', { 'class': 'widefat striped' } );
		table.appendChild( el( 'thead', {}, [ el( 'tr', {}, [
			el( 'th', { text: 'Beneficiary' } ), el( 'th', { text: 'From order' } ), el( 'th', { text: 'Level' } ),
			el( 'th', { text: 'Reward' } ), el( 'th', { text: 'Action' } )
		] ) ] ) );
		var tb = el( 'tbody' );
		items.forEach( function ( it ) {
			var reward = it.reward_kind === 'credit' ? ( money( it.reward_amount ) + ' credit' ) : ( parseInt( it.reward_amount, 10 ) + ' pts' );
			var approve = el( 'button', { 'class': 'button button-small button-primary', text: 'Approve' } );
			var reject = el( 'button', { 'class': 'button button-small', text: 'Reject', style: 'margin-inline-start:.4rem' } );
			var tr = el( 'tr', {}, [
				el( 'td', { text: it.beneficiary.name + ' (' + it.role + ')' } ),
				el( 'td', { text: '#' + it.order_id + ' · ' + money( it.order_total ) } ),
				el( 'td', { text: 'L' + it.level } ),
				el( 'td', { text: reward } ),
				el( 'td', {}, [ approve, reject ] )
			] );
			function act( verb ) {
				approve.disabled = reject.disabled = true;
				api( '/earnings/' + it.id + '/' + verb, 'POST' ).then( function ( out ) {
					if ( out.ok ) { tr.parentNode.removeChild( tr ); }
					else { approve.disabled = reject.disabled = false; }
				} );
			}
			approve.addEventListener( 'click', function () { act( 'approve' ); } );
			reject.addEventListener( 'click', function () { act( 'reject' ); } );
			tb.appendChild( tr );
		} );
		table.appendChild( tb );
		wrap.appendChild( table );
		return wrap;
	}

	function renderReferrals( items ) {
		var wrap = el( 'div', { 'class': 'yzrw-card' } );
		wrap.appendChild( el( 'h2', { text: 'Referrals' } ) );
		if ( ! items.length ) {
			wrap.appendChild( el( 'p', { 'class': 'description', text: 'No referrals yet.' } ) );
			return wrap;
		}
		var table = el( 'table', { 'class': 'widefat striped' } );
		table.appendChild( el( 'thead', {}, [ el( 'tr', {}, [
			el( 'th', { text: 'Referrer' } ), el( 'th', { text: 'Referred' } ), el( 'th', { text: 'Status' } ),
			el( 'th', { text: 'Orders' } ), el( 'th', { text: 'Revenue' } ), el( 'th', { text: 'Points generated' } )
		] ) ] ) );
		var tb = el( 'tbody' );
		items.forEach( function ( it ) {
			tb.appendChild( el( 'tr', {}, [
				el( 'td', { text: it.referrer.name } ),
				el( 'td', { text: it.referred.name } ),
				el( 'td', { text: it.status } ),
				el( 'td', { text: String( it.orders_count ) } ),
				el( 'td', { text: money( it.revenue_total ) } ),
				el( 'td', { text: String( it.points_generated ) } )
			] ) );
		} );
		table.appendChild( tb );
		wrap.appendChild( table );
		return wrap;
	}

	function boot() {
		root.textContent = 'Loading…';
		Promise.all( [ api( '/settings' ), api( '/earnings' ), api( '' ) ] ).then( function ( res ) {
			root.textContent = '';
			var settings = ( res[ 0 ].body && res[ 0 ].body.referral ) || {};
			var pending = ( res[ 1 ].body && res[ 1 ].body.items ) || [];
			var refs = ( res[ 2 ].body && res[ 2 ].body.items ) || [];
			root.appendChild( renderSettings( settings ) );
			root.appendChild( renderPending( pending ) );
			root.appendChild( renderReferrals( refs ) );
		} ).catch( function () { root.textContent = 'Failed to load.'; } );
	}

	boot();
} )();
