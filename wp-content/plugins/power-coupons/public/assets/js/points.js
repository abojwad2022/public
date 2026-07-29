/**
 * Power Coupons — Points credit redemption frontend JS.
 *
 * Handles credit input widget AJAX interactions on cart, checkout, and My Account.
 *
 * @package
 */

/* global powerCouponsPointsData */
( function ( $ ) {
	'use strict';

	if ( typeof powerCouponsPointsData === 'undefined' ) {
		return;
	}

	const PCPoints = {
		/**
		 * Initialize event handlers.
		 */
		init() {
			$( document.body ).on(
				'click',
				'.power-coupons-apply-credit-btn',
				this.onApplyCredit
			);
			$( document.body ).on(
				'click',
				'.power-coupons-remove-credit-btn',
				this.onRemoveCredit
			);
			$( document.body ).on(
				'input',
				'.power-coupons-points-credit-input',
				this.onInputChange
			);
		},

		/**
		 * Handle apply credit button click.
		 *
		 * @param {Event} e Click event.
		 */
		onApplyCredit( e ) {
			e.preventDefault();

			const $btn = $( this );

			if ( $btn.hasClass( 'pc-redeeming' ) ) {
				return;
			}

			// In full mode, points come from data attribute; in max_limit mode, from input.
			const dataPoints = $btn.data( 'points' );
			let points;

			if ( dataPoints ) {
				points = parseInt( dataPoints, 10 );
			} else {
				const $input = $( '#power-coupons-points-credit-input' );
				points = parseInt( $input.val(), 10 );

				if ( ! points || points <= 0 ) {
					PCPoints.showNotice(
						powerCouponsPointsData.i18n.enter_points,
						'error'
					);
					$input.trigger( 'focus' );
					return;
				}

				const balance =
					parseInt( powerCouponsPointsData.balance, 10 ) || 0;
				const minPoints =
					parseInt(
						powerCouponsPointsData.min_points_to_redeem,
						10
					) || 0;
				const maxPerOrder =
					parseInt(
						powerCouponsPointsData.max_credits_per_order,
						10
					) || 0;

				if ( minPoints > 0 && points < minPoints ) {
					PCPoints.showNotice(
						powerCouponsPointsData.i18n.below_minimum,
						'error'
					);
					$input.trigger( 'focus' );
					return;
				}

				if ( maxPerOrder > 0 && points > maxPerOrder ) {
					PCPoints.showNotice(
						powerCouponsPointsData.i18n.exceeds_max,
						'error'
					);
					$input.trigger( 'focus' );
					return;
				}

				if ( balance > 0 && points > balance ) {
					PCPoints.showNotice(
						powerCouponsPointsData.i18n.exceeds_balance,
						'error'
					);
					$input.trigger( 'focus' );
					return;
				}
			}

			const originalText = $btn.text();
			$btn.addClass( 'pc-redeeming' ).prop( 'disabled', true );
			$btn.html(
				'<span class="power-coupons-spinner"></span>' +
					powerCouponsPointsData.i18n.applying
			);
			PCPoints.clearNotice();

			$.ajax( {
				url: powerCouponsPointsData.ajax_url,
				type: 'POST',
				data: {
					action: 'power_coupons_apply_points_credit',
					security: powerCouponsPointsData.nonce,
					points,
				},
				success( response ) {
					if ( response.success ) {
						PCPoints.showNotice(
							response.data.message ||
								powerCouponsPointsData.i18n.apply_success,
							'success'
						);

						// Trigger WC cart update to refresh totals and widget.
						$( document.body ).trigger( 'wc_update_cart' );
						$( document.body ).trigger( 'update_checkout' );
					} else {
						PCPoints.showNotice(
							response.data.message ||
								powerCouponsPointsData.i18n.apply_error,
							'error'
						);
					}
				},
				error() {
					PCPoints.showNotice(
						powerCouponsPointsData.i18n.apply_error,
						'error'
					);
				},
				complete() {
					$btn.removeClass( 'pc-redeeming' )
						.prop( 'disabled', false )
						.text( originalText );
				},
			} );
		},

		/**
		 * Handle remove credit button click.
		 *
		 * @param {Event} e Click event.
		 */
		onRemoveCredit( e ) {
			e.preventDefault();

			const $btn = $( this );

			if ( $btn.hasClass( 'pc-redeeming' ) ) {
				return;
			}

			$btn.addClass( 'pc-redeeming' ).prop( 'disabled', true );

			$.ajax( {
				url: powerCouponsPointsData.ajax_url,
				type: 'POST',
				data: {
					action: 'power_coupons_remove_points_credit',
					security: powerCouponsPointsData.nonce,
				},
				success( response ) {
					if ( response.success ) {
						PCPoints.showNotice(
							response.data.message ||
								powerCouponsPointsData.i18n.remove_success,
							'success'
						);

						$( document.body ).trigger( 'wc_update_cart' );
						$( document.body ).trigger( 'update_checkout' );
					}
				},
				complete() {
					$btn.removeClass( 'pc-redeeming' ).prop(
						'disabled',
						false
					);
				},
			} );
		},

		/**
		 * Handle input change for live preview.
		 */
		onInputChange() {
			const $input = $( this );
			const $preview = $input
				.closest( '.power-coupons-points-credit-form' )
				.find( '.power-coupons-points-credit-preview' );

			const points = parseInt( $input.val(), 10 );

			if ( ! points || points <= 0 ) {
				$preview.text( '' );
				return;
			}

			const ratio = powerCouponsPointsData.redemption_ratio || 100;
			const discount = points / ratio;
			const symbol = powerCouponsPointsData.currency_symbol || '$';
			const label = powerCouponsPointsData.label_plural || 'Points';

			$preview.text(
				points.toLocaleString() +
					' ' +
					label +
					' = ' +
					symbol +
					discount.toFixed( 2 ) +
					' discount'
			);
		},

		/**
		 * Show a notice message.
		 *
		 * @param {string} message Notice text.
		 * @param {string} type    Notice type: 'success' or 'error'.
		 */
		showNotice( message, type ) {
			const $notice = $( '.power-coupons-points-redeem-notice' );
			if ( $notice.length ) {
				$notice
					.removeClass(
						'power-coupons-points-notice-success power-coupons-points-notice-error'
					)
					.addClass( 'power-coupons-points-notice-' + type )
					.html( '<p>' + message + '</p>' )
					.slideDown( 200 );

				// Scroll notice into view on My Account page.
				if (
					$( '.power-coupons-my-account-points' ).length &&
					$notice.length
				) {
					$( 'html, body' ).animate(
						{ scrollTop: $notice.offset().top - 50 },
						300
					);
				}
			}
		},

		/**
		 * Clear notice messages.
		 */
		clearNotice() {
			$( '.power-coupons-points-redeem-notice' ).slideUp( 150 );
		},
	};

	$( function () {
		PCPoints.init();
	} );
} )( jQuery ); // eslint-disable-line no-redeclare
