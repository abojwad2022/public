/**
 * Yazan Core — owner new-order alert (wp-admin).
 *
 * Rides the WordPress Heartbeat API: each tick we send the id of the newest order
 * we've already seen; the server replies when something newer exists. On a new
 * order we play a short WebAudio chime and raise a desktop notification. No cron,
 * no polling of our own — works while the dashboard tab is open.
 */
( function ( $ ) {
	'use strict';

	if ( typeof window.yazanOrderAlert === 'undefined' || typeof wp === 'undefined' || ! wp.heartbeat ) {
		return;
	}

	var cfg      = window.yazanOrderAlert;
	var lastSeen = parseInt( cfg.latest, 10 ) || 0;

	// Ask for desktop-notification permission once (silently ignored if unsupported).
	if ( 'Notification' in window && Notification.permission === 'default' ) {
		try {
			Notification.requestPermission();
		} catch ( e ) {}
	}

	// Attach our last-seen id to every outgoing heartbeat.
	$( document ).on( 'heartbeat-send', function ( event, data ) {
		data.yazan_last_seen = lastSeen;
	} );

	// React when the server reports newer orders.
	$( document ).on( 'heartbeat-tick', function ( event, data ) {
		if ( ! data.yazan_new_orders ) {
			return;
		}
		var info = data.yazan_new_orders;
		var next = parseInt( info.latest, 10 );
		if ( next ) {
			lastSeen = next;
		}
		var count = parseInt( info.count, 10 ) || 1;
		beep();
		toast( count );          // In-dashboard banner — works on any origin.
		notify( count );         // Desktop notification — only on HTTPS/secure context.
	} );

	function message( count ) {
		if ( count > 1 ) {
			return cfg.i18n && cfg.i18n.many ? cfg.i18n.many.replace( '%d', count ) : count + ' new orders';
		}
		return ( cfg.i18n && cfg.i18n.one ) || 'A new order has arrived.';
	}

	var toastEl = null;
	var pending = 0;               // Unseen new orders accumulated since last close.
	var MIN_KEY = 'yzOrderToastMin';

	function buildToast() {
		toastEl = document.createElement( 'div' );
		toastEl.className = 'yz-order-toast';
		toastEl.setAttribute( 'role', 'status' );
		toastEl.innerHTML =
			'<span class="yz-order-toast__icon">' +
				'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
				'<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>' +
				'<span class="yz-order-toast__badge"></span>' +
			'</span>' +
			'<span class="yz-order-toast__body">' +
				'<span class="yz-order-toast__title"></span>' +
				'<a class="yz-order-toast__link" href="' + ( cfg.adminUrl || '#' ) + '"></a>' +
			'</span>' +
			'<button type="button" class="yz-order-toast__min" aria-label="Minimize" title="Minimize">&#8211;</button>' +
			'<button type="button" class="yz-order-toast__close" aria-label="Close" title="Close">&times;</button>';
		document.body.appendChild( toastEl );

		toastEl.querySelector( '.yz-order-toast__min' ).addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			minimize( true );
		} );
		toastEl.querySelector( '.yz-order-toast__close' ).addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			pending = 0;
			toastEl.classList.remove( 'is-visible' );
		} );
		// Clicking the collapsed circle re-opens the full toast.
		toastEl.querySelector( '.yz-order-toast__icon' ).addEventListener( 'click', function () {
			if ( toastEl.classList.contains( 'is-min' ) ) {
				minimize( false );
			}
		} );
	}

	function minimize( on ) {
		toastEl.classList.toggle( 'is-min', !! on );
		try {
			window.localStorage.setItem( MIN_KEY, on ? '1' : '0' );
		} catch ( e ) {}
	}

	function toast( count ) {
		if ( ! toastEl ) {
			buildToast();
			try {
				if ( window.localStorage.getItem( MIN_KEY ) === '1' ) {
					toastEl.classList.add( 'is-min' );
				}
			} catch ( e ) {}
		}

		pending += count;

		toastEl.querySelector( '.yz-order-toast__title' ).textContent = message( pending );
		toastEl.querySelector( '.yz-order-toast__link' ).textContent = ( cfg.i18n && cfg.i18n.view ) || 'View orders';
		toastEl.querySelector( '.yz-order-toast__badge' ).textContent = pending > 99 ? '99+' : String( pending );

		// Re-show and pulse (handles more orders arriving while the toast is up/minimized).
		toastEl.classList.add( 'is-visible' );
		toastEl.classList.remove( 'is-pulse' );
		void toastEl.offsetWidth; // Force reflow so the animation restarts.
		toastEl.classList.add( 'is-pulse' );
	}

	function notify( count ) {
		var title = ( cfg.i18n && cfg.i18n.title ) || 'New order';
		var body  = message( count );

		if ( 'Notification' in window && Notification.permission === 'granted' ) {
			try {
				var n = new Notification( title, { body: body, tag: 'yazan-order', renotify: true } );
				n.onclick = function () {
					window.focus();
					if ( cfg.adminUrl ) {
						window.location.href = cfg.adminUrl;
					}
					n.close();
				};
			} catch ( e ) {}
		}
	}

	function beep() {
		try {
			var Ctx = window.AudioContext || window.webkitAudioContext;
			if ( ! Ctx ) {
				return;
			}
			var ctx = new Ctx();
			var osc = ctx.createOscillator();
			var gain = ctx.createGain();
			osc.connect( gain );
			gain.connect( ctx.destination );
			osc.type = 'sine';
			osc.frequency.value = 880;
			gain.gain.setValueAtTime( 0.0001, ctx.currentTime );
			gain.gain.exponentialRampToValueAtTime( 0.25, ctx.currentTime + 0.02 );
			gain.gain.exponentialRampToValueAtTime( 0.0001, ctx.currentTime + 0.5 );
			osc.start();
			osc.stop( ctx.currentTime + 0.5 );
			osc.onended = function () {
				try {
					ctx.close();
				} catch ( e ) {}
			};
		} catch ( e ) {}
	}
} )( jQuery );
