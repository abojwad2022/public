/**
 * Yazan Rewards — Notifications admin (channels, webhook, digest, categories, outbox).
 */
( function () {
	'use strict';
	var cfg = window.YazanNotificationsAdmin || {};
	var root = document.getElementById( 'yzrw-notifications-app' );
	if ( ! cfg.restUrl || ! root ) { return; }

	var data = null;

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
	function field( label, input ) { return el( 'p', { class: 'yzrw-field' }, [ el( 'label', { text: label } ), input ] ); }
	function checkbox( checked, disabled ) {
		var c = el( 'input', { type: 'checkbox' } ); c.checked = !! checked; if ( disabled ) { c.disabled = true; } return c;
	}

	function load() {
		root.textContent = 'Loading…';
		api( '/settings' ).then( function ( d ) { data = d; render(); loadOutbox(); } ).catch( function ( e ) { root.textContent = e.message; } );
	}

	function render() {
		root.innerHTML = '';
		root.appendChild( channelsCard() );
		root.appendChild( futureCard() );
		root.appendChild( digestCard() );
		root.appendChild( categoriesCard() );
		root.appendChild( outboxCard() );
	}

	var inputs = {};

	function channelsCard() {
		var w = el( 'div', { class: 'yzrw-card' } );
		w.appendChild( el( 'h2', { text: 'Channels' } ) );

		inputs.email = checkbox( data.channels.email );
		inputs.onsite = checkbox( data.channels.onsite );
		w.appendChild( field( 'Email notifications', inputs.email ) );
		w.appendChild( field( 'On-site inbox', inputs.onsite ) );

		w.appendChild( el( 'h3', { text: 'Webhook (integration)' } ) );
		w.appendChild( el( 'p', { class: 'description', text: 'POST every notification as signed JSON to an HTTPS endpoint (Slack, Zapier, automations). Fires regardless of customer preferences.' } ) );
		inputs.webhookEnabled = checkbox( data.channels.webhook.enabled );
		inputs.webhookUrl = el( 'input', { type: 'url', class: 'regular-text', placeholder: 'https://…' } );
		inputs.webhookUrl.value = data.channels.webhook.url || '';
		inputs.webhookSecret = el( 'input', { type: 'password', class: 'regular-text', autocomplete: 'new-password' } );
		inputs.webhookSecret.placeholder = data.channels.webhook.secret_last4 ? ( '•••• ' + data.channels.webhook.secret_last4 ) : 'signing secret';
		w.appendChild( field( 'Enable webhook', inputs.webhookEnabled ) );
		w.appendChild( field( 'Webhook URL', inputs.webhookUrl ) );
		w.appendChild( field( 'Signing secret', inputs.webhookSecret ) );

		w.appendChild( saveRow() );
		return w;
	}

	function futureCard() {
		var w = el( 'div', { class: 'yzrw-card' } );
		w.appendChild( el( 'h2', { text: 'Future channels' } ) );
		w.appendChild( el( 'p', { class: 'description', text: 'Push, WhatsApp and SMS are wired and ready. Each stays inactive until a provider add-on is connected — enabling one without a provider has no effect.' } ) );
		inputs.future = {};
		var future = data.future || {};
		[ [ 'push', 'Push notifications' ], [ 'whatsapp', 'WhatsApp' ], [ 'sms', 'SMS' ] ].forEach( function ( row ) {
			var cur = future[ row[0] ] || { enabled: false, configured: false };
			var box = checkbox( cur.enabled );
			inputs.future[ row[0] ] = box;
			var status = el( 'span', { class: 'yzrw-pill ' + ( cur.configured ? 'fulfilled' : 'pending' ), text: cur.configured ? 'Provider connected' : 'No provider configured' } );
			w.appendChild( el( 'p', { class: 'yzrw-field' }, [ el( 'label', { text: row[1] } ), box, status ] ) );
		} );
		w.appendChild( saveRow() );
		return w;
	}

	function digestCard() {
		var w = el( 'div', { class: 'yzrw-card' } );
		w.appendChild( el( 'h2', { text: 'Digest & broadcasts' } ) );
		w.appendChild( el( 'p', { class: 'description', text: 'When a customer sets a category to “digest”, its non-urgent emails are batched into one message at the hour below.' } ) );
		inputs.digestEnabled = checkbox( data.digest.enabled );
		inputs.digestHour = el( 'input', { type: 'number', class: 'small-text', min: '0', max: '23' } );
		inputs.digestHour.value = String( data.digest.hour );
		inputs.endingDays = el( 'input', { type: 'number', class: 'small-text', min: '0', max: '60' } );
		inputs.endingDays.value = String( data.campaign_ending_days != null ? data.campaign_ending_days : 3 );
		w.appendChild( field( 'Enable digest', inputs.digestEnabled ) );
		w.appendChild( field( 'Send at hour (0–23)', inputs.digestHour ) );
		w.appendChild( field( '“Campaign ending” lead time (days, 0 = off)', inputs.endingDays ) );
		w.appendChild( saveRow() );
		return w;
	}

	function categoriesCard() {
		var w = el( 'div', { class: 'yzrw-card' } );
		w.appendChild( el( 'h2', { text: 'Categories' } ) );
		w.appendChild( el( 'p', { class: 'description', text: 'Master switch per category. Required (transactional) categories are always on. Customers control their own delivery per category.' } ) );
		inputs.categories = {};
		( data.categories || [] ).forEach( function ( cat ) {
			var box = checkbox( cat.enabled, cat.required );
			inputs.categories[ cat.key ] = box;
			var label = cat.label + ( cat.required ? ' (required)' : '' );
			w.appendChild( field( label, box ) );
		} );
		w.appendChild( saveRow() );
		return w;
	}

	function saveRow() {
		var msg = el( 'span', { class: 'yzrw-msg' } );
		var save = el( 'button', { class: 'button button-primary', text: 'Save settings' } );
		save.addEventListener( 'click', function () { saveAll( save, msg ); } );
		var test = el( 'button', { class: 'button', text: 'Send test to me' } );
		test.addEventListener( 'click', function () {
			test.disabled = true;
			api( '/test', 'POST', {} ).then( function () { msg.style.color = 'var(--yz-ok, #1a7f37)'; msg.textContent = 'Test sent.'; test.disabled = false; } )
				.catch( function ( e ) { msg.style.color = 'var(--yz-danger, #b32d2e)'; msg.textContent = e.message; test.disabled = false; } );
		} );
		return el( 'p', { class: 'yzrw-save' }, [ save, test, msg ] );
	}

	function collect() {
		var cats = {};
		Object.keys( inputs.categories || {} ).forEach( function ( k ) { cats[ k ] = inputs.categories[ k ].checked; } );
		var future = {};
		Object.keys( inputs.future || {} ).forEach( function ( k ) { future[ k ] = { enabled: inputs.future[ k ].checked }; } );
		var body = {
			channels: {
				email: inputs.email.checked,
				onsite: inputs.onsite.checked,
				webhook: { enabled: inputs.webhookEnabled.checked, url: inputs.webhookUrl.value }
			},
			future: future,
			digest: { enabled: inputs.digestEnabled.checked, hour: parseInt( inputs.digestHour.value, 10 ) || 0 },
			campaign_ending_days: parseInt( inputs.endingDays.value, 10 ) || 0,
			categories: cats
		};
		if ( inputs.webhookSecret.value ) { body.channels.webhook.secret = inputs.webhookSecret.value; }
		return body;
	}

	function saveAll( btn, msg ) {
		btn.disabled = true; msg.style.color = 'var(--yz-muted, inherit)'; msg.textContent = 'Saving…';
		api( '/settings', 'POST', collect() ).then( function ( d ) {
			data = d; msg.style.color = 'var(--yz-ok, #1a7f37)'; msg.textContent = 'Saved.'; btn.disabled = false;
			// Refresh secret placeholder, keep field cleared.
			if ( inputs.webhookSecret ) { inputs.webhookSecret.value = ''; inputs.webhookSecret.placeholder = d.channels.webhook.secret_last4 ? ( '•••• ' + d.channels.webhook.secret_last4 ) : 'signing secret'; }
		} ).catch( function ( e ) { msg.style.color = 'var(--yz-danger, #b32d2e)'; msg.textContent = e.message; btn.disabled = false; } );
	}

	/* ---- outbox ---- */

	var outboxBody = null, statusSel = null;

	function outboxCard() {
		var w = el( 'div', { class: 'yzrw-card' } );
		var head = el( 'div', { class: 'yzrw-card-head', style: 'display:flex;justify-content:space-between;align-items:center;gap:1rem;' } );
		head.appendChild( el( 'h2', { text: 'Recent notifications' } ) );
		statusSel = el( 'select' );
		[ [ '', 'All statuses' ], [ 'sent', 'Sent' ], [ 'failed', 'Failed' ], [ 'queued', 'Queued (digest)' ] ].forEach( function ( o ) {
			var opt = el( 'option', { value: o[0], text: o[1] } ); statusSel.appendChild( opt );
		} );
		statusSel.addEventListener( 'change', loadOutbox );
		var refresh = el( 'button', { class: 'button', text: 'Refresh' } );
		refresh.addEventListener( 'click', loadOutbox );
		head.appendChild( el( 'span', {}, [ statusSel, refresh ] ) );
		w.appendChild( head );

		var table = el( 'table', { class: 'widefat striped yzrw-table' } );
		table.appendChild( el( 'thead', {}, [ el( 'tr', {}, [ 'User', 'Channel', 'Event', 'Category', 'Priority', 'Status', 'Date' ].map( function ( h ) { return el( 'th', { text: h } ); } ) ) ] ) );
		outboxBody = el( 'tbody' );
		table.appendChild( outboxBody );
		w.appendChild( table );
		return w;
	}

	function loadOutbox() {
		if ( ! outboxBody ) { return; }
		outboxBody.innerHTML = '';
		var status = statusSel ? statusSel.value : '';
		api( '/outbox?status=' + encodeURIComponent( status ) ).then( function ( d ) {
			var items = ( d && d.items ) || [];
			if ( ! items.length ) { outboxBody.appendChild( el( 'tr', {}, [ el( 'td', { colspan: '7', text: 'Nothing yet.' } ) ] ) ); return; }
			items.forEach( function ( r ) {
				outboxBody.appendChild( el( 'tr', {}, [
					el( 'td', { text: r.user } ),
					el( 'td', { text: r.channel } ),
					el( 'td', { text: r.template } ),
					el( 'td', { text: r.category } ),
					el( 'td', { text: r.priority } ),
					el( 'td', {}, [ el( 'span', { class: 'yzrw-pill ' + r.status, text: r.status } ) ] ),
					el( 'td', { text: r.created_at } )
				] ) );
			} );
		} ).catch( function ( e ) { outboxBody.appendChild( el( 'tr', {}, [ el( 'td', { colspan: '7', text: e.message } ) ] ) ); } );
	}

	load();
} )();
