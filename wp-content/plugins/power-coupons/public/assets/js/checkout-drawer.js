/**
 * Power Coupons - Checkout Drawer JavaScript
 *
 * @param {Object} $ jQuery object
 * @package
 * @since 1.0.0
 */
( function ( $ ) {
	'use strict';

	const PowerCouponsDrawer = {
		renderedTriggerButton: false,
		lastFocusedElement: null,
		focusableElements: null,
		firstFocusableElement: null,
		lastFocusableElement: null,

		maybeReloadPageOnSuccess() {
			if ( powerCouponsData.reloadPageAfterCouponApplied ) {
				window.location.href = window.location.href;
			}
		},

		/**
		 * Initialize
		 */
		init() {
			this.drawer = $( '#power-coupons-drawer' );
			this.triggerBtn = $( '#power-coupons-view-coupons-btn' );
			this.closeBtn = $( '.power-coupons-drawer-close' );
			this.overlay = $( '.power-coupons-drawer-overlay' );
			this.loadingEl = $( '.power-coupons-drawer-loading' );
			this.couponsListEl = $( '.power-coupons-drawer-coupons-list' );
			this.isOpen = false;
			this.couponsLoaded = false;

			if ( this.drawer.length && powerCouponsDrawer.displayMode ) {
				this.drawer.attr(
					'data-display-mode',
					powerCouponsDrawer.displayMode
				);
			}

			this.bindEvents();

			// Check if WooCommerce Blocks API is available
			if (
				window &&
				window.wc &&
				window.wc.blocksCheckout &&
				this.triggerBtn.length === 0 &&
				typeof window.wc.blocksCheckout.registerCheckoutFilters ===
					'function'
			) {
				// Register filters for blocks checkout
				window.wc.blocksCheckout.registerCheckoutFilters(
					'powerCouponsInjectDrawerButton',
					{
						itemName( defaultValue ) {
							if (
								true ===
								PowerCouponsDrawer.renderedTriggerButton
							) {
								return defaultValue;
							}

							const wcPageType =
								null !==
								document.querySelector(
									'.wp-block-woocommerce-cart'
								)
									? 'cart'
									: 'checkout';

							if (
								'' === powerCouponsDrawer.showOnCart &&
								'cart' === wcPageType
							) {
								return defaultValue;
							}

							if (
								'' === powerCouponsDrawer.showOnCheckout &&
								'checkout' === wcPageType
							) {
								return defaultValue;
							}

							const couponFormBlock = document.querySelector(
								`.wp-block-woocommerce-${ wcPageType }-order-summary-coupon-form-block`
							);

							if ( ! couponFormBlock ) {
								return defaultValue;
							}

							// Insert after BOGO notifications if present, otherwise after coupon form.
							const bogoNotifications =
								couponFormBlock.parentElement.querySelector(
									'.power-coupons-bogo-notifications'
								);
							const targetElement =
								bogoNotifications || couponFormBlock;

							const tempDiv = document.createElement( 'div' );
							tempDiv.classList =
								'power-coupons-drawer-button-wrapper wc-block-components-totals-wrapper';
							tempDiv.innerHTML =
								powerCouponsDrawer.html.drawerButton;

							targetElement.insertAdjacentElement(
								'afterend',
								tempDiv
							);

							// Re-initialize events.
							PowerCouponsDrawer.init();

							PowerCouponsDrawer.renderedTriggerButton = true;

							return defaultValue;
						},
					}
				);
			}
		},

		/**
		 * Bind events
		 */
		bindEvents() {
			const self = this;

			// Open drawer - use event delegation to handle dynamically replaced buttons
			$( document ).on(
				'click',
				'#power-coupons-view-coupons-btn',
				function ( e ) {
					e.preventDefault();
					self.openDrawer();
				}
			);

			// Close drawer - use event delegation
			$( document ).on(
				'click',
				'.power-coupons-drawer-close',
				function ( e ) {
					e.preventDefault();
					self.closeDrawer();
				}
			);

			// Close on overlay click - use event delegation
			$( document ).on(
				'click',
				'.power-coupons-drawer-overlay',
				function () {
					self.closeDrawer();
				}
			);

			// Close on ESC key and implement focus trap
			$( document ).on( 'keydown', function ( e ) {
				if ( ! self.isOpen ) {
					return;
				}

				// ESC key closes drawer
				if ( e.key === 'Escape' ) {
					e.preventDefault();
					self.closeDrawer();
					return;
				}

				// Tab key - implement focus trap
				if ( e.key === 'Tab' ) {
					if (
						! self.focusableElements ||
						self.focusableElements.length === 0
					) {
						return;
					}

					const activeElement =
						self.drawer[ 0 ].ownerDocument.activeElement;

					if ( e.shiftKey ) {
						// Shift + Tab
						if ( activeElement === self.firstFocusableElement ) {
							e.preventDefault();
							self.lastFocusableElement.focus();
						}
					} else if ( activeElement === self.lastFocusableElement ) {
						// Tab
						e.preventDefault();
						self.firstFocusableElement.focus();
					}
				}
			} );

			// Handle apply coupon clicks (delegated)
			$( document ).on(
				'click',
				'.power-coupon-apply-btn',
				function ( e ) {
					e.preventDefault();
					const couponCode = $( this ).data( 'coupon-code' );
					self.applyCoupon( couponCode, $( this ) );
				}
			);

			// Handle remove coupon clicks (delegated)
			$( document ).on(
				'click',
				'.power-coupon-remove-btn',
				function ( e ) {
					e.preventDefault();
					const couponCode = $( this ).data( 'coupon-code' );
					self.removeCoupon( couponCode, $( this ) );
				}
			);
		},

		/**
		 * Open drawer
		 */
		openDrawer() {
			// Store the element that had focus before opening
			this.lastFocusedElement =
				this.drawer[ 0 ].ownerDocument.activeElement;

			this.isOpen = true;
			this.drawer.addClass( 'is-open' );
			this.drawer.attr( 'aria-hidden', 'false' );
			this.triggerBtn.attr( 'aria-expanded', 'true' );
			$( 'body' ).css( 'overflow', 'hidden' );

			// Load coupons if not already loaded
			if ( ! this.couponsLoaded ) {
				this.loadCoupons();
			}

			// Set up focus trap
			this.setupFocusTrap();

			// Move focus to close button after a short delay
			setTimeout( () => {
				this.closeBtn.focus();
			}, 100 );
		},

		/**
		 * Setup focus trap for drawer
		 */
		setupFocusTrap() {
			// Get all focusable elements within the drawer
			const focusableSelectors =
				'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
			this.focusableElements = this.drawer
				.find( focusableSelectors )
				.filter( ':visible' )
				.get();

			if ( this.focusableElements.length > 0 ) {
				this.firstFocusableElement = this.focusableElements[ 0 ];
				this.lastFocusableElement =
					this.focusableElements[ this.focusableElements.length - 1 ];
			}
		},

		/**
		 * Close drawer
		 */
		closeDrawer() {
			this.isOpen = false;
			this.drawer.removeClass( 'is-open' );
			this.drawer.attr( 'aria-hidden', 'true' );
			this.triggerBtn.attr( 'aria-expanded', 'false' );
			$( 'body' ).css( 'overflow', '' );

			// Restore focus to the element that opened the drawer
			if ( this.lastFocusedElement ) {
				this.lastFocusedElement.focus();
				this.lastFocusedElement = null;
			}
		},

		/**
		 * Load coupons via AJAX
		 */
		loadCoupons() {
			const self = this;

			this.loadingEl.show();
			this.couponsListEl.hide().removeClass( 'loaded' );

			$.ajax( {
				url: powerCouponsDrawer.ajaxUrl,
				type: 'POST',
				data: {
					action: 'power_coupons_get_drawer_coupons',
					nonce: powerCouponsDrawer.nonce,
				},
				success( response ) {
					if ( response.success && response.data.html ) {
						self.couponsListEl.html( response.data.html );
						self.couponsListEl.addClass( 'loaded' ).show();
						self.loadingEl.hide();
						self.couponsLoaded = true;
					} else {
						self.showError(
							'Failed to load coupons. Please try again.'
						);
					}
				},
				error() {
					self.showError( 'Connection error. Please try again.' );
				},
			} );
		},

		/**
		 * Reload coupons (for refresh after apply/remove)
		 */
		reloadCoupons() {
			this.couponsLoaded = false;
			this.loadCoupons();
			// Refresh focus trap after loading new content
			setTimeout( () => {
				this.setupFocusTrap();
			}, 100 );
		},

		/**
		 * Apply coupon
		 *
		 * @param {string} couponCode Coupon code to apply
		 * @param {Object} $button    jQuery button element
		 */
		applyCoupon( couponCode, $button ) {
			const self = this;

			if ( ! couponCode ) {
				return;
			}

			// Disable button and show loading state
			$button.prop( 'disabled', true ).addClass( 'loading' );

			$.ajax( {
				url: powerCouponsData.ajaxUrl.applyCoupon,
				type: 'POST',
				data: {
					coupon_code: couponCode,
					nonce: powerCouponsData.nonce,
				},
				success( response ) {
					if ( response.success ) {
						// Show success message
						self.showNotice(
							powerCouponsData.text.successMessage ||
								'Coupon applied successfully!',
							'success'
						);

						PowerCouponsDrawer.maybeReloadPageOnSuccess();

						// Reload coupons to show updated state
						setTimeout( function () {
							self.reloadCoupons();
						}, 500 );
					} else {
						const errorMessage =
							response.data && response.data.message
								? response.data.message
								: powerCouponsData.text.genericErrorMessage ||
								  'Failed to apply coupon.';
						self.showNotice( errorMessage, 'error' );
						$button
							.prop( 'disabled', false )
							.removeClass( 'loading' );
					}
				},
				error() {
					self.showNotice(
						powerCouponsData.text.networkErrorMessage ||
							'Connection error.',
						'error'
					);
					$button.prop( 'disabled', false ).removeClass( 'loading' );
				},
			} );
		},

		/**
		 * Remove coupon
		 *
		 * @param {string} couponCode Coupon code to remove
		 * @param {Object} $button    jQuery button element
		 */
		removeCoupon( couponCode, $button ) {
			const self = this;

			if ( ! couponCode ) {
				return;
			}

			// Disable button and show loading state
			$button.prop( 'disabled', true ).addClass( 'loading' );

			$.ajax( {
				url: powerCouponsData.ajaxUrl.removeCoupon,
				type: 'POST',
				data: {
					coupon_code: couponCode,
					nonce: powerCouponsData.nonce,
				},
				success( response ) {
					if ( response.success ) {
						// Show success message
						self.showNotice(
							powerCouponsData.text.couponRemovedMessage ||
								'Coupon removed.',
							'success'
						);

						// Trigger WooCommerce cart/checkout updates
						self.triggerWooCommerceUpdates();

						// Reload coupons to show updated state
						setTimeout( function () {
							self.reloadCoupons();
						}, 500 );
					} else {
						const errorMessage =
							response.data && response.data.message
								? response.data.message
								: powerCouponsData.text.genericErrorMessage ||
								  'Failed to remove coupon.';
						self.showNotice( errorMessage, 'error' );
						$button
							.prop( 'disabled', false )
							.removeClass( 'loading' );
					}
				},
				error() {
					self.showNotice(
						powerCouponsData.text.networkErrorMessage ||
							'Connection error.',
						'error'
					);
					$button.prop( 'disabled', false ).removeClass( 'loading' );
				},
			} );
		},

		/**
		 * Trigger WooCommerce cart/checkout updates
		 *
		 * Triggers appropriate WooCommerce events to refresh cart/checkout
		 * displays after coupon application or removal.
		 *
		 * @since 1.0.0
		 */
		triggerWooCommerceUpdates() {
			// Check if we're on cart page
			const isCartPage = $( '.woocommerce-cart-form' ).length > 0;
			const isCheckoutPage =
				$( 'form.checkout' ).length > 0 ||
				$( '.wc-block-checkout' ).length > 0;

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
		 * Show error message in drawer
		 *
		 * @param {string} message Error message to display
		 */
		showError( message ) {
			this.loadingEl.hide();
			this.couponsListEl
				.html(
					'<div class="power-coupons-no-coupons" role="alert"><p>' +
						message +
						'</p></div>'
				)
				.addClass( 'loaded' )
				.show();
		},

		/**
		 * Show notice/toast message
		 *
		 * @param {string} message Notice message to display
		 * @param {string} type    Notice type (success or error)
		 */
		showNotice( message, type ) {
			// Use role="status" (polite) for success, role="alert" (assertive) for errors.
			const noticeRole = type === 'error' ? 'alert' : 'status';
			const ariaLive = type === 'error' ? 'assertive' : 'polite';

			// Use WooCommerce notices if available
			const noticeWrapper = $( '.woocommerce-notices-wrapper' ).first();

			if ( noticeWrapper.length ) {
				const noticeClass =
					type === 'success'
						? 'woocommerce-message'
						: 'woocommerce-error';
				const notice = $( '<div>' )
					.addClass( noticeClass )
					.attr( 'role', noticeRole )
					.attr( 'aria-live', ariaLive )
					.attr( 'aria-atomic', 'true' )
					.text( message );

				noticeWrapper.html( notice );

				// Scroll to notice
				$( 'html, body' ).animate(
					{
						scrollTop: noticeWrapper.offset().top - 100,
					},
					500
				);
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

	// Initialize on document ready
	$( document ).ready( function () {
		// Check if drawer elements exist
		if ( $( '#power-coupons-drawer' ).length ) {
			PowerCouponsDrawer.init();
		}
	} );

	// Re-initialize after WooCommerce updates that replace DOM elements
	// These events fire when WooCommerce refreshes cart/checkout content
	$( document.body ).on(
		'updated_checkout updated_cart_totals updated_wc_div',
		function () {
			// Re-initialize drawer references if button was replaced
			if ( $( '#power-coupons-view-coupons-btn' ).length ) {
				PowerCouponsDrawer.triggerBtn = $(
					'#power-coupons-view-coupons-btn'
				);
				// Update aria-expanded attribute if drawer is open
				if ( PowerCouponsDrawer.isOpen ) {
					PowerCouponsDrawer.triggerBtn.attr(
						'aria-expanded',
						'true'
					);
				}
			}
		}
	);
} )( jQuery );
