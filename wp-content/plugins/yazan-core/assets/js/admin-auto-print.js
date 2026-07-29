/**
 * Yazan Core — auto-print pickup (wp-admin).
 *
 * Over the WP Heartbeat API this receives "print this order" jobs pushed by the
 * server when an order becomes Completed, prints each invoice from a hidden
 * iframe, and acknowledges so the server marks it done. With Chrome launched
 * using `--kiosk-printing`, the print is silent (default printer, no dialog).
 *
 * Duplicate protection: a localStorage record of printed order ids guards against
 * reprinting across page reloads while an acknowledgement is still in flight.
 */
( function ( $ ) {
	'use strict';

	if ( typeof wp === 'undefined' || ! wp.heartbeat ) {
		return;
	}

	var STORE_KEY = 'yzAutoPrinted';
	var toAck = [];

	function loadPrinted() {
		try {
			return JSON.parse( window.localStorage.getItem( STORE_KEY ) || '{}' );
		} catch ( e ) {
			return {};
		}
	}

	function savePrinted( map ) {
		try {
			window.localStorage.setItem( STORE_KEY, JSON.stringify( map ) );
		} catch ( e ) {}
	}

	function isPrinted( id ) {
		return !! loadPrinted()[ id ];
	}

	function markPrinted( id ) {
		var map = loadPrinted();
		map[ id ] = Date.now();
		// Prune records older than two days so the store can't grow forever.
		var cutoff = Date.now() - 2 * 24 * 60 * 60 * 1000;
		Object.keys( map ).forEach( function ( key ) {
			if ( map[ key ] < cutoff ) {
				delete map[ key ];
			}
		} );
		savePrinted( map );
	}

	function queueAck( id ) {
		if ( toAck.indexOf( id ) < 0 ) {
			toAck.push( id );
		}
	}

	// Attach acknowledgements to each outgoing heartbeat, then clear them (they are
	// re-queued from the job list if the server hasn't dequeued them yet).
	$( document ).on( 'heartbeat-send', function ( event, data ) {
		if ( toAck.length ) {
			data.yazan_printed = toAck.slice();
			toAck = [];
		}
	} );

	$( document ).on( 'heartbeat-tick', function ( event, data ) {
		if ( ! data.yazan_autoprint || ! data.yazan_autoprint.length ) {
			return;
		}
		data.yazan_autoprint.forEach( function ( job ) {
			var id = parseInt( job.id, 10 );
			if ( ! id ) {
				return;
			}
			if ( isPrinted( id ) ) {
				queueAck( id ); // Already handled — just confirm so the server dequeues it.
				return;
			}
			printSilent( job.url );
			markPrinted( id );
			queueAck( id );
		} );
	} );

	// Print an invoice URL from a hidden, off-screen iframe. Under --kiosk-printing
	// this is silent; otherwise the browser's normal dialog appears.
	function printSilent( url ) {
		var frame = document.createElement( 'iframe' );
		frame.setAttribute( 'aria-hidden', 'true' );
		frame.style.position = 'fixed';
		frame.style.top = '0';
		frame.style.left = '-10000px';
		frame.style.width = '820px';
		frame.style.height = '1123px';
		frame.style.border = '0';

		frame.onload = function () {
			var win = frame.contentWindow;
			window.setTimeout( function () {
				try {
					win.focus();
					win.print();
				} catch ( e ) {}
				// Clean the frame up well after the print has been dispatched.
				window.setTimeout( function () {
					if ( frame.parentNode ) {
						frame.parentNode.removeChild( frame );
					}
				}, 15000 );
			}, 300 );
		};

		frame.src = url;
		document.body.appendChild( frame );
	}
} )( jQuery );
