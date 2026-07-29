/**
 * Power Coupons Public JavaScript
 *
 * Handles coupon apply/remove actions and refreshes on the frontend.
 * Uses jQuery for DOM manipulation and AJAX requests.
 *
 * @param {Object} $ jQuery object
 * @package
 * @since 1.0.0
 */

( function ( $ ) {
	'use strict';

	/**
	 * Power Coupons Main Object
	 *
	 * Manages all coupon-related functionality on the frontend.
	 */
	const PowerCoupons = {
		/**
		 * Active AJAX requests tracker
		 * Prevents duplicate requests from multiple clicks
		 *
		 * @type {Object}
		 */
		activeRequests: {
			apply: null,
			remove: null,
			refresh: null,
		},

		/**
		 * Initialize the Power Coupons functionality
		 *
		 * Sets up event handlers and WooCommerce integrations.
		 *
		 * @since 1.0.0
		 */
		init() {
			// Validate required data is available
			if ( ! this.validateDependencies() ) {
				console.error( 'Power Coupons: Required data not available' );
				return;
			}

			// Setup WooCommerce event handlers
			this.handleWooCommerceEvents();

			// Bind click events for apply/remove buttons
			this.bindEvents();
		},

		maybeReloadPageOnSuccess() {
			if ( powerCouponsData.reloadPageAfterCouponApplied ) {
				window.location.href = window.location.href;
			}
		},

		/**
		 * Validate that all required dependencies are available
		 *
		 * Checks for powerCouponsData object and required AJAX URLs.
		 *
		 * @since 1.0.0
		 * @return {boolean} True if all dependencies are available
		 */
		validateDependencies() {
			if ( typeof powerCouponsData === 'undefined' ) {
				return false;
			}

			if ( ! powerCouponsData.ajaxUrl || ! powerCouponsData.nonce ) {
				return false;
			}

			return true;
		},

		/**
		 * AJAX function to refresh coupons HTML
		 *
		 * Fetches updated coupon list from server and replaces existing list.
		 * Uses modern DOM APIs for better performance.
		 * @param {Event}  event      Click event.
		 * @param {string} couponCode Removed coupon code.
		 */
		ajaxRefreshCouponsHTML( event, couponCode ) {
			// Cancel any pending refresh request
			if ( PowerCoupons.activeRequests.refresh ) {
				PowerCoupons.activeRequests.refresh.abort();
			}

			const dataContext = $( '.power-coupons-list' ).attr(
				'data-context'
			);

			// Make AJAX request to get fresh coupon HTML
			PowerCoupons.activeRequests.refresh = $.ajax( {
				type: 'GET',
				url: powerCouponsData.ajaxUrl.getCouponsHtml,
				data: {
					nonce: powerCouponsData.nonce,
					context: dataContext || 'ajax',
					couponCode,
				},
				success( response ) {
					if ( parseInt( response.coupon_id, 10 ) > 0 ) {
						const couponCards = document.querySelectorAll(
							`.power-coupons-apply-coupon-btn[data-coupon="${ CSS.escape(
								response.coupon_code
							) }"]`
						);

						const template = document.createElement( 'template' );
						template.innerHTML = response.html.trim();

						couponCards.forEach( function ( couponCard ) {
							const newNode =
								template.content.firstElementChild.cloneNode(
									true
								);
							couponCard.parentElement.parentElement.replaceWith(
								newNode
							);
						} );

						return;
					}

					const drawerList = document.querySelector(
						'.power-coupons-drawer-coupons-list'
					);

					if ( drawerList ) {
						drawerList.innerHTML = response.html;

						// Trigger custom event for extensions.
						jQuery( document ).trigger( 'powerCoupons:refreshed' );
					}
				},
				error( jqXHR, textStatus ) {
					// Only reload if request wasn't aborted
					if ( textStatus !== 'abort' ) {
						console.error(
							'Power Coupons: Failed to refresh coupons list'
						);
						// Fallback to page reload as last resort
						location.reload();
					}
				},
				complete() {
					// Clear request reference
					PowerCoupons.activeRequests.refresh = null;
				},
			} );
		},

		/**
		 * Handle WooCommerce-specific events
		 *
		 * Refreshes coupon list when coupons are applied/removed via:
		 * - Classic WooCommerce cart/checkout forms
		 * - WooCommerce Blocks (cart/checkout blocks)
		 *
		 * @since 1.0.0
		 */
		handleWooCommerceEvents() {
			// ============================================
			// Classic WooCommerce Cart/Checkout Events
			// ============================================

			// Coupon applied in classic cart
			$( 'body' ).on(
				'applied_coupon',
				PowerCoupons.ajaxRefreshCouponsHTML
			);

			// Coupon removed in classic cart
			$( 'body' ).on(
				'removed_coupon',
				PowerCoupons.ajaxRefreshCouponsHTML
			);

			// Coupon applied in classic checkout
			$( 'body' ).on(
				'applied_coupon_in_checkout',
				PowerCoupons.ajaxRefreshCouponsHTML
			);

			// Coupon removed in classic checkout
			$( 'body' ).on(
				'removed_coupon_in_checkout',
				PowerCoupons.ajaxRefreshCouponsHTML
			);

			// Coupon removed in classic checkout
			$( 'body' ).on(
				'updated_cart_totals',
				PowerCoupons.ajaxRefreshCouponsHTML
			);

			// ============================================
			// WooCommerce Blocks Integration
			// ============================================

			// Check if WooCommerce Blocks API is available
			if (
				window &&
				window.wc &&
				window.wc.blocksCheckout &&
				typeof window.wc.blocksCheckout.registerCheckoutFilters ===
					'function'
			) {
				// Register filters for blocks checkout
				window.wc.blocksCheckout.registerCheckoutFilters(
					'powerCouponsRefreshCoupons',
					{
						totalValue( defaultValue ) {
							PowerCoupons.ajaxRefreshCouponsHTML();
							return defaultValue;
						},

						/**
						 * Filter for apply coupon notice
						 * Refreshes coupon list when coupon is applied in blocks
						 *
						 * @param {boolean} defaultValue - Whether to show notice
						 * @return {boolean} The default value (pass through)
						 */
						showApplyCouponNotice( defaultValue ) {
							PowerCoupons.ajaxRefreshCouponsHTML();
							return defaultValue;
						},

						/**
						 * Filter for remove coupon notice
						 * Refreshes coupon list when coupon is removed in blocks
						 *
						 * @param {boolean} defaultValue - Whether to show notice
						 * @return {boolean} The default value (pass through)
						 */
						showRemoveCouponNotice( defaultValue ) {
							PowerCoupons.ajaxRefreshCouponsHTML();
							return defaultValue;
						},
					}
				);
			}
		},

		/**
		 * Bind click events to coupon action buttons
		 *
		 * Uses event delegation for better performance and to handle
		 * dynamically added elements (after AJAX refresh).
		 *
		 * @since 1.0.0
		 */
		bindEvents() {
			const self = this;

			// Apply coupon button click
			// Simpler: clicking a coupon card applies it (unless user clicks a button in it)
			$( document ).on(
				'click',
				'.power-coupons-list .power-coupon-card',
				function ( e ) {
					if (
						! $( e.target ).closest(
							'.apply-coupon, .remove-coupon'
						).length
					) {
						$( this )
							.find( '.apply-coupon' )
							.first()
							.trigger( 'click' );
					}
				}
			);

			$( document ).on(
				'click',
				'.power-coupons-apply-coupon-btn',
				function ( e ) {
					self.applyCoupon.call( this, e, self );
				}
			);

			// Remove coupon button click
			$( document ).on( 'click', '.remove-coupon', function ( e ) {
				self.removeCoupon.call( this, e, self );
			} );
		},

		/**
		 * Apply coupon to cart
		 *
		 * Sends AJAX request to apply coupon and updates UI accordingly.
		 *
		 * @since 1.0.0
		 * @param {Event}  e    - Click event object
		 * @param {Object} self - Reference to PowerCoupons object
		 */
		applyCoupon( e, self ) {
			e.preventDefault();

			const $button = $( this );
			const couponCode = $button.data( 'coupon' );

			// Validate coupon code
			if ( ! couponCode ) {
				console.error( 'Power Coupons: No coupon code found' );
				return;
			}

			// Prevent duplicate requests
			if ( self.activeRequests.apply ) {
				return;
			}

			const originalText = $button.html();

			// Update button state - show loading
			$button
				.prop( 'disabled', true )
				.attr( 'aria-disabled', 'true' )
				.attr( 'aria-busy', 'true' )
				.addClass( 'pc-loading' )
				.find( '.power-coupons-coupon-status' )
				.text( powerCouponsData.text?.applyingText || 'Applying...' );

			// Send AJAX request
			self.activeRequests.apply = $.ajax( {
				type: 'POST',
				url: powerCouponsData.ajaxUrl.applyCoupon,
				data: {
					coupon_code: couponCode,
					nonce: powerCouponsData.nonce,
					billing_email: $( 'input[name="billing_email"]' ).val(),
				},
				success( response ) {
					if ( response.success ) {
						// Trigger WooCommerce events for cart/checkout updates
						self.triggerWooCommerceUpdates();

						PowerCoupons.maybeReloadPageOnSuccess();

						// Refresh coupon list after a short delay
						setTimeout( function () {
							PowerCoupons.ajaxRefreshCouponsHTML();
						}, 500 );
					} else {
						// Failed - show error message
						const errorMessage =
							response.data?.message ||
							powerCouponsData.text?.applyErrorText ||
							'Failed to apply coupon.';

						self.showNotice( errorMessage, 'error' );

						// Refresh coupon list after a short delay
						setTimeout( function () {
							PowerCoupons.ajaxRefreshCouponsHTML();
						}, 500 );
					}
				},
				error( jqXHR, textStatus ) {
					// Network error or server error
					if ( textStatus !== 'abort' ) {
						const errorMessage =
							powerCouponsData.text?.applyErrorText ||
							'Failed to apply coupon. Please try again.';

						self.showNotice( errorMessage, 'error' );

						// Restore button state
						$button
							.prop( 'disabled', false )
							.attr( 'aria-disabled', 'false' )
							.attr( 'aria-busy', 'false' )
							.removeClass( 'pc-loading' )
							.text( originalText );
					}
				},
				complete() {
					// Clear request reference
					self.activeRequests.apply = null;
				},
			} );
		},

		/**
		 * Remove coupon from cart
		 *
		 * Sends AJAX request to remove coupon and updates UI accordingly.
		 *
		 * @since 1.0.0
		 * @param {Event}  e    - Click event object
		 * @param {Object} self - Reference to PowerCoupons object
		 */
		removeCoupon( e, self ) {
			e.preventDefault();

			const $button = $( this );
			const couponCode = $button.data( 'coupon' );

			// Validate coupon code
			if ( ! couponCode ) {
				console.error( 'Power Coupons: No coupon code found' );
				return;
			}

			// Prevent duplicate requests
			if ( self.activeRequests.remove ) {
				return;
			}

			const originalText = $button.text();

			// Update button state - show loading
			$button
				.prop( 'disabled', true )
				.attr( 'aria-disabled', 'true' )
				.attr( 'aria-busy', 'true' )
				.addClass( 'pc-loading' )
				.text( powerCouponsData.text?.removingText || 'Removing...' );

			// Send AJAX request
			self.activeRequests.remove = $.ajax( {
				type: 'POST',
				url: powerCouponsData.ajaxUrl.removeCoupon,
				data: {
					coupon_code: couponCode,
					nonce: powerCouponsData.nonce,
				},
				success( response ) {
					if ( response.success ) {
						// Success - reload page to update cart totals
						// WooCommerce will show its own success message
						PowerCoupons.maybeReloadPageOnSuccess();
					} else {
						// Failed - show error message
						const errorMessage =
							response.data?.message ||
							powerCouponsData.text?.removeErrorText ||
							'Failed to remove coupon.';

						self.showNotice( errorMessage, 'error' );

						// Restore button state
						$button
							.prop( 'disabled', false )
							.attr( 'aria-disabled', 'false' )
							.attr( 'aria-busy', 'false' )
							.removeClass( 'pc-loading' )
							.text( originalText );
					}
				},
				error( jqXHR, textStatus ) {
					// Network error or server error
					if ( textStatus !== 'abort' ) {
						const errorMessage =
							powerCouponsData.text?.removeErrorText ||
							'Failed to remove coupon. Please try again.';

						self.showNotice( errorMessage, 'error' );

						// Restore button state
						$button
							.prop( 'disabled', false )
							.attr( 'aria-disabled', 'false' )
							.attr( 'aria-busy', 'false' )
							.removeClass( 'pc-loading' )
							.text( originalText );
					}
				},
				complete() {
					// Clear request reference
					self.activeRequests.remove = null;
				},
			} );
		},

		/**
		 * Trigger WooCommerce cart/checkout updates
		 *
		 * Triggers appropriate WooCommerce events to refresh cart/checkout
		 * displays after coupon application.
		 *
		 * @since 1.0.0
		 */
		triggerWooCommerceUpdates() {
			const isBlockCart =
				document.querySelector( '.wc-block-cart' ) !== null;
			const isBlockCheckout =
				document.querySelector( '.wc-block-checkout' ) !== null;

			if ( isBlockCart || isBlockCheckout ) {
				// Block pages: reload to get fresh server-rendered notifications.
				window.location.reload();
				return;
			}

			// Classic cart/checkout pages.
			const isCartPage = $( '.woocommerce-cart-form' ).length > 0;
			const isCheckoutPage = $( 'form.checkout' ).length > 0;

			if ( isCartPage ) {
				// Cart page: Trigger cart totals update
				$( document.body ).trigger( 'updated_cart_totals' );
				$( document.body ).trigger( 'wc_fragment_refresh' );

				// Also trigger update_checkout if checkout is on same page
				if ( isCheckoutPage ) {
					$( document.body ).trigger( 'update_checkout' );
				}
			} else if ( isCheckoutPage ) {
				// Checkout page: Trigger checkout update
				$( document.body ).trigger( 'update_checkout' );
			}

			// Always trigger applied_coupon event (WooCommerce standard event)
			$( document.body ).trigger( 'applied_coupon' );
			$( document.body ).trigger( 'applied_coupon_in_checkout' );
		},

		/**
		 * Show notice message to user
		 *
		 * Attempts to use WooCommerce notice system if available,
		 * falls back to browser alert as last resort.
		 *
		 * @since 1.0.0
		 * @param {string} message - Message to display
		 * @param {string} type    - Notice type: 'success', 'error', 'notice'
		 */
		showNotice( message, type ) {
			type = type || 'notice';

			// Use role="status" (polite) for success, role="alert" (assertive) for errors.
			const noticeRole = type === 'error' ? 'alert' : 'status';
			const ariaLive = type === 'error' ? 'assertive' : 'polite';

			// Try to use WooCommerce notices if available
			const noticeContainer = $( '.woocommerce-notices-wrapper' ).first();

			if ( noticeContainer.length ) {
				// Create WooCommerce-style notice
				const noticeClass =
					type === 'error'
						? 'woocommerce-error'
						: 'woocommerce-message';
				const notice = $( '<div>' )
					.addClass( noticeClass )
					.attr( 'role', noticeRole )
					.attr( 'aria-live', ariaLive )
					.attr( 'aria-atomic', 'true' )
					.text( message );

				// Clear existing notices and add new one
				noticeContainer.empty().append( notice );

				// Scroll to notice
				$( 'html, body' ).animate(
					{
						scrollTop: noticeContainer.offset().top - 100,
					},
					500
				);

				// Auto-dismiss success notices after 10 seconds (increased from 5 for accessibility)
				if ( type === 'success' ) {
					setTimeout( function () {
						notice.fadeOut( function () {
							$( this ).remove();
						} );
					}, 10000 );
				}
			} else {
				// Fallback: create a temporary inline notice instead of disruptive alert().
				const fallbackNotice = $( '<div>' )
					.addClass( 'power-coupons-inline-notice' )
					.attr( 'role', noticeRole )
					.attr( 'aria-atomic', 'true' )
					.text( message )
					.css( {
						position: 'fixed',
						top: '20px',
						right: '20px',
						zIndex: 999999,
						padding: '12px 20px',
						borderRadius: '4px',
						maxWidth: '400px',
						background: type === 'error' ? '#fee2e2' : '#f0fdf4',
						color: type === 'error' ? '#991b1b' : '#166534',
						border:
							'1px solid ' +
							( type === 'error' ? '#fecaca' : '#86efac' ),
						boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
						fontSize: '14px',
						lineHeight: '1.5',
					} );

				$( 'body' ).append( fallbackNotice );

				setTimeout( function () {
					fallbackNotice.fadeOut( function () {
						$( this ).remove();
					} );
				}, 5000 );
			}
		},
	};

	/**
	 * Initialize on DOM ready
	 *
	 * Waits for jQuery and DOM to be ready before initializing.
	 */
	$( document ).ready( function () {
		PowerCoupons.init();
	} );
} )( jQuery );
