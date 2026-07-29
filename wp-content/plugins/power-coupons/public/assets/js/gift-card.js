/* global powerCouponsGiftCard */
/**
 * Power Coupons - Gift Card Frontend JavaScript
 * Handles amount dropdown, custom input, price display,
 * multi-recipient quantity sync, and form validation.
 *
 * @param {Object} $ jQuery instance.
 * @package
 * @since 1.0.0
 */

( function ( $ ) {
	'use strict';

	const PowerCouponsGiftCard = {
		/**
		 * Initialize
		 */
		init() {
			this.config =
				typeof powerCouponsGiftCard !== 'undefined'
					? powerCouponsGiftCard
					: {};
			this.bindEvents();
			this.syncRecipientGroups();
			this.updatePriceDisplay();

			// Disable browser-native validation on the cart form so our
			// custom WooCommerce-style notices are the only feedback.
			$( 'form.cart' ).attr( 'novalidate', 'novalidate' );

			// Defer style sync so the browser has fully computed input styles.
			$( window ).on( 'load', this.syncSelectStyle.bind( this ) );
		},

		/**
		 * Match the amount dropdown's visual style to the sibling text inputs.
		 *
		 * Themes style select and input elements independently, so border,
		 * border-radius, and padding often differ. This reads the computed
		 * styles from the first recipient text input and applies them to the
		 * select so they look identical regardless of the active theme.
		 */
		syncSelectStyle() {
			const $select = $( '.power-coupon-gc-amount-select' );
			const refInput = document.querySelector(
				'.power-coupon-gc-recipient-fields .input-text'
			);

			if ( ! $select.length || ! refInput ) {
				return;
			}

			const cs = window.getComputedStyle( refInput );

			$select.css( {
				border: cs.border,
				'border-radius': cs.borderRadius,
				padding: cs.padding,
				height: cs.height,
				'min-height': cs.height,
				'line-height': cs.lineHeight,
				'font-size': cs.fontSize,
				'box-sizing': cs.boxSizing,
			} );
		},

		/**
		 * Bind events
		 */
		bindEvents() {
			// Amount dropdown change.
			$( document ).on(
				'change',
				'.power-coupon-gc-amount-select',
				this.handleAmountChange.bind( this )
			);

			// Custom amount input.
			$( document ).on(
				'input',
				'.power-coupon-gc-custom-amount-input',
				this.handleCustomAmountInput.bind( this )
			);

			// Quantity field change — sync recipient groups.
			$( document ).on(
				'change input',
				'form.cart input.qty, form.cart .quantity input[type="number"]',
				this.handleQuantityChange.bind( this )
			);

			// Form validation before add to cart.
			$( document ).on(
				'submit',
				'form.cart',
				this.handleFormSubmit.bind( this )
			);
		},

		/**
		 * Handle amount dropdown change.
		 *
		 * @param {Event} e Change event.
		 */
		handleAmountChange( e ) {
			const value = $( e.target ).val();

			if ( 'custom' === value ) {
				$( '.power-coupon-gc-custom-input' ).slideDown( 200 );
				const customVal = parseFloat(
					$( '.power-coupon-gc-custom-amount-input' ).val()
				);
				if ( customVal > 0 ) {
					this.updatePriceDisplay( customVal );
				}
			} else {
				$( '.power-coupon-gc-custom-input' ).slideUp( 200 );
				this.updatePriceDisplay( parseFloat( value ) );
			}
		},

		/**
		 * Handle custom amount input.
		 *
		 * @param {Event} e Input event.
		 */
		handleCustomAmountInput( e ) {
			const value = parseFloat( $( e.target ).val() );
			if ( value > 0 ) {
				this.updatePriceDisplay( value );
			}
		},

		/**
		 * Handle quantity field change — sync recipient groups.
		 */
		handleQuantityChange() {
			this.syncRecipientGroups();
		},

		/**
		 * Sync the number of recipient field groups to match the quantity.
		 */
		syncRecipientGroups() {
			const $container = $( '.power-coupon-gc-recipients-container' );
			if ( ! $container.length ) {
				return;
			}

			const qty =
				parseInt(
					$(
						'form.cart input.qty, form.cart .quantity input[type="number"]'
					)
						.first()
						.val(),
					10
				) || 1;

			const $groups = $container.find(
				'.power-coupon-gc-recipient-group'
			);
			const currentCount = $groups.length;

			if ( qty === currentCount ) {
				return;
			}

			if ( qty > currentCount ) {
				// Add more groups.
				const $template = $groups.first();
				for ( let i = currentCount; i < qty; i++ ) {
					const $clone = $template.clone();
					$clone.attr( 'data-index', i );

					// Update field names and clear values.
					$clone
						.find( '.power-coupon-gc-recipient-name' )
						.attr(
							'name',
							'power_coupon_gc_recipients[' + i + '][name]'
						)
						.val( '' );
					$clone
						.find( '.power-coupon-gc-recipient-email' )
						.attr(
							'name',
							'power_coupon_gc_recipients[' + i + '][email]'
						)
						.val( '' );
					$clone
						.find( '.power-coupon-gc-recipient-message' )
						.attr(
							'name',
							'power_coupon_gc_recipients[' + i + '][message]'
						)
						.val( '' );

					// Update the header label.
					$clone
						.find( '.power-coupon-gc-recipient-number' )
						.text( this.config.text.recipient + ' ' + ( i + 1 ) );

					$container.append( $clone );
				}
			} else {
				// Remove extra groups (keep from the end).
				$groups.each( function ( index ) {
					if ( index >= qty ) {
						$( this ).remove();
					}
				} );
			}

			// Update header visibility — hide "Recipient 1" header when only 1 group.
			const $updatedGroups = $container.find(
				'.power-coupon-gc-recipient-group'
			);
			$updatedGroups.each( function ( index ) {
				const $header = $( this ).find(
					'.power-coupon-gc-recipient-header'
				);
				if ( $updatedGroups.length <= 1 ) {
					$header.hide();
				} else {
					$header.show();
					$( this )
						.find( '.power-coupon-gc-recipient-number' )
						.text(
							PowerCouponsGiftCard.config.text.recipient +
								' ' +
								( index + 1 )
						);
				}
			} );
		},

		/**
		 * Update the product price display.
		 *
		 * @param {number|undefined} amount Amount to display.
		 */
		updatePriceDisplay( amount ) {
			if ( typeof amount === 'undefined' ) {
				const val = $( '.power-coupon-gc-amount-select' ).val();
				if ( ! val ) {
					return;
				}
				if ( 'custom' === val ) {
					amount = parseFloat(
						$( '.power-coupon-gc-custom-amount-input' ).val()
					);
				} else {
					amount = parseFloat( val );
				}
			}

			if ( ! amount || isNaN( amount ) ) {
				return;
			}

			const formatted = this.formatPrice( amount );
			const $priceEl = $( '.product .price' ).first();

			if ( $priceEl.length ) {
				$priceEl.html(
					'<span class="woocommerce-Price-amount amount">' +
						formatted +
						'</span>'
				);
			}
		},

		/**
		 * Format a price value using WooCommerce settings.
		 *
		 * @param {number} price Price to format.
		 * @return {string} Formatted price HTML.
		 */
		formatPrice( price ) {
			const cfg = this.config;
			const decimals = parseInt( cfg.decimals, 10 ) || 2;
			const decSep = cfg.decimalSep || '.';
			const thousandSep = cfg.thousandSep || ',';
			const symbol = cfg.currencySymbol || '$';
			const position = cfg.currencyPosition || 'left';

			const fixed = price.toFixed( decimals );
			const parts = fixed.split( '.' );
			parts[ 0 ] = parts[ 0 ].replace(
				/\B(?=(\d{3})+(?!\d))/g,
				thousandSep
			);
			const formatted = parts.join( decSep );

			const symbolHtml =
				'<span class="woocommerce-Price-currencySymbol">' +
				symbol +
				'</span>';

			switch ( position ) {
				case 'left':
					return symbolHtml + formatted;
				case 'right':
					return formatted + symbolHtml;
				case 'left_space':
					return symbolHtml + '&nbsp;' + formatted;
				case 'right_space':
					return formatted + '&nbsp;' + symbolHtml;
				default:
					return symbolHtml + formatted;
			}
		},

		/**
		 * Validate and handle form submission.
		 *
		 * @param {Event} e Submit event.
		 * @return {boolean} Whether to allow form submission.
		 */
		handleFormSubmit( e ) {
			if ( ! $( '.power-coupon-gc-fields' ).length ) {
				return true;
			}

			const errors = [];
			const cfg = this.config;

			// Validate amount.
			const selectedAmount = $( '.power-coupon-gc-amount-select' ).val();
			if ( ! selectedAmount ) {
				errors.push( cfg.text.selectAmount );
			} else if ( 'custom' === selectedAmount ) {
				const customAmount = parseFloat(
					$( '.power-coupon-gc-custom-amount-input' ).val()
				);
				if ( ! customAmount || customAmount <= 0 ) {
					errors.push( cfg.text.enterAmount );
				} else if ( customAmount < cfg.customMin ) {
					errors.push(
						cfg.text.amountTooLow.replace(
							'%s',
							cfg.currencySymbol + cfg.customMin
						)
					);
				} else if ( customAmount > cfg.customMax ) {
					errors.push(
						cfg.text.amountTooHigh.replace(
							'%s',
							cfg.currencySymbol + cfg.customMax
						)
					);
				}
			}

			// Validate each recipient group.
			$( '.power-coupon-gc-recipient-group' ).each( function () {
				const name = $( this )
					.find( '.power-coupon-gc-recipient-name' )
					.val()
					.trim();

				if ( ! name ) {
					errors.push( cfg.text.enterName );
					return false; // Break.
				}

				const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
				const email = $( this )
					.find( '.power-coupon-gc-recipient-email' )
					.val()
					.trim();

				if ( ! email || ! emailRegex.test( email ) ) {
					errors.push( cfg.text.enterEmail );
					return false; // Break.
				}
			} );

			if ( errors.length > 0 ) {
				e.preventDefault();
				this.showNotices( errors );
				return false;
			}

			return true;
		},

		/**
		 * Show WooCommerce-style error notices.
		 *
		 * @param {Array} messages Error messages.
		 */
		showNotices( messages ) {
			$( '.power-coupon-gc-notices' ).remove();

			const html =
				'<ul class="woocommerce-error power-coupon-gc-notices" role="alert">' +
				messages
					.map( function ( msg ) {
						return '<li>' + msg + '</li>';
					} )
					.join( '' ) +
				'</ul>';

			const $form = $( 'form.cart' );
			let $wrapper = $form
				.closest( '.product' )
				.find( '.woocommerce-notices-wrapper' )
				.first();

			if ( ! $wrapper.length ) {
				$wrapper = $form.parent();
			}

			$wrapper.prepend( html );

			$( 'html, body' ).animate(
				{
					scrollTop:
						$( '.power-coupon-gc-notices' ).offset().top - 100,
				},
				300
			);
		},
	};

	$( document ).ready( function () {
		PowerCouponsGiftCard.init();
	} );
} )( jQuery );
