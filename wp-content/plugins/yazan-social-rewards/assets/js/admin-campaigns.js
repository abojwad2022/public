/**
 * Yazan Rewards — marketing campaign manager (builder + review + analytics).
 */
( function () {
	'use strict';
	var cfg = window.YazanCampaignsAdmin || {};
	var root = document.getElementById( 'yzrw-campaigns-app' );
	if ( ! cfg.restUrl || ! root ) { return; }

	var campaigns = [], statuses = [], editing = null, view = 'list';

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
		api( '' ).then( function ( d ) { campaigns = ( d && d.items ) || []; statuses = ( d && d.statuses ) || []; render(); } ).catch( function ( e ) { root.textContent = e.message; } );
	}

	function render() {
		root.innerHTML = '';
		if ( view === 'submissions' ) { renderSubmissions(); return; }
		if ( view === 'analytics' ) { renderAnalytics(); return; }
		root.appendChild( toolbar() );
		root.appendChild( list() );
		root.appendChild( form() );
	}

	function toolbar() {
		var bar = el( 'p', {} );
		var subs = el( 'button', { class: 'button', text: 'Submissions queue' } );
		subs.addEventListener( 'click', function () { view = 'submissions'; render(); } );
		bar.appendChild( subs );
		return bar;
	}

	function list() {
		var w = el( 'div', { class: 'yzrw-card' } );
		w.appendChild( el( 'h2', { text: 'Campaigns (' + campaigns.length + ')' } ) );
		if ( ! campaigns.length ) { w.appendChild( el( 'p', { class: 'description', text: 'No campaigns yet — create one below.' } ) ); return w; }
		var table = el( 'table', { class: 'widefat striped yzrw-table' } );
		table.appendChild( el( 'thead', {}, [ el( 'tr', {}, [ el( 'th', { text: 'Title' } ), el( 'th', { text: 'Status' } ), el( 'th', { text: 'Tasks' } ), el( 'th', { text: 'Reward' } ), el( 'th', { text: '' } ) ] ) ] ) );
		var tb = el( 'tbody' );
		campaigns.forEach( function ( cmp ) {
			var edit = el( 'button', { class: 'button button-small', text: 'Edit' } );
			edit.addEventListener( 'click', function () { openEdit( cmp.id ); } );
			var stats = el( 'button', { class: 'button button-small', text: 'Analytics' } );
			stats.addEventListener( 'click', function () { view = 'analytics'; editing = { id: cmp.id, title: cmp.title }; render(); } );
			var del = el( 'button', { class: 'button button-small button-link-delete', text: 'Delete' } );
			del.addEventListener( 'click', function () { if ( window.confirm( 'Delete this campaign?' ) ) { api( '/' + cmp.id, 'DELETE' ).then( load ); } } );
			tb.appendChild( el( 'tr', {}, [
				el( 'td', { text: cmp.title } ),
				el( 'td', {}, [ el( 'span', { class: 'yzrw-pill ' + cmp.status, text: cmp.status } ) ] ),
				el( 'td', { text: String( cmp.tasks || 0 ) } ),
				el( 'td', { text: String( cmp.reward_amount ) } ),
				el( 'td', { class: 'yzrw-actions-cell' }, [ edit, stats, del ] )
			] ) );
		} );
		table.appendChild( tb ); w.appendChild( table ); return w;
	}

	function openEdit( id ) { api( '/' + id ).then( function ( c ) { editing = c; render(); window.scrollTo( 0, 0 ); } ); }

	function form() {
		var e = editing || { tasks: [], target: { type: 'all', values: [] }, media: { images: [], videos: [] } };
		var w = el( 'div', { class: 'yzrw-card' } );
		w.appendChild( el( 'h2', { text: editing && editing.id ? 'Edit campaign #' + editing.id : 'New campaign' } ) );

		var title = el( 'input', { type: 'text', class: 'regular-text' } ); title.value = e.title || '';
		var desc = el( 'textarea', { class: 'large-text', rows: '2' } ); desc.value = e.description || '';
		var images = el( 'input', { type: 'text', class: 'large-text', placeholder: 'image URLs (comma)' } ); images.value = ( ( e.media && e.media.images ) || [] ).join( ',' );
		var videos = el( 'input', { type: 'text', class: 'large-text', placeholder: 'video URLs (comma)' } ); videos.value = ( ( e.media && e.media.videos ) || [] ).join( ',' );
		var start = el( 'input', { type: 'date' } ); if ( e.starts_at ) { start.value = String( e.starts_at ).substr( 0, 10 ); }
		var end = el( 'input', { type: 'date' } ); if ( e.ends_at ) { end.value = String( e.ends_at ).substr( 0, 10 ); }
		var reward = el( 'input', { type: 'number', class: 'small-text' } ); reward.value = e.reward_amount != null ? String( e.reward_amount ) : '0';

		var targetType = el( 'select', {} );
		[ [ 'all', 'Everyone' ], [ 'tier', 'By tier' ], [ 'role', 'By role' ], [ 'users', 'Specific users' ] ].forEach( function ( o ) { targetType.appendChild( opt( o[ 0 ], o[ 1 ], ( e.target && e.target.type ) === o[ 0 ] ) ); } );
		var targetValues = el( 'input', { type: 'text', class: 'regular-text', placeholder: 'tier slugs / roles / user IDs (comma)' } ); targetValues.value = ( ( e.target && e.target.values ) || [] ).join( ',' );

		// Tasks repeater.
		var taskBox = el( 'div', { class: 'yzrw-rows' } );
		function taskRow( t ) {
			t = t || {};
			var row = el( 'div', { class: 'yzrw-row' } );
			var nm = el( 'input', { type: 'text', 'data-tk': 'name', placeholder: 'Task name' } ); nm.value = t.name || '';
			var ty = el( 'select', { 'data-tk': 'task_type' } );
			[ 'photo', 'video', 'review', 'views', 'custom' ].forEach( function ( x ) { ty.appendChild( opt( x, x, t.task_type === x ) ); } );
			var pts = el( 'input', { type: 'number', class: 'small-text', 'data-tk': 'points_award', placeholder: 'points' } ); if ( t.points_award != null ) { pts.value = t.points_award; }
			var req = el( 'input', { type: 'checkbox', 'data-tk': 'required' } ); req.checked = !! t.required;
			var reqL = el( 'label', {}, [ req, document.createTextNode( ' required' ) ] );
			var rm = el( 'button', { class: 'button-link yzrw-remove', text: '✕' } ); rm.addEventListener( 'click', function () { row.remove(); } );
			row.appendChild( nm ); row.appendChild( ty ); row.appendChild( pts ); row.appendChild( reqL ); row.appendChild( rm );
			return row;
		}
		( e.tasks || [] ).forEach( function ( t ) { taskBox.appendChild( taskRow( t ) ); } );
		var addTask = el( 'button', { class: 'button', text: '+ Add task' } );
		addTask.addEventListener( 'click', function () { taskBox.appendChild( taskRow( {} ) ); } );

		w.appendChild( field( 'Title', title ) );
		w.appendChild( field( 'Description', desc ) );
		w.appendChild( field( 'Images', images ) );
		w.appendChild( field( 'Videos', videos ) );
		w.appendChild( field( 'Start date', start ) );
		w.appendChild( field( 'End date', end ) );
		w.appendChild( field( 'Target', targetType ) );
		w.appendChild( field( 'Target values', targetValues ) );
		w.appendChild( field( 'Completion bonus', reward ) );
		w.appendChild( el( 'div', { class: 'yzrw-section' }, [ el( 'h3', { text: 'Tasks' } ), taskBox, addTask ] ) );

		// Status workflow (only when editing).
		if ( editing && editing.id ) {
			var statusWrap = el( 'div', { class: 'yzrw-section' }, [ el( 'h3', { text: 'Status: ' + e.status } ) ] );
			statuses.forEach( function ( s ) {
				if ( s === e.status ) { return; }
				var b = el( 'button', { class: 'button button-small', text: '→ ' + s } );
				b.addEventListener( 'click', function () { api( '/' + editing.id + '/status', 'POST', { status: s } ).then( function () { openEdit( editing.id ); } ).catch( function ( err ) { window.alert( err.message ); } ); } );
				statusWrap.appendChild( b );
			} );
			w.appendChild( statusWrap );
		}

		var msg = el( 'span', { class: 'yzrw-msg' } );
		var save = el( 'button', { class: 'button button-primary', text: editing && editing.id ? 'Update' : 'Create' } );
		save.addEventListener( 'click', function () {
			var tasks = Array.prototype.map.call( taskBox.querySelectorAll( '.yzrw-row' ), function ( row ) {
				return {
					name: row.querySelector( '[data-tk="name"]' ).value,
					task_type: row.querySelector( '[data-tk="task_type"]' ).value,
					points_award: parseInt( row.querySelector( '[data-tk="points_award"]' ).value, 10 ) || 0,
					required: row.querySelector( '[data-tk="required"]' ).checked
				};
			} ).filter( function ( t ) { return t.name; } );
			var payload = {
				title: title.value, description: desc.value,
				media: { images: images.value.split( ',' ).map( trim ).filter( Boolean ), videos: videos.value.split( ',' ).map( trim ).filter( Boolean ) },
				starts_at: start.value, ends_at: end.value, reward_amount: parseInt( reward.value, 10 ) || 0,
				target: { type: targetType.value, values: targetValues.value.split( ',' ).map( trim ).filter( Boolean ) },
				tasks: tasks
			};
			save.disabled = true;
			var req = ( editing && editing.id ) ? api( '/' + editing.id, 'PUT', payload ) : api( '', 'POST', payload );
			req.then( function () { editing = null; load(); } ).catch( function ( err ) { msg.textContent = err.message; save.disabled = false; } );
		} );
		var actions = [ save, msg ];
		if ( editing && editing.id ) { var cancel = el( 'button', { class: 'button', text: 'Cancel' } ); cancel.addEventListener( 'click', function () { editing = null; render(); } ); actions.splice( 1, 0, cancel ); }
		w.appendChild( el( 'p', { class: 'yzrw-save' }, actions ) );
		return w;
	}
	function trim( s ) { return s.trim(); }

	function renderSubmissions() {
		var back = el( 'button', { class: 'button', text: '← Back' } ); back.addEventListener( 'click', function () { view = 'list'; render(); } );
		root.appendChild( el( 'p', {}, [ back ] ) );
		var card = el( 'div', { class: 'yzrw-card' } );
		card.appendChild( el( 'h2', { text: 'Submissions to review' } ) );
		root.appendChild( card );
		api( '/submissions' ).then( function ( d ) {
			var items = ( d && d.items ) || [];
			if ( ! items.length ) { card.appendChild( el( 'p', { class: 'description', text: 'No pending submissions.' } ) ); return; }
			var table = el( 'table', { class: 'widefat striped yzrw-table' } );
			table.appendChild( el( 'thead', {}, [ el( 'tr', {}, [ el( 'th', { text: 'Customer' } ), el( 'th', { text: 'Type' } ), el( 'th', { text: 'Content' } ), el( 'th', { text: 'Points' } ), el( 'th', { text: '' } ) ] ) ] ) );
			var tb = el( 'tbody' );
			items.forEach( function ( s ) {
				var link = s.content_url ? el( 'a', { href: s.content_url, target: '_blank', text: 'view' } ) : el( 'span', { text: ( s.metric_value || '' ) + '' } );
				var ap = el( 'button', { class: 'button button-small button-primary', text: 'Approve' } ); ap.addEventListener( 'click', function () { api( '/submissions/' + s.id + '/approve', 'POST' ).then( renderSubmissions ); } );
				var rj = el( 'button', { class: 'button button-small', text: 'Reject' } ); rj.addEventListener( 'click', function () { api( '/submissions/' + s.id + '/reject', 'POST' ).then( renderSubmissions ); } );
				tb.appendChild( el( 'tr', {}, [ el( 'td', { text: s.user } ), el( 'td', { text: ( s.type || '' ).replace( /_/g, ' ' ) } ), el( 'td', {}, [ link ] ), el( 'td', { text: String( s.points_award ) } ), el( 'td', { class: 'yzrw-actions-cell' }, [ ap, rj ] ) ] ) );
			} );
			table.appendChild( tb ); card.appendChild( table );
		} ).catch( function ( e ) { card.appendChild( el( 'p', { class: 'yzrw-msg', text: e.message } ) ); } );
	}

	function renderAnalytics() {
		var back = el( 'button', { class: 'button', text: '← Back' } ); back.addEventListener( 'click', function () { view = 'list'; editing = null; render(); } );
		root.appendChild( el( 'p', {}, [ back ] ) );
		var card = el( 'div', { class: 'yzrw-card' } );
		card.appendChild( el( 'h2', { text: 'Analytics — ' + ( editing.title || ( '#' + editing.id ) ) } ) );
		root.appendChild( card );
		api( '/' + editing.id + '/analytics' ).then( function ( a ) {
			var rows = [ [ 'Participants', a.participants ], [ 'Submissions', a.submissions ], [ 'Approved', a.approved ], [ 'Rejected', a.rejected ], [ 'Pending', a.pending ], [ 'Completions', a.completions ], [ 'Points generated', a.points_generated ], [ 'Engagement rate', ( a.engagement_rate * 100 ).toFixed( 1 ) + '%' ] ];
			var table = el( 'table', { class: 'widefat striped yzrw-table' } );
			var tb = el( 'tbody' );
			rows.forEach( function ( r ) { tb.appendChild( el( 'tr', {}, [ el( 'th', { text: r[ 0 ] } ), el( 'td', { text: String( r[ 1 ] ) } ) ] ) ); } );
			table.appendChild( tb ); card.appendChild( table );
		} ).catch( function ( e ) { card.appendChild( el( 'p', { class: 'yzrw-msg', text: e.message } ) ); } );
	}

	load();
} )();
