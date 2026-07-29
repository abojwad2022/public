/* global power_coupons_bogo, wc_cart_params, powerCouponsBogoData */
/**
 * Power Coupons - BOGO JavaScript
 * Handles giveaway product selection and dynamic updates
 *
 * @param {Object} $ jQuery instance.
 * @package
 * @since 1.0.0
 */

( function ( $ ) {
	'use strict';

	const PowerCouponsBOGO = {
		/**
		 * Initialize
		 */
		init() {
			this.bindEvents();
			this.bindVariableOfferIntercept();
			this.initVariationForms();
		},

		/**
		 * Intercept clicks on BOGO "Apply Offer" buttons during the capture phase
		 * so we can hijack the click for variable get-products before the generic
		 * .power-coupons-apply-coupon-btn handler in frontend.js runs.
		 */
		bindVariableOfferIntercept() {
			document.addEventListener(
				'click',
				( e ) => {
					const btn = e.target.closest(
						'.power-coupons-bogo-offer-button'
					);
					if ( ! btn ) {
						return;
					}

					const wrapper = btn.closest(
						'.power-coupons-bogo-offer-wrapper'
					);
					if ( ! wrapper ) {
						return;
					}

					// The server renders the modal markup as a hidden overlay
					// inside variable offers. Its presence marks this offer as
					// needing a variation pick.
					const dormant = wrapper.querySelector(
						'.power-coupons-bogo-modal-template'
					);
					if ( ! dormant ) {
						return;
					}

					// Hijack the click before the generic apply handler runs.
					e.preventDefault();
					e.stopImmediatePropagation();

					this.openVariationModal( dormant, btn );
				},
				true
			);
		},

		/**
		 * Clone the server-rendered modal markup, mount it on <body>, and wire
		 * events. The dormant copy stays hidden inside the offer wrapper.
		 *
		 * @param {HTMLElement} dormant    The offer's hidden modal overlay.
		 * @param {HTMLElement} triggerBtn The clicked "Apply Offer" button.
		 */
		openVariationModal( dormant, triggerBtn ) {
			this.closeVariationModal();

			const overlay = dormant.cloneNode( true );
			overlay.classList.remove( 'power-coupons-bogo-modal-template' );
			overlay.removeAttribute( 'aria-hidden' );
			document.body.appendChild( overlay );

			const $overlay = $( overlay );
			const $modal = $overlay.find( '.power-coupons-bogo-modal' );
			const $form = $overlay.find( '.power-coupons-bogo-modal-form' );
			const $error = $overlay.find( '.power-coupons-bogo-modal-error' );

			// 'change' = swap an already-applied gift's variation; 'apply' = apply the offer.
			const mode =
				$form.attr( 'data-mode' ) === 'change' ? 'change' : 'apply';

			this.activeModal = {
				$overlay,
				$form,
				$error,
				triggerBtn,
				mode,
			};

			$form.on(
				'change',
				'.power-coupons-bogo-modal-select',
				this.handleModalSelectChange.bind( this )
			);
			$form.on( 'submit', this.submitVariationModal.bind( this ) );
			$modal.on(
				'click',
				'.power-coupons-bogo-modal-close, .power-coupons-bogo-modal-cancel',
				this.closeVariationModal.bind( this )
			);
			$overlay.on( 'click', ( e ) => {
				if ( e.target === $overlay[ 0 ] ) {
					this.closeVariationModal();
				}
			} );

			// In change mode, pre-select the customer's current variation so they
			// adjust from where they are, then refresh the submit button state.
			if ( 'change' === mode ) {
				this.prefillModalSelections( $form );
				this.handleModalSelectChange();
			}
		},

		/**
		 * Pre-select the modal's variation dropdowns from the currently-added gift.
		 *
		 * @param {jQuery} $form The cloned modal form.
		 */
		prefillModalSelections( $form ) {
			let selections = [];
			try {
				selections = JSON.parse(
					$form.attr( 'data-current-selections' ) || '[]'
				);
			} catch ( err ) {
				selections = [];
			}

			if ( ! Array.isArray( selections ) ) {
				return;
			}

			selections.forEach( ( selection ) => {
				if ( ! selection || ! selection.attributes ) {
					return;
				}

				const $product = $form.find(
					'.power-coupons-bogo-modal-product[data-product-id="' +
						selection.product_id +
						'"]'
				);
				if ( ! $product.length ) {
					return;
				}

				Object.keys( selection.attributes ).forEach( ( slug ) => {
					const value = selection.attributes[ slug ];
					const select = $product
						.find(
							'.power-coupons-bogo-modal-select[data-attr-name="' +
								slug +
								'"]'
						)
						.get( 0 );
					if ( ! select ) {
						return;
					}

					// Match by option value (slug); set selectedIndex explicitly
					// because the block cart can strip empty value="" attributes.
					for ( let i = 0; i < select.options.length; i++ ) {
						if ( select.options[ i ].value === value ) {
							select.selectedIndex = i;
							break;
						}
					}
				} );
			} );
		},

		/**
		 * Close & cleanup the variation modal.
		 */
		closeVariationModal() {
			if ( this.activeModal && this.activeModal.$overlay ) {
				this.activeModal.$overlay.remove();
			}
			this.activeModal = null;
		},

		/**
		 * Toggle submit button when all selects have a value.
		 */
		handleModalSelectChange() {
			if ( ! this.activeModal ) {
				return;
			}
			const $form = this.activeModal.$form;
			let allFilled = true;
			$form.find( '.power-coupons-bogo-modal-select' ).each( function () {
				// The placeholder is always the first option; treat it as
				// "unchosen". value="" can be stripped by the block cart's DOM
				// normalisation, so selectedIndex is the reliable check.
				if ( this.selectedIndex <= 0 ) {
					allFilled = false;
					return false;
				}
			} );
			$form
				.find( '.power-coupons-bogo-modal-submit' )
				.prop( 'disabled', ! allFilled );
		},

		/**
		 * Submit the variation modal — POSTs to the new endpoint that applies
		 * the coupon and adds the chosen variation(s) atomically.
		 *
		 * @param {Event} e
		 */
		submitVariationModal( e ) {
			e.preventDefault();

			if ( ! this.activeModal ) {
				return;
			}

			const { $form, $error, triggerBtn, mode } = this.activeModal;
			const text =
				( window.powerCouponsBogoData &&
					window.powerCouponsBogoData.text ) ||
				{};

			$error.hide().text( '' );

			// Group the chosen attribute values by product, reading the
			// product id from each select's section in the cloned template.
			const selectionMap = {};
			const selections = [];
			let allValid = true;

			$form.find( '.power-coupons-bogo-modal-select' ).each( function () {
				if ( this.selectedIndex <= 0 ) {
					allValid = false;
					return false;
				}

				const $select = $( this );
				const value = $select.val();
				const productId = $select
					.closest( '.power-coupons-bogo-modal-product' )
					.attr( 'data-product-id' );
				const attrName = $select.attr( 'data-attr-name' );

				if ( ! selectionMap[ productId ] ) {
					selectionMap[ productId ] = {
						product_id: parseInt( productId, 10 ),
						attributes: {},
					};
					selections.push( selectionMap[ productId ] );
				}
				selectionMap[ productId ].attributes[ attrName ] = value;
			} );

			if ( ! allValid ) {
				$error
					.text(
						text.selectAllOptions || 'Please choose every option.'
					)
					.show();
				return;
			}

			const couponCode = $form.attr( 'data-coupon-code' );
			const $submit = $form.find( '.power-coupons-bogo-modal-submit' );
			const submitOriginal = $submit.text();
			$submit.prop( 'disabled', true ).text( text.adding || 'Adding...' );

			// 'change' swaps an already-applied gift; 'apply' applies the offer.
			const action =
				'change' === mode
					? 'power_coupons_change_giveaway_variation'
					: 'power_coupons_apply_offer_with_variations';

			$.ajax( {
				url: powerCouponsBogoData.ajaxUrl,
				type: 'POST',
				data: {
					action,
					nonce: powerCouponsBogoData.nonce,
					coupon_code: couponCode,
					variations: selections,
				},
				success: ( response ) => {
					if ( response && response.success ) {
						this.closeVariationModal();
						if ( response.data && response.data.reload ) {
							window.location.reload();
							return;
						}
						$( document.body ).trigger( 'wc_update_cart' );
						$( document.body ).trigger( 'updated_wc_div' );
					} else {
						const message =
							( response &&
								response.data &&
								response.data.message ) ||
							text.error ||
							'Something went wrong.';
						$error.text( message ).show();
						$submit
							.prop( 'disabled', false )
							.text( submitOriginal );
						if ( triggerBtn ) {
							$( triggerBtn )
								.prop( 'disabled', false )
								.removeClass( 'pc-loading' );
						}
					}
				},
				error: () => {
					$error.text( text.error || 'Something went wrong.' ).show();
					$submit.prop( 'disabled', false ).text( submitOriginal );
				},
			} );
		},

		/**
		 * Bind events
		 */
		bindEvents() {
			// Giveaway product selection
			$( document ).on(
				'submit',
				'.power-coupons-bogo-giveaway-selector .variations-form',
				this.handleGiveawaySelection.bind( this )
			);

			// Monitor variation selection changes to enable/disable button
			$( document ).on(
				'change',
				'.power-coupons-bogo-giveaway-selector .variation-select',
				this.handleVariationChange.bind( this )
			);

			// Update BOGO status on cart changes
			$( document.body ).on(
				'updated_cart_totals',
				this.onCartUpdate.bind( this )
			);
			$( document.body ).on(
				'updated_checkout',
				this.onCartUpdate.bind( this )
			);

			// WooCommerce Blocks: reload page when coupons change so BOGO notifications refresh.
			this.bindBlockEvents();
		},

		/**
		 * Bind WooCommerce Blocks events for BOGO notification refresh.
		 *
		 * Block cart/checkout handles coupon apply/remove via React — classic
		 * jQuery events don't fire. Use registerCheckoutFilters to detect
		 * coupon changes and reload the page for fresh server-rendered HTML.
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

			// Only act on block pages.
			const isBlockPage =
				document.querySelector( '.wc-block-cart' ) !== null ||
				document.querySelector( '.wc-block-checkout' ) !== null;

			if ( ! isBlockPage ) {
				return;
			}

			window.wc.blocksCheckout.registerCheckoutFilters(
				'powerCouponsBogoRefresh',
				{
					showRemoveCouponNotice( defaultValue ) {
						// Coupon removed in block cart/checkout — reload for fresh BOGO state.
						window.location.reload();
						return defaultValue;
					},
				}
			);
		},

		/**
		 * Apply BOGO offer
		 * @param {Event} e
		 */
		applyBogoOffer( e ) {
			e.preventDefault();
			const $button = $( e.currentTarget );
			const couponCode = $button.data( 'coupon-code' );

			if ( ! couponCode ) {
				return;
			}

			// Disable button
			$button
				.prop( 'disabled', true )
				.text( power_coupons_bogo.applying_text || 'Applying...' );

			// Apply coupon via AJAX
			$.ajax( {
				url: wc_cart_params.wc_ajax_url
					.toString()
					.replace( '%%endpoint%%', 'apply_coupon' ),
				type: 'POST',
				data: {
					coupon_code: couponCode,
				},
				success: ( response ) => {
					if ( response && response.error ) {
						this.showNotice( response.error, 'error' );
						$button
							.prop( 'disabled', false )
							.text(
								power_coupons_bogo.apply_text || 'Apply Offer'
							);
					} else {
						// Trigger cart update
						$( document.body ).trigger( 'wc_update_cart' );
					}
				},
				error: () => {
					this.showNotice(
						power_coupons_bogo.error_text ||
							'Failed to apply offer',
						'error'
					);
					$button
						.prop( 'disabled', false )
						.text( power_coupons_bogo.apply_text || 'Apply Offer' );
				},
			} );
		},

		/**
		 * Initialize variation forms
		 */
		initVariationForms() {
			$( '.power-coupons-bogo-giveaway-selector .variations-form' ).each(
				function () {
					// Check if all selections are made
					PowerCouponsBOGO.validateForm( $( this ) );
				}
			);
		},

		/**
		 * Handle variation selection change
		 * @param {Event} e
		 */
		handleVariationChange( e ) {
			const $form = $( e.target ).closest( '.variations-form' );
			this.validateForm( $form );
		},

		/**
		 * Validate form and enable/disable button
		 * @param {Object} $form
		 */
		validateForm( $form ) {
			const $button = $form.find( '.power-coupons-add-giveaway-btn' );
			const allSelected = this.areAllAttributesSelected( $form );

			$button.prop( 'disabled', ! allSelected );
		},

		/**
		 * Check if all attributes are selected
		 * @param {Object} $form
		 */
		areAllAttributesSelected( $form ) {
			let allSelected = true;

			$form.find( '.variation-select' ).each( function () {
				if ( ! $( this ).val() ) {
					allSelected = false;
					return false; // Break loop
				}
			} );

			return allSelected;
		},

		/**
		 * Handle giveaway selection
		 * @param {Event} e
		 */
		handleGiveawaySelection( e ) {
			e.preventDefault();

			const $form = $( e.currentTarget );

			// Collect selected attributes
			const attributes = {};
			$form.find( '.variation-select' ).each( function () {
				attributes[ $( this ).attr( 'name' ) ] = $( this ).val();
			} );

			// Validate selection
			if ( ! this.validateSelection( attributes ) ) {
				this.showNotice(
					powerCouponsBogoData.text.selectAllOptions,
					'error'
				);
				return;
			}

			const $button = $form.find( '.power-coupons-add-giveaway-btn' );
			const couponCode = $button.data( 'coupon-code' );
			const productId = $button.data( 'product-id' );

			// Show loading state
			$button
				.prop( 'disabled', true )
				.addClass( 'loading' )
				.text( 'Adding...' );

			// Send AJAX request
			$.ajax( {
				url: powerCouponsBogoData.ajaxUrl,
				type: 'POST',
				data: {
					action: 'power_coupons_add_giveaway_product',
					nonce: powerCouponsBogoData.nonce,
					coupon_code: couponCode,
					product_id: productId,
					attributes,
				},
				success: ( response ) => {
					if ( response.success ) {
						// Trigger cart update
						$( document.body ).trigger( 'wc_fragment_refresh' );
						$( document.body ).trigger( 'updated_wc_div' );

						this.showNotice(
							powerCouponsBogoData.text.giftAdded,
							'success'
						);

						// Hide the product card or show success message
						$form
							.closest( '.giveaway-product-card' )
							.fadeOut( 300 );
					} else {
						this.showNotice(
							response.data.message ||
								powerCouponsBogoData.text.error,
							'error'
						);
						$button
							.prop( 'disabled', false )
							.removeClass( 'loading' )
							.text( 'Add Free Gift' );
					}
				},
				error: () => {
					this.showNotice( powerCouponsBogoData.text.error, 'error' );
					$button
						.prop( 'disabled', false )
						.removeClass( 'loading' )
						.text( 'Add Free Gift' );
				},
			} );
		},

		/**
		 * Validate selection
		 * @param {Object} attributes
		 */
		validateSelection( attributes ) {
			// Check all required attributes are selected
			for ( const key in attributes ) {
				if ( ! attributes[ key ] ) {
					return false;
				}
			}
			return true;
		},

		/**
		 * Handle cart update
		 */
		onCartUpdate() {
			// Cart has been updated, notifications and selector will be re-rendered by PHP
			// Re-initialize forms if they exist
			PowerCouponsBOGO.initVariationForms();
		},

		/**
		 * Show notice
		 * @param {string} message
		 * @param {string} type
		 */
		showNotice( message, type ) {
			// Use WooCommerce notice system
			const noticeClass =
				type === 'error' ? 'woocommerce-error' : 'woocommerce-message';
			const $notice = $( '<div>' )
				.addClass( noticeClass )
				.attr( 'role', 'alert' )
				.text( message );

			// Find or create notices wrapper
			let $wrapper = $( '.woocommerce-notices-wrapper' ).first();
			if ( ! $wrapper.length ) {
				$wrapper = $(
					'<div class="woocommerce-notices-wrapper"></div>'
				);
				$( '.woocommerce' ).prepend( $wrapper );
			}

			$wrapper.html( $notice );

			// Scroll to notice
			$( 'html, body' ).animate(
				{
					scrollTop: $wrapper.offset().top - 100,
				},
				300
			);

			// Auto-hide success messages after 5 seconds
			if ( type === 'success' ) {
				setTimeout( () => {
					$notice.fadeOut( 300, function () {
						$( this ).remove();
					} );
				}, 5000 );
			}
		},
	};

	// Initialize on document ready
	$( document ).ready( () => {
		PowerCouponsBOGO.init();
	} );
} )( jQuery );
