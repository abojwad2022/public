/**
 * Yazan Rewards — rewards catalog manager (no-code CRUD).
 */
( function () {
	'use strict';
	var cfg = window.YazanRewardsAdmin || {};
	var root = document.getElementById( 'yzrw-rewards-app' );
	if ( ! cfg.restUrl || ! root ) { return; }

	var schema = null, rewards = [], editing = null;

	function api( path, method, body ) {
		return fetch( cfg.restUrl + path, {
			method: method || 'GET', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( r ) { return r.json().then( function ( j ) { if ( ! r.ok ) { throw new Error( ( j && j.message ) || 'Error' ); } return j; } ); } );
	}
	function el( t, a, c ) { var n = document.createElement( t ); a = a || {}; Object.keys( a ).forEach( function ( k ) { if ( k === 'text' ) { n.textContent = a[ k ]; } else if ( k === 'html' ) { n.innerHTML = a[ k ]; } else { n.setAttribute( k, a[ k ] ); } } ); ( c || [] ).forEach( function ( x ) { if ( x ) { n.appendChild( x ); } } ); return n; }
	function opt( v, l, s ) { var o = el( 'option', { value: v, text: l } ); if ( s ) { o.selected = true; } return o; }
	function field( label, input ) { return el( 'p', { class: 'yzrw-field' }, [ el( 'label', { text: label } ), input ] ); }

	function load() {
		root.textContent = 'Loading…';
		Promise.all( [ api( '/schema' ), api( '' ) ] ).then( function ( res ) {
			schema = res[ 0 ]; rewards = ( res[ 1 ] && res[ 1 ].items ) || []; render();
		} ).catch( function ( e ) { root.textContent = e.message; } );
	}
	function typeLabel( t ) { var f = t; ( schema.types || [] ).forEach( function ( x ) { if ( x.value === t ) { f = x.label; } } ); return f; }
	function typeDef( t ) { return ( schema.types || [] ).filter( function ( x ) { return x.value === t; } )[ 0 ] || { fields: [] }; }

	// New-reward/Edit FORM on top, then the Rewards list below it — so the create
	// form is reached first and an Edit (which scrolls to top) reveals the form.
	function render() { root.innerHTML = ''; root.appendChild( form() ); root.appendChild( list() ); }

	function list() {
		var w = el( 'div', { class: 'yzrw-card' } );
		w.appendChild( el( 'h2', { text: 'Rewards (' + rewards.length + ')' } ) );
		if ( ! rewards.length ) { w.appendChild( el( 'p', { class: 'description', text: 'No rewards yet — create one below.' } ) ); return w; }
		var table = el( 'table', { class: 'widefat striped yzrw-table' } );
		table.appendChild( el( 'thead', {}, [ el( 'tr', {}, [ el( 'th', { text: 'Title' } ), el( 'th', { text: 'Type' } ), el( 'th', { text: 'Points' } ), el( 'th', { text: 'Limit/user' } ), el( 'th', { text: 'Active' } ), el( 'th', { text: '' } ) ] ) ] ) );
		var tb = el( 'tbody' );
		rewards.forEach( function ( r ) {
			var edit = el( 'button', { class: 'button button-small', text: 'Edit' } );
			edit.addEventListener( 'click', function () { editing = r; render(); window.scrollTo( 0, 0 ); } );
			var del = el( 'button', { class: 'button button-small button-link-delete', text: 'Delete' } );
			del.addEventListener( 'click', function () { if ( window.confirm( 'Delete this reward?' ) ) { api( '/' + r.id, 'DELETE' ).then( load ); } } );
			tb.appendChild( el( 'tr', {}, [
				el( 'td', { text: r.title } ), el( 'td', { text: typeLabel( r.type ) } ),
				el( 'td', { text: String( r.cost_points ) } ), el( 'td', { text: r.per_user_limit ? String( r.per_user_limit ) : '∞' } ),
				el( 'td', { text: r.active ? '✓' : '—' } ),
				el( 'td', { class: 'yzrw-actions-cell' }, [ edit, del ] )
			] ) );
		} );
		table.appendChild( tb ); w.appendChild( table ); return w;
	}

	function form() {
		var w = el( 'div', { class: 'yzrw-card' } );
		w.appendChild( el( 'h2', { text: editing ? 'Edit reward #' + editing.id : 'New reward' } ) );
		var e = editing || {};

		var title = el( 'input', { type: 'text', class: 'regular-text' } ); title.value = e.title || '';
		var desc = el( 'textarea', { class: 'large-text', rows: '2' } ); desc.value = e.description || '';
		var type = el( 'select', {} );
		( schema.types || [] ).forEach( function ( t ) { type.appendChild( opt( t.value, t.label, e.type === t.value ) ); } );
		var cost = el( 'input', { type: 'number', class: 'small-text' } ); cost.value = e.cost_points != null ? String( e.cost_points ) : '';
		var stock = el( 'input', { type: 'number', class: 'small-text' } ); stock.value = e.stock != null ? String( e.stock ) : '-1';
		var limit = el( 'input', { type: 'number', class: 'small-text' } ); limit.value = e.per_user_limit != null ? String( e.per_user_limit ) : '0';
		var from = el( 'input', { type: 'date' } ); if ( e.available_from ) { from.value = String( e.available_from ).substr( 0, 10 ); }
		var to = el( 'input', { type: 'date' } ); if ( e.available_to ) { to.value = String( e.available_to ).substr( 0, 10 ); }
		var active = el( 'input', { type: 'checkbox' } ); active.checked = editing ? !! e.active : true;

		var valueWrap = el( 'span', { class: 'yzrw-value-fields' } );
		function buildValue() {
			valueWrap.innerHTML = '';
			var def = typeDef( type.value ); var v = e.value || {};
			( def.fields || [] ).forEach( function ( f ) {
				var input;
				if ( f === 'discount_type' ) { input = el( 'select', { 'data-vk': f } ); input.appendChild( opt( 'percent', '% off', v.discount_type === 'percent' ) ); input.appendChild( opt( 'fixed_cart', 'Fixed $', v.discount_type === 'fixed_cart' ) ); }
				else if ( f === 'product_ids' ) { input = el( 'input', { type: 'text', 'data-vk': f, placeholder: 'product IDs (comma)' } ); if ( v.product_ids ) { input.value = ( v.product_ids || [] ).join( ',' ); } }
				else { input = el( 'input', { type: 'number', 'data-vk': f, placeholder: f } ); if ( v[ f ] != null ) { input.value = v[ f ]; } }
				valueWrap.appendChild( el( 'label', { text: f.replace( /_/g, ' ' ) + ':' } ) );
				valueWrap.appendChild( input );
			} );
		}
		type.addEventListener( 'change', function () { e.value = e.value || {}; buildValue(); } );
		buildValue();

		w.appendChild( field( 'Title', title ) );
		w.appendChild( field( 'Description', desc ) );
		w.appendChild( field( 'Type', type ) );
		w.appendChild( field( 'Value', valueWrap ) );
		w.appendChild( field( 'Required points', cost ) );
		w.appendChild( field( 'Stock (-1 = unlimited)', stock ) );
		w.appendChild( field( 'Usage limit / user (0 = unlimited)', limit ) );
		w.appendChild( field( 'Available from', from ) );
		w.appendChild( field( 'Available to (expiration)', to ) );
		w.appendChild( field( 'Active', active ) );

		var msg = el( 'span', { class: 'yzrw-msg' } );
		var save = el( 'button', { class: 'button button-primary', text: editing ? 'Update' : 'Create' } );
		save.addEventListener( 'click', function () {
			var value = {};
			Array.prototype.forEach.call( valueWrap.querySelectorAll( '[data-vk]' ), function ( inp ) {
				var k = inp.getAttribute( 'data-vk' ); var val = inp.value; if ( val === '' ) { return; }
				if ( k === 'product_ids' ) { value[ k ] = val.split( ',' ).map( function ( s ) { return parseInt( s.trim(), 10 ); } ).filter( function ( n ) { return ! isNaN( n ); } ); }
				else { value[ k ] = ( inp.type === 'number' ) ? ( parseFloat( val ) || 0 ) : val; }
			} );
			var payload = {
				title: title.value, description: desc.value, type: type.value,
				cost_points: parseInt( cost.value, 10 ) || 0, stock: parseInt( stock.value, 10 ),
				per_user_limit: parseInt( limit.value, 10 ) || 0, active: active.checked,
				available_from: from.value, available_to: to.value, value: value
			};
			save.disabled = true;
			var req = editing ? api( '/' + editing.id, 'PUT', payload ) : api( '', 'POST', payload );
			req.then( function () { editing = null; load(); } ).catch( function ( err ) { msg.textContent = err.message; save.disabled = false; } );
		} );
		var actions = [ save, msg ];
		if ( editing ) { var cancel = el( 'button', { class: 'button', text: 'Cancel' } ); cancel.addEventListener( 'click', function () { editing = null; render(); } ); actions.splice( 1, 0, cancel ); }
		w.appendChild( el( 'p', { class: 'yzrw-save' }, actions ) );
		return w;
	}

	load();
} )();
