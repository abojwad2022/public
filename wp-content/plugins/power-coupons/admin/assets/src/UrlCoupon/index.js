import {
	Button,
	CheckboxControl,
	Notice,
	ToggleControl,
} from '@wordpress/components';
import { createRoot, useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import './styles.scss';

const data = window.powerCouponsUrlCoupon || {};

/**
 * Copy text to the clipboard.
 *
 * The async Clipboard API is only available in a secure context (HTTPS or
 * localhost); many WordPress installs run the admin over plain HTTP, where
 * `navigator.clipboard` is undefined. Fall back to a hidden textarea +
 * `document.execCommand( 'copy' )` so copying still works there.
 *
 * @param {string} text Text to copy.
 * @return {Promise<boolean>} Resolves true when the copy succeeded.
 */
function copyText( text ) {
	if ( ! text ) {
		return Promise.resolve( false );
	}

	const fallback = () => {
		try {
			const textarea = document.createElement( 'textarea' );
			textarea.value = text;
			textarea.setAttribute( 'readonly', '' );
			textarea.style.position = 'fixed';
			textarea.style.top = '-9999px';
			textarea.style.opacity = '0';
			document.body.appendChild( textarea );
			textarea.focus();
			textarea.select();
			const ok = document.execCommand( 'copy' );
			document.body.removeChild( textarea );
			return ok;
		} catch ( e ) {
			return false;
		}
	};

	if ( navigator.clipboard && window.isSecureContext ) {
		return navigator.clipboard
			.writeText( text )
			.then( () => true )
			.catch( () => fallback() );
	}

	return Promise.resolve( fallback() );
}

/**
 * A WooCommerce-style help tip: a "?" icon that reveals a tooltip on
 * hover/focus, mirroring the `.woocommerce-help-tip` used on the native
 * coupon data tabs.
 *
 * @param {Object} props      Component props.
 * @param {string} props.text Tooltip text.
 * @return {JSX.Element} The help tip.
 */
function HelpTip( { text } ) {
	const ref = useRef( null );
	const [ open, setOpen ] = useState( false );
	const [ pos, setPos ] = useState( { top: 0, left: 0 } );

	// The coupon data box (`.panel-wrap`, `.postbox`) clips overflow, so the
	// bubble is positioned with `position: fixed` against the viewport to
	// escape that clipping, mirroring WooCommerce's body-level help tips.
	const show = () => {
		if ( ! ref.current ) {
			return;
		}
		const rect = ref.current.getBoundingClientRect();
		setPos( {
			top: rect.bottom + 8,
			left: rect.left + rect.width / 2,
		} );
		setOpen( true );
	};
	const hide = () => setOpen( false );

	// The bubble is `position: fixed` against viewport coords captured once on
	// open, so any scroll/resize detaches it from its icon. Close it on those
	// events (matching native WC help tips, which also dismiss on scroll).
	// `mouseleave`/`blur` can't be relied on here — a wheel-scroll with the
	// pointer held still fires neither.
	useEffect( () => {
		if ( ! open ) {
			return undefined;
		}
		window.addEventListener( 'scroll', hide, true );
		window.addEventListener( 'resize', hide );
		return () => {
			window.removeEventListener( 'scroll', hide, true );
			window.removeEventListener( 'resize', hide );
		};
	}, [ open ] );

	return (
		<span
			ref={ ref }
			className="power-coupons-url-coupon__tip"
			tabIndex={ 0 }
			role="img"
			aria-label={ text }
			onMouseEnter={ show }
			onMouseLeave={ hide }
			onFocus={ show }
			onBlur={ hide }
		>
			{ open && (
				<span
					className="power-coupons-url-coupon__tip-bubble"
					role="tooltip"
					style={ { top: pos.top, left: pos.left } }
				>
					{ text }
				</span>
			) }
		</span>
	);
}

/**
 * A label-left / control-right field row.
 *
 * @param {Object}      props           Component props.
 * @param {string}      props.label     Field label (left column).
 * @param {string}      props.htmlFor   ID of the control the label points to.
 * @param {string}      props.tip       Help-tip text shown via the label's "?" icon.
 * @param {string}      props.className Extra class names added to the row.
 * @param {JSX.Element} props.children  The control rendered in the right column.
 * @return {JSX.Element} The field row.
 */
function LabeledRow( { label, htmlFor, tip, className = '', children } ) {
	return (
		<div
			className={ `power-coupons-url-coupon__row${
				className ? ` ${ className }` : ''
			}` }
		>
			{ htmlFor ? (
				<label
					className="power-coupons-url-coupon__row-label"
					htmlFor={ htmlFor }
				>
					{ label }
				</label>
			) : (
				<span className="power-coupons-url-coupon__row-label">
					{ label }
				</span>
			) }
			<div className="power-coupons-url-coupon__row-control">
				{ children }
				{ tip && <HelpTip text={ tip } /> }
			</div>
		</div>
	);
}

/**
 * Read-only URL field with a copy button.
 *
 * @param {Object}   props             Component props.
 * @param {string}   props.label       Field label.
 * @param {string}   props.value       URL value to display/copy.
 * @param {string}   props.tip         Help-tip text shown via the label's "?" icon.
 * @param {string}   props.copied      Key of the row currently showing "Copied!".
 * @param {string}   props.rowKey      This row's key, compared against `copied`.
 * @param {Function} props.onCopy      Copy handler, called with (value, rowKey).
 * @param {string}   props.placeholder Placeholder when value is empty.
 * @param {boolean}  props.disabled    Disable the copy button regardless of value.
 * @return {JSX.Element} The field.
 */
function CopyableUrl( {
	label,
	value,
	tip,
	copied,
	rowKey,
	onCopy,
	placeholder,
	disabled = false,
} ) {
	const inputId = `power-coupons-url-coupon-${ rowKey }`;
	return (
		<div className="power-coupons-url-coupon__field">
			<label
				className="power-coupons-url-coupon__label"
				htmlFor={ inputId }
			>
				{ label }
				{ tip && <HelpTip text={ tip } /> }
			</label>
			<div className="power-coupons-url-coupon__url-row">
				<input
					id={ inputId }
					type="text"
					className="power-coupons-url-coupon__url-input"
					value={ value }
					placeholder={ placeholder }
					readOnly
					onFocus={ ( e ) => e.target.select() }
				/>
				<Button
					variant="secondary"
					onClick={ () => onCopy( value, rowKey ) }
					disabled={ disabled || ! value }
				>
					{ copied === rowKey
						? __( 'Copied!', 'power-coupons' )
						: __( 'Copy', 'power-coupons' ) }
				</Button>
			</div>
		</div>
	);
}

function App() {
	const [ enabled, setEnabled ] = useState( 'yes' === data.enabled );
	const [ expiryDays, setExpiryDays ] = useState(
		String( data.expiryDays ?? 0 )
	);
	const [ maxUses, setMaxUses ] = useState( String( data.maxUses ?? 0 ) );
	const [ redirect, setRedirect ] = useState( data.redirect || '' );
	const [ oneTime, setOneTime ] = useState( 'yes' === data.oneTime );
	const [ tokenUrl, setTokenUrl ] = useState( '' );
	const [ tokenError, setTokenError ] = useState( '' );
	const [ generating, setGenerating ] = useState( false );
	const [ copied, setCopied ] = useState( '' );

	// Holds the "Copied!" reset timer so it can be cancelled on unmount or
	// before a rapid re-copy, avoiding a state-update-on-unmounted warning and
	// overlapping timers.
	const copiedTimer = useRef( null );
	useEffect(
		() => () => {
			if ( copiedTimer.current ) {
				clearTimeout( copiedTimer.current );
			}
		},
		[]
	);

	const isPublished = !! data.isPublished;
	const couponUrl = data.url || '';

	// When the URL Coupons feature is switched off globally (Settings → URL
	// Coupons), links never apply — the apply controller bails early. Reflect
	// that here so the admin isn't left configuring a link that silently fails:
	// show a notice and disable every control. Default to enabled when the flag
	// is absent (e.g. an older Pro build that doesn't localize it).
	const featureEnabled =
		undefined === data.featureEnabled ? true : !! data.featureEnabled;
	const settingsUrl = data.settingsUrl || '';

	const handleCopy = ( text, key ) => {
		copyText( text ).then( ( ok ) => {
			if ( ! ok ) {
				return;
			}
			setCopied( key );
			if ( copiedTimer.current ) {
				clearTimeout( copiedTimer.current );
			}
			copiedTimer.current = setTimeout( () => setCopied( '' ), 1500 );
		} );
	};

	const generateToken = () => {
		setGenerating( true );
		setTokenError( '' );

		const body = new window.FormData();
		body.append( 'action', 'power_coupons_generate_url_token' );
		body.append( 'nonce', data.generateNonce || '' );
		body.append( 'coupon_id', data.couponId || 0 );

		// Matches the app's `@wordpress/api-fetch` convention (nonce handling,
		// error normalization). admin-ajax returns HTTP 200 even for
		// `wp_send_json_error`, so a failed mint resolves with
		// `{ success: false }` rather than rejecting — handle both that and a
		// genuine network/HTTP error (the `.catch`). The token field stays
		// URL-only so Copy stays disabled and failures show in a separate
		// notice instead of masquerading as a copyable link.
		apiFetch( {
			url: data.ajaxurl,
			method: 'POST',
			body,
		} )
			.then( ( res ) => {
				if ( res && res.success && res.data && res.data.url ) {
					setTokenUrl( res.data.url );
				} else {
					setTokenError(
						res && res.data && res.data.message
							? res.data.message
							: __(
									'Could not generate a link. Please try again.',
									'power-coupons'
							  )
					);
				}
			} )
			.catch( () => {
				setTokenError(
					__(
						'Could not generate a link. Please try again.',
						'power-coupons'
					)
				);
			} )
			.finally( () => {
				setGenerating( false );
			} );
	};

	const onlyDigits = ( raw ) => raw.replace( /[^0-9]/g, '' );

	return (
		<div
			className={ `power-coupons-url-coupon${
				! featureEnabled ? ' power-coupons-url-coupon--feature-off' : ''
			}` }
		>
			{ /* Hidden inputs submitted with the WC coupon form and read by the PHP save handler. */ }
			<input
				type="hidden"
				name="_power_coupon_url_enabled"
				value={ enabled ? 'yes' : 'no' }
			/>
			<input
				type="hidden"
				name="_power_coupon_url_expiry_days"
				value={ expiryDays || '0' }
			/>
			<input
				type="hidden"
				name="_power_coupon_url_max_uses"
				value={ maxUses || '0' }
			/>
			<input
				type="hidden"
				name="_power_coupon_url_redirect"
				value={ redirect }
			/>
			<input
				type="hidden"
				name="_power_coupon_url_one_time"
				value={ oneTime ? 'yes' : 'no' }
			/>

			{ ! featureEnabled && (
				<div
					className="power-coupons-url-coupon__feature-notice"
					role="alert"
				>
					<span
						className="power-coupons-url-coupon__feature-notice-icon"
						aria-hidden="true"
					>
						{ '⚠' }
					</span>
					<div className="power-coupons-url-coupon__feature-notice-body">
						<strong>
							{ __(
								'URL Coupons are turned off globally.',
								'power-coupons'
							) }
						</strong>
						<p>
							{ __(
								"Links won't apply until you enable the feature in Settings → URL Coupons.",
								'power-coupons'
							) }
						</p>
						{ settingsUrl && (
							<a
								className="power-coupons-url-coupon__feature-notice-link"
								href={ settingsUrl }
							>
								{ __( 'Go to settings →', 'power-coupons' ) }
							</a>
						) }
					</div>
				</div>
			) }

			<div className="power-coupons-url-coupon__header">
				<div className="power-coupons-url-coupon__header__title">
					<strong>
						{ __( 'Enable URL Coupon', 'power-coupons' ) }
					</strong>
					<p>
						{ __(
							'Allow this coupon to be applied automatically when a customer visits its shareable link.',
							'power-coupons'
						) }
					</p>
				</div>
				<ToggleControl
					label={ null }
					checked={ enabled }
					onChange={ setEnabled }
					disabled={ ! featureEnabled }
					className="power-coupons-url-coupon__header__control"
					__nextHasNoMarginBottom
				/>
			</div>

			{ enabled && (
				<div className="power-coupons-url-coupon__body">
					<hr className="power-coupons-url-coupon__separator" />

					<LabeledRow
						label={ __(
							'Link expires after (days)',
							'power-coupons'
						) }
						htmlFor="power-coupons-url-coupon-expiry"
						tip={ __(
							'Number of days the link stays valid after the coupon is first enabled. 0 = never expires.',
							'power-coupons'
						) }
					>
						<input
							id="power-coupons-url-coupon-expiry"
							type="number"
							min="0"
							step="1"
							className="power-coupons-url-coupon__input"
							value={ expiryDays }
							disabled={ ! featureEnabled }
							onChange={ ( e ) =>
								setExpiryDays( onlyDigits( e.target.value ) )
							}
						/>
					</LabeledRow>

					<LabeledRow
						label={ __( 'Max uses via URL', 'power-coupons' ) }
						htmlFor="power-coupons-url-coupon-maxuses"
						tip={ __(
							'Maximum number of times the reusable link may apply this coupon. 0 = unlimited.',
							'power-coupons'
						) }
					>
						<input
							id="power-coupons-url-coupon-maxuses"
							type="number"
							min="0"
							step="1"
							className="power-coupons-url-coupon__input"
							value={ maxUses }
							disabled={ ! featureEnabled }
							onChange={ ( e ) =>
								setMaxUses( onlyDigits( e.target.value ) )
							}
						/>
					</LabeledRow>

					<LabeledRow
						label={ __(
							'Redirect / deep-link URL',
							'power-coupons'
						) }
						htmlFor="power-coupons-url-coupon-redirect"
						tip={ __(
							'Optional. Send the customer to this URL (e.g. a product page) after applying, instead of the cart/checkout. Must be on this site.',
							'power-coupons'
						) }
					>
						<input
							id="power-coupons-url-coupon-redirect"
							type="text"
							className="power-coupons-url-coupon__input"
							value={ redirect }
							disabled={ ! featureEnabled }
							onChange={ ( e ) => setRedirect( e.target.value ) }
						/>
					</LabeledRow>

					<LabeledRow
						label={ __( 'One-time-use links', 'power-coupons' ) }
						className="power-coupons-url-coupon__row--checkbox"
					>
						<CheckboxControl
							label={ __(
								'Enable generation of unique, single-use links (one per customer/campaign).',
								'power-coupons'
							) }
							checked={ oneTime }
							onChange={ setOneTime }
							disabled={ ! featureEnabled }
							__nextHasNoMarginBottom
						/>
					</LabeledRow>

					{ ! isPublished && (
						<p className="power-coupons-url-coupon__notice">
							{ __(
								'Publish the coupon to generate its shareable URL.',
								'power-coupons'
							) }
						</p>
					) }

					{ isPublished && (
						<>
							<CopyableUrl
								label={ __( 'Coupon URL', 'power-coupons' ) }
								value={ couponUrl }
								rowKey="url"
								copied={ copied }
								onCopy={ handleCopy }
								disabled={ ! featureEnabled }
								tip={ __(
									'Share this link in emails or ads. When the customer clicks it, the coupon is applied automatically (only while "Enable URL Coupon" is on).',
									'power-coupons'
								) }
							/>

							{ oneTime && (
								<div className="power-coupons-url-coupon__field">
									<label
										className="power-coupons-url-coupon__label"
										htmlFor="power-coupons-url-coupon-token"
									>
										{ __(
											'One-time link',
											'power-coupons'
										) }
										<HelpTip
											text={ __(
												'Each generated link works only once. Generate a new one per customer or campaign.',
												'power-coupons'
											) }
										/>
									</label>
									<div className="power-coupons-url-coupon__url-row">
										<input
											id="power-coupons-url-coupon-token"
											type="text"
											className="power-coupons-url-coupon__url-input"
											value={ tokenUrl }
											placeholder={ __(
												'Click generate to create a single-use link',
												'power-coupons'
											) }
											readOnly
											onFocus={ ( e ) =>
												e.target.select()
											}
										/>
										<Button
											variant="secondary"
											onClick={ () =>
												handleCopy( tokenUrl, 'token' )
											}
											disabled={
												! featureEnabled || ! tokenUrl
											}
										>
											{ copied === 'token'
												? __(
														'Copied!',
														'power-coupons'
												  )
												: __(
														'Copy',
														'power-coupons'
												  ) }
										</Button>
										<Button
											variant="primary"
											onClick={ generateToken }
											isBusy={ generating }
											disabled={
												! featureEnabled || generating
											}
										>
											{ __(
												'Generate Unique Link',
												'power-coupons'
											) }
										</Button>
									</div>
									{ tokenError && (
										<Notice
											status="error"
											isDismissible={ false }
											className="power-coupons-url-coupon__token-error"
										>
											{ tokenError }
										</Notice>
									) }
								</div>
							) }
						</>
					) }
				</div>
			) }
		</div>
	);
}

const container = document.getElementById( 'power_coupons_url_coupon_panel' );
if ( container ) {
	createRoot( container ).render( <App /> );
}
