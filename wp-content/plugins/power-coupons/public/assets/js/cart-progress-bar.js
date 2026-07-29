/**
 * Power Coupons - Cart Progress Bar JavaScript
 * Handles real-time progress bar updates on cart/checkout.
 *
 * @param {Object} $ jQuery instance.
 * @package
 * @since 1.0.0
 */

/* global powerCouponsProgressData */
( function ( $ ) {
	'use strict';

	const PowerCouponsProgressBar = {
		/**
		 * Initialize
		 */
		init() {
			this.bindEvents();
		},

		/**
		 * Bind events
		 */
		bindEvents() {
			// Classic cart/checkout events.
			$( document.body ).on(
				'updated_cart_totals',
				this.refreshProgress.bind( this )
			);
			$( document.body ).on(
				'updated_checkout',
				this.refreshProgress.bind( this )
			);
			$( document.body ).on(
				'added_to_cart',
				this.refreshProgress.bind( this )
			);
			$( document.body ).on(
				'removed_from_cart',
				this.refreshProgress.bind( this )
			);

			// WooCommerce Blocks support.
			this.bindBlockEvents();
		},

		/**
		 * Bind WooCommerce Blocks events.
		 * Uses registerCheckoutFilters to detect cart total changes in block cart/checkout.
		 */
		bindBlockEvents() {
			if (
				! window.wc ||
				! window.wc.blocksCheckout ||
				typeof window.wc.blocksCheckout.registerCheckoutFilters !==
					'function'
			) {
				return;
			}

			const self = this;

			window.wc.blocksCheckout.registerCheckoutFilters(
				'powerCouponsProgressBar',
				{
					totalValue( defaultValue ) {
						self.refreshProgress();
						return defaultValue;
					},
					showApplyCouponNotice( defaultValue ) {
						self.refreshProgress();
						return defaultValue;
					},
					showRemoveCouponNotice( defaultValue ) {
						self.refreshProgress();
						return defaultValue;
					},
				}
			);
		},

		/**
		 * Refresh progress data via AJAX.
		 * Debounced to avoid rapid-fire requests during block updates.
		 */
		refreshProgress() {
			if ( ! window.powerCouponsProgressData ) {
				return;
			}

			// Debounce: cancel any pending request timer.
			if ( this._refreshTimer ) {
				clearTimeout( this._refreshTimer );
			}

			this._refreshTimer = setTimeout( () => {
				$.ajax( {
					url: powerCouponsProgressData.ajaxUrl,
					type: 'POST',
					data: {
						action: 'power_coupons_get_progress_data',
						nonce: powerCouponsProgressData.nonce,
					},
					success: ( response ) => {
						if (
							response.success &&
							response.data &&
							response.data.sources
						) {
							this.updateDOM( response.data.sources );
						}
					},
				} );
			}, 300 );
		},

		/**
		 * Update DOM elements with fresh progress data.
		 *
		 * @param {Array} sources Array of source progress objects.
		 */
		updateDOM( sources ) {
			sources.forEach( ( source ) => {
				const $source = $( '[data-source-key="' + source.key + '"]' );
				if ( ! $source.length ) {
					return;
				}

				// Update fill width.
				$source
					.find( '.power-coupons-progress-fill' )
					.css( 'width', source.percentage + '%' );

				// Update message.
				$source
					.find( '.power-coupons-progress-text' )
					.html( source.message );

				// Update aria.
				$source
					.find( '.power-coupons-progress-track' )
					.attr( 'aria-valuenow', Math.round( source.percentage ) );

				// Toggle complete class.
				if ( source.is_complete ) {
					$source.addClass( 'complete' );
					$source
						.find( '.power-coupons-progress-fill' )
						.addClass( 'complete' );
					$source
						.find( '.power-coupons-progress-message' )
						.addClass( 'success' );
				} else {
					$source.removeClass( 'complete' );
					$source
						.find( '.power-coupons-progress-fill' )
						.removeClass( 'complete' );
					$source
						.find( '.power-coupons-progress-message' )
						.removeClass( 'success' );
				}
			} );
		},
	};

	// Initialize on document ready.
	$( document ).ready( () => {
		PowerCouponsProgressBar.init();
	} );
} )( jQuery );
