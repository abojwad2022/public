/**
 * Yazan Core — orders-list "Print" button.
 *
 * Injects a printer icon next to each row's preview (eye) button. Clicking it
 * prints that order's invoice from a hidden iframe (silent under Chrome
 * --kiosk-printing; otherwise the browser's print dialog).
 */
( function ( $ ) {
	'use strict';

	if ( typeof window.yazanListPrint === 'undefined' ) {
		return;
	}
	var cfg = window.yazanListPrint;

	function addButtons() {
		$( 'a.order-preview' ).each( function () {
			var $eye = $( this );
			if ( $eye.next( '.yz-print-preview' ).length ) {
				return; // Already added for this row.
			}
			var id = $eye.attr( 'data-order-id' );
			if ( ! id ) {
				return;
			}
			$( '<a href="#" class="yz-print-preview"></a>' )
				.attr( 'data-order-id', id )
				.attr( 'title', cfg.label )
				.insertAfter( $eye );
		} );
	}

	$( function () {
		addButtons();
		// The list can re-render (sorting/filtering via AJAX in some setups).
		$( document ).on( 'ajaxComplete', addButtons );
	} );

	$( document ).on( 'click', 'a.yz-print-preview', function ( e ) {
		e.preventDefault();
		var id = $( this ).attr( 'data-order-id' );
		if ( ! id ) {
			return;
		}
		var url = cfg.base +
			'?action=' + encodeURIComponent( cfg.action ) +
			'&order_id=' + encodeURIComponent( id ) +
			'&_wpnonce=' + encodeURIComponent( cfg.nonce );
		printInvoice( url );
	} );

	function printInvoice( url ) {
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
				} catch ( err ) {}
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
