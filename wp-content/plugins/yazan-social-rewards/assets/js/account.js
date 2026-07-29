/**
 * Yazan Rewards — account hub progressive enhancement.
 *
 * The hub renders fully server-side; this only wires the redeem buttons to the
 * REST API so a redemption happens without a full page reload. All requests are
 * same-origin cookie auth + the wp_rest nonce.
 */
( function () {
	'use strict';

	var cfg = window.YazanRewards || {};
	if ( ! cfg.restUrl ) {
		return;
	}

	var resultBox = document.querySelector( '[data-yzrw-result]' );
	var balanceEl = document.querySelector( '[data-yzrw-balance]' );

	function showResult( message, isError ) {
		if ( ! resultBox ) {
			return;
		}
		resultBox.hidden = false;
		resultBox.className = 'yzrw-result' + ( isError ? ' is-error' : '' );
		resultBox.innerHTML = message;
	}

	function redeem( button ) {
		var rewardId = parseInt( button.getAttribute( 'data-reward-id' ), 10 );
		if ( ! rewardId ) {
			return;
		}
		if ( cfg.i18n && cfg.i18n.confirm && ! window.confirm( cfg.i18n.confirm ) ) {
			return;
		}

		button.disabled = true;
		var original = button.textContent;
		button.textContent = ( cfg.i18n && cfg.i18n.redeeming ) || 'Redeeming…';

		fetch( cfg.restUrl + '/rewards/redeem', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce
			},
			body: JSON.stringify( { reward_id: rewardId } )
		} )
			.then( function ( res ) {
				return res.json().then( function ( body ) {
					return { ok: res.ok, body: body };
				} );
			} )
			.then( function ( out ) {
				if ( ! out.ok ) {
					var msg = ( out.body && out.body.message ) || ( cfg.i18n && cfg.i18n.error ) || 'Error';
					showResult( msg, true );
					button.disabled = false;
					button.textContent = original;
					return;
				}

				var data = out.body;
				if ( balanceEl && typeof data.balance !== 'undefined' ) {
					balanceEl.textContent = new Intl.NumberFormat().format( data.balance );
				}

				var message = ( cfg.i18n && cfg.i18n.redeemed ) || 'Redeemed!';
				if ( data.result_type === 'coupon' && data.coupon_code ) {
					message += ' <code>' + data.coupon_code + '</code>';
				} else if ( data.result_type === 'wallet' && data.credit_amount ) {
					message += ' +' + data.credit_amount;
				}
				showResult( message, false );

				// Refresh affordability of every button against the new balance.
				document.querySelectorAll( '.yzrw-redeem' ).forEach( function ( btn ) {
					var cost = parseInt( btn.getAttribute( 'data-cost' ), 10 ) || 0;
					btn.disabled = ( data.balance < cost );
				} );
				button.textContent = original;
			} )
			.catch( function () {
				showResult( ( cfg.i18n && cfg.i18n.error ) || 'Error', true );
				button.disabled = false;
				button.textContent = original;
			} );
	}

	function submitTask( button ) {
		var li = button.closest( '.yzrw-campaign' );
		var taskId = parseInt( button.getAttribute( 'data-task' ), 10 );
		var campaignId = li ? parseInt( li.getAttribute( 'data-campaign' ), 10 ) : 0;
		var input = button.parentNode.querySelector( '.yzrw-task-url' );
		var url = input ? input.value : '';
		if ( ! campaignId || ! taskId || ! url ) {
			return;
		}
		button.disabled = true;
		var original = button.textContent;
		button.textContent = ( cfg.i18n && cfg.i18n.redeeming ) || 'Submitting…';
		fetch( cfg.restUrl + '/campaigns/' + campaignId + '/tasks/' + taskId + '/submit', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify( { content_url: url } )
		} )
			.then( function ( res ) { return res.json().then( function ( body ) { return { ok: res.ok, body: body }; } ); } )
			.then( function ( out ) {
				if ( ! out.ok ) {
					showResult( ( out.body && out.body.message ) || ( cfg.i18n && cfg.i18n.error ) || 'Error', true );
					button.disabled = false; button.textContent = original;
					return;
				}
				var wrap = button.parentNode;
				wrap.innerHTML = '';
				var span = document.createElement( 'span' );
				span.className = 'yzrw-task__pending';
				span.textContent = ( cfg.i18n && cfg.i18n.inReview ) || 'In review';
				wrap.appendChild( span );
			} )
			.catch( function () { showResult( ( cfg.i18n && cfg.i18n.error ) || 'Error', true ); button.disabled = false; button.textContent = original; } );
	}

	document.addEventListener( 'click', function ( e ) {
		if ( ! e.target.closest ) { return; }
		var redeemBtn = e.target.closest( '.yzrw-redeem' );
		if ( redeemBtn && ! redeemBtn.disabled ) { redeem( redeemBtn ); return; }
		var taskBtn = e.target.closest( '.yzrw-task-go' );
		if ( taskBtn && ! taskBtn.disabled ) { submitTask( taskBtn ); }
	} );
} )();
