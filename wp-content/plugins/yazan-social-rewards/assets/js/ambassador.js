/**
 * Yazan Rewards — ambassador dashboard enhancement (copy link + apply).
 */
( function () {
	'use strict';
	var cfg = window.YazanDashboard || {};
	var i18n = cfg.i18n || {};

	function flashCopied( btn ) {
		var original = btn.getAttribute( 'data-label' ) || btn.textContent;
		btn.setAttribute( 'data-label', original );
		btn.textContent = i18n.copied || 'Copied';
		setTimeout( function () { btn.textContent = original; }, 1500 );
	}

	document.addEventListener( 'click', function ( e ) {
		var closest = e.target.closest ? e.target.closest.bind( e.target ) : null;
		if ( ! closest ) {
			return;
		}

		// Copy referral / ambassador link.
		var copyBtn = closest( '[data-yzrw-copy]' );
		if ( copyBtn ) {
			var wrap = copyBtn.closest( '.yzrw-referral__link' );
			var input = wrap ? wrap.querySelector( '[data-yzrw-reflink]' ) : null;
			if ( input ) {
				input.select();
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( input.value ).then(
						function () { flashCopied( copyBtn ); },
						function () { try { document.execCommand( 'copy' ); flashCopied( copyBtn ); } catch ( err ) {} }
					);
				} else {
					try { document.execCommand( 'copy' ); flashCopied( copyBtn ); } catch ( err ) {}
				}
			}
			return;
		}

		// Apply to become an ambassador.
		var applyBtn = closest( '[data-yzrw-apply]' );
		if ( applyBtn && cfg.restUrl ) {
			var originalLabel = applyBtn.textContent;
			var msg = document.querySelector( '[data-yzrw-apply-msg]' );
			applyBtn.disabled = true;
			applyBtn.textContent = i18n.applying || 'Applying…';

			fetch( cfg.restUrl + '/ambassador/apply', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce }
			} )
				.then( function ( r ) { return r.json().then( function ( j ) { return { ok: r.ok, body: j }; } ); } )
				.then( function ( out ) {
					if ( out.ok ) {
						// Application accepted (pending, or auto-approved -> active).
						// Reload so the server re-renders the ambassador panel (status,
						// and the referral link/stats for an auto-approved member).
						if ( msg ) { msg.textContent = i18n.applied || 'Application received'; }
						applyBtn.style.display = 'none';
						setTimeout( function () { window.location.reload(); }, 900 );
					} else {
						if ( msg ) { msg.textContent = ( out.body && out.body.message ) || i18n.error || 'Something went wrong.'; }
						applyBtn.disabled = false;
						applyBtn.textContent = originalLabel;
					}
				} )
				.catch( function () {
					if ( msg ) { msg.textContent = i18n.error || 'Something went wrong.'; }
					applyBtn.disabled = false;
					applyBtn.textContent = originalLabel;
				} );
		}
	} );
} )();
