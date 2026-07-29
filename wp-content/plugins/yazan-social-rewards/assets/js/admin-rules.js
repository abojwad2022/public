/**
 * Yazan Rewards — no-code rule builder (native wp-admin).
 *
 * Drives the whole page from the yazan-rewards/v1/rules REST API: it loads the
 * event/condition/action catalogs (/rules/schema), lists existing rules, and
 * builds a rule from an event + condition rows + action rows, then saves it.
 * Same-origin cookie auth + the wp_rest nonce.
 */
( function () {
	'use strict';

	var cfg = window.YazanRulesAdmin || {};
	var root = document.getElementById( 'yzrw-rules-app' );
	if ( ! cfg.restUrl || ! root ) {
		return;
	}

	var schema = null;
	var rules = [];
	var editing = null; // rule object being edited, or null for a new rule.

	/* ------------------------------------------------------------------ */
	/* REST helpers                                                        */
	/* ------------------------------------------------------------------ */

	function api( path, method, body ) {
		return fetch( cfg.restUrl + path, {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( r ) {
			return r.json().then( function ( j ) {
				if ( ! r.ok ) { throw new Error( ( j && j.message ) || 'Request failed' ); }
				return j;
			} );
		} );
	}

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		attrs = attrs || {};
		Object.keys( attrs ).forEach( function ( k ) {
			if ( k === 'class' ) { node.className = attrs[ k ]; }
			else if ( k === 'text' ) { node.textContent = attrs[ k ]; }
			else if ( k === 'html' ) { node.innerHTML = attrs[ k ]; }
			else { node.setAttribute( k, attrs[ k ] ); }
		} );
		( children || [] ).forEach( function ( c ) { if ( c ) { node.appendChild( c ); } } );
		return node;
	}

	function option( value, label, selected ) {
		var o = el( 'option', { value: value, text: label } );
		if ( selected ) { o.selected = true; }
		return o;
	}

	/* ------------------------------------------------------------------ */
	/* Load + top-level render                                             */
	/* ------------------------------------------------------------------ */

	function load() {
		root.textContent = 'Loading…';
		Promise.all( [ api( '/schema' ), api( '' ) ] ).then( function ( res ) {
			schema = res[ 0 ];
			rules = ( res[ 1 ] && res[ 1 ].items ) || [];
			render();
		} ).catch( function ( e ) { root.textContent = e.message; } );
	}

	function render() {
		root.innerHTML = '';
		root.appendChild( renderList() );
		root.appendChild( renderForm() );
	}

	/* ------------------------------------------------------------------ */
	/* Rules list                                                          */
	/* ------------------------------------------------------------------ */

	function eventLabel( key ) {
		var found = key;
		( schema.events || [] ).forEach( function ( g ) {
			g.events.forEach( function ( e ) { if ( e.key === key ) { found = e.label; } } );
		} );
		return found;
	}

	function renderList() {
		var wrap = el( 'div', { class: 'yzrw-card' } );
		wrap.appendChild( el( 'h2', { text: 'Rules (' + rules.length + ')' } ) );
		if ( ! rules.length ) {
			wrap.appendChild( el( 'p', { class: 'description', text: 'No rules yet — build one below.' } ) );
			return wrap;
		}
		var table = el( 'table', { class: 'widefat striped' } );
		var thead = el( 'thead', {}, [ el( 'tr', {}, [
			el( 'th', { text: 'Name' } ), el( 'th', { text: 'Event' } ),
			el( 'th', { text: 'Conditions' } ), el( 'th', { text: 'Actions' } ),
			el( 'th', { text: 'Active' } ), el( 'th', { text: '' } )
		] ) ] );
		table.appendChild( thead );
		var tbody = el( 'tbody' );
		rules.forEach( function ( r ) {
			var editBtn = el( 'button', { class: 'button button-small', text: 'Edit' } );
			editBtn.addEventListener( 'click', function () { editing = r; render(); window.scrollTo( 0, 0 ); } );
			var delBtn = el( 'button', { class: 'button button-small button-link-delete', text: 'Delete' } );
			delBtn.addEventListener( 'click', function () {
				if ( ! window.confirm( 'Delete this rule?' ) ) { return; }
				api( '/' + r.id, 'DELETE' ).then( load );
			} );
			tbody.appendChild( el( 'tr', {}, [
				el( 'td', { text: r.name || '(untitled)' } ),
				el( 'td', { text: eventLabel( r.event ) } ),
				el( 'td', { text: String( Object.keys( r.conditions || {} ).length ) } ),
				el( 'td', { text: String( ( r.actions || [] ).length ) } ),
				el( 'td', { text: r.active ? '✓' : '—' } ),
				el( 'td', {}, [ editBtn, document.createTextNode( ' ' ), delBtn ] )
			] ) );
		} );
		table.appendChild( tbody );
		wrap.appendChild( table );
		return wrap;
	}

	/* ------------------------------------------------------------------ */
	/* Builder form                                                        */
	/* ------------------------------------------------------------------ */

	function renderForm() {
		var wrap = el( 'div', { class: 'yzrw-card yzrw-builder' } );
		wrap.appendChild( el( 'h2', { text: editing ? 'Edit rule #' + editing.id : 'New rule' } ) );

		// Name.
		var name = el( 'input', { type: 'text', class: 'regular-text', placeholder: 'Rule name' } );
		name.value = editing ? ( editing.name || '' ) : '';
		wrap.appendChild( field( 'Name', name ) );

		// Event.
		var eventSel = el( 'select', { class: 'yzrw-event' } );
		( schema.events || [] ).forEach( function ( g ) {
			var og = el( 'optgroup', { label: g.label } );
			g.events.forEach( function ( e ) {
				og.appendChild( option( e.key, e.label + ( e.wired ? '' : ' (trigger)' ), editing && editing.event === e.key ) );
			} );
			eventSel.appendChild( og );
		} );
		wrap.appendChild( field( 'Event', eventSel ) );

		// Conditions.
		var condBox = el( 'div', { class: 'yzrw-rows', id: 'yzrw-conditions' } );
		if ( editing && editing.conditions ) {
			Object.keys( editing.conditions ).forEach( function ( k ) { condBox.appendChild( conditionRow( k, editing.conditions[ k ] ) ); } );
		}
		var addCond = el( 'button', { class: 'button', text: '+ Add condition' } );
		addCond.addEventListener( 'click', function () { condBox.appendChild( conditionRow( null, null ) ); } );
		wrap.appendChild( section( 'Conditions', condBox, addCond ) );

		// Actions.
		var actBox = el( 'div', { class: 'yzrw-rows', id: 'yzrw-actions' } );
		if ( editing && editing.actions ) {
			editing.actions.forEach( function ( a ) { actBox.appendChild( actionRow( a.type, a.params || a ) ); } );
		}
		var addAct = el( 'button', { class: 'button', text: '+ Add action' } );
		addAct.addEventListener( 'click', function () { actBox.appendChild( actionRow( null, null ) ); } );
		wrap.appendChild( section( 'Actions', actBox, addAct ) );

		// Priority / cap / active.
		var prio = el( 'input', { type: 'number', class: 'small-text', value: editing ? String( editing.priority || 10 ) : '10' } );
		var cap = el( 'input', { type: 'number', class: 'small-text', value: editing ? String( editing.per_user_cap || 0 ) : '0' } );
		var active = el( 'input', { type: 'checkbox' } );
		active.checked = editing ? !! editing.active : true;
		wrap.appendChild( field( 'Priority', prio ) );
		wrap.appendChild( field( 'Per-user cap (0 = unlimited)', cap ) );
		wrap.appendChild( field( 'Active', active ) );

		// Save / cancel.
		var msg = el( 'span', { class: 'yzrw-msg' } );
		var save = el( 'button', { class: 'button button-primary', text: editing ? 'Update rule' : 'Create rule' } );
		save.addEventListener( 'click', function () {
			var payload = {
				name: name.value,
				event: eventSel.value,
				conditions: collectConditions( condBox ),
				actions: collectActions( actBox ),
				priority: parseInt( prio.value, 10 ) || 10,
				per_user_cap: parseInt( cap.value, 10 ) || 0,
				active: active.checked
			};
			var req = editing ? api( '/' + editing.id, 'PUT', payload ) : api( '', 'POST', payload );
			save.disabled = true;
			req.then( function () { editing = null; load(); } )
				.catch( function ( e ) { msg.textContent = e.message; save.disabled = false; } );
		} );
		var actions = [ save, msg ];
		if ( editing ) {
			var cancel = el( 'button', { class: 'button', text: 'Cancel' } );
			cancel.addEventListener( 'click', function () { editing = null; render(); } );
			actions.splice( 1, 0, cancel );
		}
		wrap.appendChild( el( 'p', { class: 'yzrw-save' }, actions ) );
		return wrap;
	}

	function field( label, input ) {
		return el( 'p', { class: 'yzrw-field' }, [ el( 'label', { text: label } ), input ] );
	}
	function section( label, box, addBtn ) {
		return el( 'div', { class: 'yzrw-section' }, [ el( 'h3', { text: label } ), box, addBtn ] );
	}

	/* ------------------------------------------------------------------ */
	/* Condition rows                                                      */
	/* ------------------------------------------------------------------ */

	function conditionDef( key ) {
		return ( schema.conditions || [] ).filter( function ( c ) { return c.key === key; } )[ 0 ] || null;
	}

	function conditionRow( key, value ) {
		var row = el( 'div', { class: 'yzrw-row' } );
		var sel = el( 'select', { class: 'yzrw-cond-key' } );
		sel.appendChild( option( '', '— condition —', false ) );
		( schema.conditions || [] ).forEach( function ( c ) { sel.appendChild( option( c.key, c.label, c.key === key ) ); } );
		var inputWrap = el( 'span', { class: 'yzrw-cond-input' } );
		function rebuild() { inputWrap.innerHTML = ''; buildConditionInput( inputWrap, sel.value, value ); value = null; }
		sel.addEventListener( 'change', rebuild );
		var rm = el( 'button', { class: 'button-link yzrw-remove', text: '✕' } );
		rm.addEventListener( 'click', function () { row.remove(); } );
		row.appendChild( sel ); row.appendChild( inputWrap ); row.appendChild( rm );
		if ( key ) { buildConditionInput( inputWrap, key, value ); }
		return row;
	}

	function buildConditionInput( wrap, key, value ) {
		var def = conditionDef( key );
		if ( ! def ) { return; }
		if ( def.input === 'number_op' ) {
			var op = el( 'select', { class: 'yzrw-op' } );
			( schema.operators || [ '>=', '>', '<=', '<', '=', '!=' ] ).forEach( function ( o ) { op.appendChild( option( o, o, value && value.op === o ) ); } );
			var num = el( 'input', { type: 'number', class: 'small-text yzrw-val' } );
			if ( value && typeof value.value !== 'undefined' ) { num.value = value.value; }
			wrap.appendChild( op ); wrap.appendChild( num );
		} else if ( def.input === 'tier' || def.input === 'role' || def.input === 'achievement' ) {
			var opts = def.input === 'tier' ? schema.options.tiers : ( def.input === 'role' ? schema.options.roles : schema.options.achievements );
			var multi = el( 'select', { class: 'yzrw-val', multiple: 'multiple', size: '3' } );
			( opts || [] ).forEach( function ( o ) {
				var seld = Array.isArray( value ) ? value.indexOf( o.value ) >= 0 : false;
				multi.appendChild( option( o.value, o.label, seld ) );
			} );
			wrap.appendChild( multi );
		} else if ( def.input === 'date_range' ) {
			var from = el( 'input', { type: 'date', class: 'yzrw-from' } );
			var to = el( 'input', { type: 'date', class: 'yzrw-to' } );
			if ( value && value.from ) { from.value = value.from; }
			if ( value && value.to ) { to.value = value.to; }
			wrap.appendChild( from ); wrap.appendChild( document.createTextNode( ' → ' ) ); wrap.appendChild( to );
		} else { // product / category / free text → comma-separated ids.
			var txt = el( 'input', { type: 'text', class: 'regular-text yzrw-val', placeholder: 'comma-separated IDs' } );
			if ( Array.isArray( value ) ) { txt.value = value.join( ',' ); }
			wrap.appendChild( txt );
		}
	}

	function collectConditions( box ) {
		var out = {};
		Array.prototype.forEach.call( box.querySelectorAll( '.yzrw-row' ), function ( row ) {
			var key = row.querySelector( '.yzrw-cond-key' ).value;
			var def = conditionDef( key );
			if ( ! key || ! def ) { return; }
			if ( def.input === 'number_op' ) {
				out[ key ] = { op: row.querySelector( '.yzrw-op' ).value, value: parseFloat( row.querySelector( '.yzrw-val' ).value ) || 0 };
			} else if ( def.input === 'tier' || def.input === 'role' || def.input === 'achievement' ) {
				out[ key ] = Array.prototype.map.call( row.querySelectorAll( '.yzrw-val option:checked' ), function ( o ) { return o.value; } );
			} else if ( def.input === 'date_range' ) {
				var d = {}; var f = row.querySelector( '.yzrw-from' ).value; var t = row.querySelector( '.yzrw-to' ).value;
				if ( f ) { d.from = f; } if ( t ) { d.to = t; } out[ key ] = d;
			} else {
				out[ key ] = row.querySelector( '.yzrw-val' ).value.split( ',' ).map( function ( s ) { return parseInt( s.trim(), 10 ); } ).filter( function ( n ) { return ! isNaN( n ); } );
			}
		} );
		return out;
	}

	/* ------------------------------------------------------------------ */
	/* Action rows                                                         */
	/* ------------------------------------------------------------------ */

	function actionDef( key ) {
		return ( schema.actions || [] ).filter( function ( a ) { return a.key === key; } )[ 0 ] || null;
	}

	function actionRow( type, params ) {
		var row = el( 'div', { class: 'yzrw-row' } );
		var sel = el( 'select', { class: 'yzrw-act-key' } );
		sel.appendChild( option( '', '— action —', false ) );
		( schema.actions || [] ).forEach( function ( a ) { sel.appendChild( option( a.key, a.label, a.key === type ) ); } );
		var paramWrap = el( 'span', { class: 'yzrw-act-params' } );
		function rebuild() { paramWrap.innerHTML = ''; buildActionParams( paramWrap, sel.value, params ); params = null; }
		sel.addEventListener( 'change', rebuild );
		var rm = el( 'button', { class: 'button-link yzrw-remove', text: '✕' } );
		rm.addEventListener( 'click', function () { row.remove(); } );
		row.appendChild( sel ); row.appendChild( paramWrap ); row.appendChild( rm );
		if ( type ) { buildActionParams( paramWrap, type, params ); }
		return row;
	}

	function buildActionParams( wrap, type, params ) {
		params = params || {};
		function num( key, ph ) {
			var i = el( 'input', { type: 'number', class: 'small-text yzrw-p', 'data-k': key, placeholder: ph || key } );
			if ( typeof params[ key ] !== 'undefined' ) { i.value = params[ key ]; }
			return i;
		}
		function selectFrom( key, opts ) {
			var s = el( 'select', { class: 'yzrw-p', 'data-k': key } );
			( opts || [] ).forEach( function ( o ) { s.appendChild( option( o.value, o.label, params[ key ] === o.value ) ); } );
			return s;
		}
		if ( type === 'add_points' ) {
			wrap.appendChild( num( 'amount', 'points' ) );
			wrap.appendChild( num( 'per_currency', 'or × order total' ) );
		} else if ( type === 'remove_points' ) {
			wrap.appendChild( num( 'amount', 'points' ) );
		} else if ( type === 'upgrade_level' ) {
			wrap.appendChild( selectFrom( 'tier', schema.options.tiers ) );
		} else if ( type === 'give_badge' ) {
			wrap.appendChild( selectFrom( 'achievement', schema.options.achievements ) );
		} else if ( type === 'create_coupon' ) {
			wrap.appendChild( selectFrom( 'discount_type', [ { value: 'percent', label: '% off' }, { value: 'fixed_cart', label: 'Fixed $' } ] ) );
			wrap.appendChild( num( 'amount', 'amount' ) );
			wrap.appendChild( num( 'expiry_days', 'expiry days' ) );
		} else if ( type === 'send_notification' ) {
			var subj = el( 'input', { type: 'text', class: 'regular-text yzrw-p', 'data-k': 'subject', placeholder: 'Subject' } );
			var body = el( 'textarea', { class: 'yzrw-p', 'data-k': 'message', rows: '2', placeholder: 'Message' } );
			if ( params.subject ) { subj.value = params.subject; }
			if ( params.message ) { body.value = params.message; }
			wrap.appendChild( subj ); wrap.appendChild( body );
		}
	}

	function collectActions( box ) {
		var out = [];
		Array.prototype.forEach.call( box.querySelectorAll( '.yzrw-row' ), function ( row ) {
			var type = row.querySelector( '.yzrw-act-key' ).value;
			if ( ! type ) { return; }
			var params = {};
			Array.prototype.forEach.call( row.querySelectorAll( '.yzrw-p' ), function ( inp ) {
				var k = inp.getAttribute( 'data-k' );
				var v = inp.value;
				if ( v === '' ) { return; }
				params[ k ] = ( inp.type === 'number' ) ? ( parseFloat( v ) || 0 ) : v;
			} );
			out.push( { type: type, params: params } );
		} );
		return out;
	}

	load();
} )();
