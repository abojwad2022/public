import {
	Topbar,
	Tabs,
	Input,
	DatePicker,
	RadioButton,
	Button,
	Checkbox,
	Switch,
} from '@bsf/force-ui';
import { __ } from '@wordpress/i18n';
import { useState, useEffect, useRef } from '@wordpress/element';
import Logo from '../../../../images/logo.svg';
import { BOGOPresets, getBOGOPresetData, RenderIcon } from '../common/Utils';
import { CheckCircleIcon } from '@heroicons/react/24/solid';
import { CalendarIcon, ClockIcon } from '@heroicons/react/24/outline';
import { format } from 'date-fns';
import ProductSelector from './ProductSelector';

// ─── Date helpers ────────────────────────────────────────────────────────────

const toYMD = ( date ) => format( date, 'yyyy-MM-dd' );

// Guard against MySQL DATETIME strings ('2026-03-23 00:00:00') and invalid values.
const parseDate = ( val ) => {
	if ( ! val ) {
		return undefined;
	}
	// Slice to first 10 chars so '2026-03-23 00:00:00' → '2026-03-23'
	const d = new Date( String( val ).slice( 0, 10 ) + 'T00:00:00' );
	return isNaN( d.getTime() ) ? undefined : d;
};

const toDisplay = ( date ) => {
	if ( ! date || isNaN( date.getTime() ) ) {
		return '';
	}
	return format( date, 'MMM d, yyyy' );
};

// ─── DateField component ─────────────────────────────────────────────────────

const DateField = ( { id, label, value, placeholder, onChange, helper } ) => {
	const [ open, setOpen ] = useState( false );
	const [ dropUp, setDropUp ] = useState( false );
	const dateRef = useRef( null );

	useEffect( () => {
		if ( ! open ) {
			return;
		}
		const handler = ( e ) => {
			if ( dateRef.current && ! dateRef.current.contains( e.target ) ) {
				setOpen( false );
			}
		};
		document.addEventListener( 'mousedown', handler );
		return () => document.removeEventListener( 'mousedown', handler );
	}, [ open ] );

	const handleToggle = () => {
		if ( ! open && dateRef.current ) {
			const rect = dateRef.current.getBoundingClientRect();
			// Single-month DatePicker is ~350px tall; flip up if not enough room below.
			setDropUp( window.innerHeight - rect.bottom < 370 );
		}
		setOpen( ( v ) => ! v );
	};

	const selected = parseDate( value );

	return (
		<div className="flex flex-col gap-1.5" ref={ dateRef }>
			<label
				htmlFor={ id }
				className="text-sm font-medium text-text-primary"
			>
				{ label }
			</label>
			<div className="relative">
				<button
					id={ id }
					type="button"
					onClick={ handleToggle }
					className="w-full h-10 px-3.5 flex items-center gap-2 bg-white text-text-primary outline outline-1 outline-border-subtle border-none transition-[color,box-shadow,outline] duration-200 rounded text-sm text-left cursor-pointer hover:outline-border-strong focus:outline-focus-border focus:ring-2 focus:ring-toggle-on focus:ring-offset-2"
				>
					<CalendarIcon className="w-4 h-4 text-text-tertiary flex-shrink-0" />
					<span
						className={
							value ? 'text-text-primary' : 'text-text-tertiary'
						}
					>
						{ value
							? toDisplay( parseDate( value ) )
							: placeholder }
					</span>
				</button>
				{ open && (
					<div
						className={ `pc-date-picker-popup absolute z-50 ${
							dropUp ? 'bottom-full mb-1' : 'top-full mt-1'
						} left-0 shadow-lg rounded-md` }
					>
						<DatePicker
							selectionType="single"
							variant="normal"
							selected={ selected }
							onApply={ ( date ) => {
								onChange( toYMD( date ) );
								setOpen( false );
							} }
							onCancel={ () => {
								onChange( '' );
								setOpen( false );
							} }
							cancelButtonText={ __( 'Clear', 'power-coupons' ) }
						/>
					</div>
				) }
			</div>
			{ helper && (
				<p className="m-0 text-xs text-text-tertiary leading-snug">
					{ helper }
				</p>
			) }
		</div>
	);
};

// ─── Offer type groups ───────────────────────────────────────────────────────

const BUY_X_TYPES = [
	'buy-x-get-x-free',
	'buy-x-get-y',
	'buy-x-get-y-at-x-percent-off',
];
const SPEND_X_TYPES = [
	'spend-x-get-y-free',
	'spend-x-get-y-at-x-percent-off',
	'spend-x-get-free-shipping',
];

// Offer types where the admin must pick specific "Products to Get".
const TYPES_REQUIRING_GET_PRODUCTS = [
	'buy-x-get-y',
	'buy-x-get-y-at-x-percent-off',
	'spend-x-get-y-free',
	'spend-x-get-y-at-x-percent-off',
];

// ─── Per-tab validation helpers ───────────────────────────────────────────────

const validateTab1 = ( formData ) => {
	const errors = {};
	if ( ! ( formData.name || '' ).trim() ) {
		errors.name = __( 'Offer name is required.', 'power-coupons' );
	}
	return errors;
};

const validateTab2 = ( formData ) => {
	const errors = {};
	const {
		key,
		buy_quantity,
		get_quantity,
		spend_amount,
		discount_type,
		discount_percent,
		discount_amount,
	} = formData;

	// These fields use uncontrolled inputs (defaultValue), so when the admin has not
	// touched a field its formData entry is undefined. Use ?? to fall back to the same
	// default that the Input renders, so untouched-but-valid fields never raise errors.
	const buyQtyRaw = buy_quantity ?? '1';
	const getQtyRaw = get_quantity ?? '1';
	const spendAmtRaw = spend_amount ?? '50';
	const discPctRaw = discount_percent ?? '10';
	const discAmtRaw = discount_amount ?? '5';

	if ( BUY_X_TYPES.includes( key ) ) {
		if ( ! buyQtyRaw || parseInt( buyQtyRaw ) < 1 ) {
			errors.buy_quantity = __(
				'Buy quantity must be at least 1.',
				'power-coupons'
			);
		}
	}

	const needsGetQty =
		BUY_X_TYPES.includes( key ) ||
		( SPEND_X_TYPES.includes( key ) &&
			key !== 'spend-x-get-free-shipping' );

	if ( needsGetQty && ( ! getQtyRaw || parseInt( getQtyRaw ) < 1 ) ) {
		errors.get_quantity = __(
			'Get quantity must be at least 1.',
			'power-coupons'
		);
	}

	if ( SPEND_X_TYPES.includes( key ) ) {
		if ( ! spendAmtRaw || parseFloat( spendAmtRaw ) <= 0 ) {
			errors.spend_amount = __(
				'Minimum spend amount must be greater than 0.',
				'power-coupons'
			);
		}
	}

	if ( ( discount_type || 'free' ) === 'percentage' ) {
		const pct = parseFloat( discPctRaw );
		if ( isNaN( pct ) || pct <= 0 || pct > 100 ) {
			errors.discount_percent = __(
				'Discount percentage must be between 1 and 100.',
				'power-coupons'
			);
		}
	}

	if ( ( discount_type || 'free' ) === 'fixed' ) {
		const amt = parseFloat( discAmtRaw );
		if ( isNaN( amt ) || amt <= 0 ) {
			errors.discount_amount = __(
				'Fixed discount amount must be greater than 0.',
				'power-coupons'
			);
		}
	}

	return errors;
};

const validateTab3 = ( formData ) => {
	const errors = {};
	const { key, trigger_type, buy_product_ids, get_product_ids } = formData;

	if (
		BUY_X_TYPES.includes( key ) &&
		( trigger_type || 'any_product' ) === 'specific_products'
	) {
		if ( ! buy_product_ids || buy_product_ids.length === 0 ) {
			errors.buy_product_ids = __(
				'Please select at least one product to buy.',
				'power-coupons'
			);
		}
	}

	if ( TYPES_REQUIRING_GET_PRODUCTS.includes( key ) ) {
		if ( ! get_product_ids || get_product_ids.length === 0 ) {
			errors.get_product_ids = __(
				'Please select at least one product to get.',
				'power-coupons'
			);
		}
	}

	return errors;
};

// ─── Inline field error component ────────────────────────────────────────────

const FieldError = ( { message } ) => {
	if ( ! message ) {
		return null;
	}
	return (
		<p className="text-red-500 text-xs mt-1 flex items-center gap-1">
			<svg
				width="12"
				height="12"
				viewBox="0 0 12 12"
				fill="none"
				xmlns="http://www.w3.org/2000/svg"
				aria-hidden="true"
			>
				<path
					d="M6 1L11 10H1L6 1Z"
					stroke="currentColor"
					strokeWidth="1.2"
					strokeLinecap="round"
					strokeLinejoin="round"
				/>
				<path
					d="M6 5V7"
					stroke="currentColor"
					strokeWidth="1.2"
					strokeLinecap="round"
				/>
				<circle cx="6" cy="9" r="0.5" fill="currentColor" />
			</svg>
			{ message }
		</p>
	);
};

// ─── Tab definitions ──────────────────────────────────────────────────────────

const FormTabs = [
	{
		slug: 'basic-settings',
		title: __( 'Basic Settings', 'power-coupons' ),
		content: ( {
			formData,
			setFormData,
			presetData,
			setActiveTab,
			errors,
			setErrors,
			clearError,
		} ) => {
			const handleSaveAndContinue = () => {
				const tabErrors = validateTab1( formData );
				if ( Object.keys( tabErrors ).length > 0 ) {
					setErrors( tabErrors );
					return;
				}
				setErrors( {} );
				setActiveTab( FormTabs[ 1 ].slug );
			};

			return (
				<>
					<div>
						<strong>
							{ __( 'Offer Basics', 'power-coupons' ) }
						</strong>
						<p>
							{ __(
								'Define the core settings for your offer.',
								'power-coupons'
							) }
						</p>
					</div>

					<div className="flex flex-col gap-4">
						{ /* Offer Name — required */ }
						<div className="flex flex-col gap-1">
							<Input
								value={ formData.name ?? '' }
								id="bogo-input-offer-name"
								label={ __( 'Offer Name', 'power-coupons' ) }
								size="md"
								type="text"
								placeholder={ __(
									'E.g. Buy X Get X @ 10%',
									'power-coupons'
								) }
								onChange={ ( value ) => {
									setFormData( 'name', value );
									clearError( 'name' );
								} }
							/>
							<FieldError message={ errors.name } />
						</div>

						<div className="flex flex-col items-start gap-1.5">
							<label
								htmlFor="bogo-textarea-offer-description"
								className="text-sm font-medium"
							>
								{ __( 'Description', 'power-coupons' ) }
							</label>
							<textarea
								id="bogo-textarea-offer-description"
								className="!font-normal !text-sm h-20 bg-field-secondary-background font-normal placeholder-text-tertiary text-text-primary w-full outline outline-1 outline-border-subtle border-none transition-[color,box-shadow,outline] duration-200 p-3 py-2 rounded text-xs focus:outline-focus-border focus:ring-2 focus:ring-toggle-on focus:ring-offset-2 hover:outline-border-strong"
								placeholder={ __(
									'Eg: Buy more, save more! 1 item gets 10% off, 2 items get 20% off, and the savings grow with every item.',
									'power-coupons'
								) }
								defaultValue={
									formData.description ||
									presetData?.description ||
									''
								}
								onChange={ ( e ) =>
									setFormData( 'description', e.target.value )
								}
							/>
						</div>

						<div className="flex flex-col gap-1.5">
							{ /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
							<label className="text-sm font-medium">
								{ __( 'Activate Offer', 'power-coupons' ) }
							</label>
							<RadioButton.Group
								columns={ 2 }
								onChange={ ( value ) =>
									setFormData( 'activation_type', value )
								}
								size="md"
								defaultValue={
									formData.activation_type ||
									presetData?.activation_type ||
									'automatically'
								}
								style="simple"
							>
								<RadioButton.Button
									label={ {
										description: __(
											"Shows the offer name in the cart summary after it's applied.",
											'power-coupons'
										),
										heading: __(
											'Automatically',
											'power-coupons'
										),
									} }
									value="automatically"
									borderOn
								/>
								<RadioButton.Button
									label={ {
										description: __(
											'Apply this offer by entering the coupon code for eligible items.',
											'power-coupons'
										),
										heading: __(
											'Using Coupon Code',
											'power-coupons'
										),
									} }
									value="manually"
									borderOn
								/>
							</RadioButton.Group>
						</div>
					</div>

					<Button
						className="font-semibold text-sm px-3 py-2 w-fit ml-auto cursor-pointer no-underline text-white hover:text-white bg-wpcolor hover:bg-wphovercolor rounded-md box-content outline-0 hover:outline-0 focus:ring-0 focus-visible:ring-1 ring-0 border-none"
						size="md"
						tag="button"
						type="button"
						variant="primary"
						onClick={ handleSaveAndContinue }
					>
						{ __( 'Save & Continue', 'power-coupons' ) }
					</Button>
				</>
			);
		},
	},
	{
		slug: 'offer-conditions',
		title: __( 'Offer Conditions', 'power-coupons' ),
		content: ( {
			formData,
			setFormData,
			setActiveTab,
			errors,
			setErrors,
			clearError,
		} ) => {
			const handleSaveAndContinue = () => {
				const tabErrors = validateTab2( formData );
				if ( Object.keys( tabErrors ).length > 0 ) {
					setErrors( tabErrors );
					return;
				}
				setErrors( {} );
				setActiveTab( FormTabs[ 2 ].slug );
			};

			return (
				<>
					<div>
						<strong>
							{ __( 'Set Offer Conditions', 'power-coupons' ) }
						</strong>
						<p>
							{ __(
								'Configure when and how your BOGO offer should be applied.',
								'power-coupons'
							) }
						</p>
					</div>

					<div className="flex flex-col gap-4">
						{ /* Discount Type */ }
						<div className="flex flex-col gap-1.5">
							{ /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
							<label className="text-sm font-medium">
								{ __( 'Discount Type', 'power-coupons' ) }
							</label>
							<RadioButton.Group
								columns={ 3 }
								onChange={ ( value ) => {
									setFormData( 'discount_type', value );
									clearError( 'discount_percent' );
									clearError( 'discount_amount' );
								} }
								size="md"
								style="simple"
							>
								<RadioButton.Button
									label={ {
										heading: __( 'Free', 'power-coupons' ),
									} }
									checked={
										! formData.discount_type ||
										formData.discount_type === 'free'
									}
									value="free"
									borderOn
								/>
								<RadioButton.Button
									label={ {
										heading: __(
											'Percentage',
											'power-coupons'
										),
									} }
									checked={
										formData.discount_type === 'percentage'
									}
									value="percentage"
									borderOn
								/>
								<RadioButton.Button
									label={ {
										heading: __(
											'Fixed Amount',
											'power-coupons'
										),
									} }
									checked={
										formData.discount_type === 'fixed'
									}
									value="fixed"
									borderOn
								/>
							</RadioButton.Group>
						</div>

						{ /* buy-x-get-x-free quantities */ }
						{ formData.key === 'buy-x-get-x-free' && (
							<>
								<div className="flex flex-col gap-1">
									<Input
										label={ __(
											'Buy Quantity',
											'power-coupons'
										) }
										type="number"
										min="1"
										size="md"
										defaultValue={
											formData.buy_quantity || '1'
										}
										onChange={ ( value ) => {
											setFormData(
												'buy_quantity',
												value
											);
											clearError( 'buy_quantity' );
										} }
									/>
									<FieldError
										message={ errors.buy_quantity }
									/>
								</div>
								<div className="flex flex-col gap-1">
									<Input
										label={ __(
											'Get Quantity',
											'power-coupons'
										) }
										type="number"
										min="1"
										size="md"
										defaultValue={
											formData.get_quantity || '1'
										}
										onChange={ ( value ) => {
											setFormData(
												'get_quantity',
												value
											);
											clearError( 'get_quantity' );
										} }
									/>
									<FieldError
										message={ errors.get_quantity }
									/>
								</div>
							</>
						) }

						{ /* buy-x-get-y / buy-x-get-y-at-x-percent-off quantities */ }
						{ ( formData.key === 'buy-x-get-y' ||
							formData.key ===
								'buy-x-get-y-at-x-percent-off' ) && (
							<>
								<div className="flex flex-col gap-1">
									<Input
										label={ __(
											'Buy Quantity',
											'power-coupons'
										) }
										type="number"
										min="1"
										size="md"
										defaultValue={
											formData.buy_quantity || '1'
										}
										onChange={ ( value ) => {
											setFormData(
												'buy_quantity',
												value
											);
											clearError( 'buy_quantity' );
										} }
									/>
									<FieldError
										message={ errors.buy_quantity }
									/>
								</div>
								<div className="flex flex-col gap-1">
									<Input
										label={ __(
											'Get Quantity',
											'power-coupons'
										) }
										type="number"
										min="1"
										size="md"
										defaultValue={
											formData.get_quantity || '1'
										}
										onChange={ ( value ) => {
											setFormData(
												'get_quantity',
												value
											);
											clearError( 'get_quantity' );
										} }
										className="font-['Figtree'] font-normal not-italic text-sm leading-5 tracking-[0%]"
									/>
									<FieldError
										message={ errors.get_quantity }
									/>
								</div>
							</>
						) }

						{ /* spend-x types */ }
						{ ( formData.key === 'spend-x-get-y-free' ||
							formData.key === 'spend-x-get-y-at-x-percent-off' ||
							formData.key === 'spend-x-get-free-shipping' ) && (
							<>
								<div className="flex flex-col gap-1">
									<Input
										label={ __(
											'Minimum Spend Amount',
											'power-coupons'
										) }
										type="number"
										min="0"
										step="0.01"
										defaultValue={
											formData.spend_amount || '50'
										}
										onChange={ ( value ) => {
											setFormData(
												'spend_amount',
												value
											);
											clearError( 'spend_amount' );
										} }
									/>
									<FieldError
										message={ errors.spend_amount }
									/>
								</div>
								{ formData.key !==
									'spend-x-get-free-shipping' && (
									<div className="flex flex-col gap-1">
										<Input
											label={ __(
												'Get Quantity',
												'power-coupons'
											) }
											type="number"
											min="1"
											defaultValue={
												formData.get_quantity || '1'
											}
											onChange={ ( value ) => {
												setFormData(
													'get_quantity',
													value
												);
												clearError( 'get_quantity' );
											} }
										/>
										<FieldError
											message={ errors.get_quantity }
										/>
									</div>
								) }
							</>
						) }

						{ /* Discount percentage */ }
						{ formData.discount_type === 'percentage' && (
							<div className="flex flex-col gap-1">
								<Input
									label={ __(
										'Discount Percentage (%)',
										'power-coupons'
									) }
									type="number"
									min="0"
									max="100"
									step="0.01"
									defaultValue={
										formData.discount_percent || '10'
									}
									onChange={ ( value ) => {
										setFormData(
											'discount_percent',
											value
										);
										clearError( 'discount_percent' );
									} }
								/>
								<FieldError
									message={ errors.discount_percent }
								/>
							</div>
						) }

						{ /* Fixed discount amount */ }
						{ formData.discount_type === 'fixed' && (
							<div className="flex flex-col gap-1">
								<Input
									label={ __(
										'Fixed Discount Amount',
										'power-coupons'
									) }
									type="number"
									min="0"
									step="0.01"
									defaultValue={
										formData.discount_amount || '5'
									}
									onChange={ ( value ) => {
										setFormData( 'discount_amount', value );
										clearError( 'discount_amount' );
									} }
								/>
								<FieldError
									message={ errors.discount_amount }
								/>
							</div>
						) }

						{ /* Schedule — Start & End Dates */ }
						<div className="grid grid-cols-2 gap-4">
							<DateField
								id="bogo-input-start-date"
								label={ __( 'Start Date', 'power-coupons' ) }
								value={ formData.start_date ?? '' }
								placeholder={ __(
									'Select start date',
									'power-coupons'
								) }
								onChange={ ( value ) =>
									setFormData( 'start_date', value )
								}
								helper={ __(
									'Leave blank to start immediately.',
									'power-coupons'
								) }
							/>
							<DateField
								id="bogo-input-end-date"
								label={ __( 'End Date', 'power-coupons' ) }
								value={ formData.end_date ?? '' }
								placeholder={ __(
									'Select end date',
									'power-coupons'
								) }
								onChange={ ( value ) =>
									setFormData( 'end_date', value )
								}
								helper={ __(
									'Leave blank to never expire.',
									'power-coupons'
								) }
							/>
						</div>

						{ /* Free Shipping Option */ }
						<div className="flex items-start flex-col gap-1.5">
							<Checkbox
								label={ {
									heading: __(
										'Include free shipping',
										'power-coupons'
									),
								} }
								size="sm"
								defaultChecked={
									formData.free_shipping || false
								}
								onChange={ ( checked ) =>
									setFormData( 'free_shipping', checked )
								}
							/>
						</div>
					</div>

					<div className="flex gap-2 ml-auto">
						<Button
							className="font-semibold text-sm px-3 py-2 cursor-pointer border border-border-subtle text-text-primary hover:text-text-primary bg-transparent hover:bg-field-secondary-background rounded-md"
							variant="secondary"
							onClick={ () => {
								setErrors( {} );
								setActiveTab( FormTabs[ 0 ].slug );
							} }
						>
							{ __( 'Back', 'power-coupons' ) }
						</Button>
						<Button
							className="font-semibold text-sm px-3 py-2 w-fit ml-auto cursor-pointer no-underline text-white hover:text-white bg-wpcolor hover:bg-wphovercolor rounded-md box-content outline-0 hover:outline-0 focus:ring-0 focus-visible:ring-1 ring-0 border-none"
							variant="primary"
							onClick={ handleSaveAndContinue }
						>
							{ __( 'Save & Continue', 'power-coupons' ) }
						</Button>
					</div>
				</>
			);
		},
	},
	{
		slug: 'product-selection',
		title: __( 'Product Selection', 'power-coupons' ),
		content: ( {
			formData,
			setFormData,
			setActiveTab,
			saveOffer,
			isLoading,
			errors,
			setErrors,
			clearError,
			formError,
		} ) => {
			const isBuyXType = BUY_X_TYPES.includes( formData.key );
			const triggerType = formData.trigger_type || 'any_product';
			const showProductsToBuy =
				! isBuyXType || triggerType === 'specific_products';

			return (
				<>
					<div>
						<strong>
							{ __( 'Select Products', 'power-coupons' ) }
						</strong>
						<p>
							{ __(
								'Choose which products are eligible for this BOGO offer.',
								'power-coupons'
							) }
						</p>
					</div>

					<div className="flex flex-col gap-4">
						{ isBuyXType && (
							<div className="flex flex-col gap-1.5">
								{ /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
								<label className="text-sm font-medium">
									{ __( 'Trigger Type', 'power-coupons' ) }
								</label>
								<RadioButton.Group
									columns={ 2 }
									onChange={ ( value ) => {
										setFormData( 'trigger_type', value );
										clearError( 'buy_product_ids' );
									} }
									defaultValue={ triggerType }
									size="md"
									style="simple"
								>
									<RadioButton.Button
										label={ {
											heading: __(
												'Any Product',
												'power-coupons'
											),
											description: __(
												'Trigger when cart contains any product.',
												'power-coupons'
											),
										} }
										value="any_product"
										checked={
											triggerType === 'any_product'
										}
										borderOn
									/>
									<RadioButton.Button
										label={ {
											heading: __(
												'Specific Products',
												'power-coupons'
											),
											description: __(
												'Trigger only for selected products.',
												'power-coupons'
											),
										} }
										value="specific_products"
										checked={
											triggerType === 'specific_products'
										}
										borderOn
									/>
								</RadioButton.Group>
							</div>
						) }

						{ showProductsToBuy && (
							<div className="flex flex-col gap-1">
								<ProductSelector
									label={ __(
										'Products to Buy',
										'power-coupons'
									) }
									placeholder={ __(
										'Search for products…',
										'power-coupons'
									) }
									value={ formData.buy_product_ids || [] }
									onChange={ ( productIds ) => {
										setFormData(
											'buy_product_ids',
											productIds
										);
										clearError( 'buy_product_ids' );
									} }
								/>
								<FieldError
									message={ errors.buy_product_ids }
								/>
							</div>
						) }

						{ formData.key !== 'buy-x-get-x-free' &&
							formData.key !== 'spend-x-get-free-shipping' && (
								<div className="flex flex-col gap-1">
									<ProductSelector
										label={ __(
											'Products to Get',
											'power-coupons'
										) }
										placeholder={ __(
											'Search for products…',
											'power-coupons'
										) }
										value={ formData.get_product_ids || [] }
										onChange={ ( productIds ) => {
											setFormData(
												'get_product_ids',
												productIds
											);
											clearError( 'get_product_ids' );
										} }
									/>
									<FieldError
										message={ errors.get_product_ids }
									/>
								</div>
							) }

						<div>
							<label
								htmlFor="bogo-input-usage-limit"
								className="text-sm font-medium mb-2 block"
							>
								{ __(
									'Usage Limit (Optional)',
									'power-coupons'
								) }
							</label>
							<input
								id="bogo-input-usage-limit"
								type="number"
								min="0"
								className="w-full p-2 border border-border-subtle rounded text-sm"
								placeholder={ __(
									'Leave empty for unlimited usage',
									'power-coupons'
								) }
								onChange={ ( e ) =>
									setFormData( 'usage_limit', e.target.value )
								}
							/>
						</div>
					</div>

					{ /* Server-level error banner */ }
					{ formError && (
						<div className="bg-red-50 border border-red-200 rounded-md px-4 py-3 text-red-700 text-sm flex items-start gap-2">
							<svg
								className="shrink-0 mt-0.5"
								width="14"
								height="14"
								viewBox="0 0 12 12"
								fill="none"
								xmlns="http://www.w3.org/2000/svg"
								aria-hidden="true"
							>
								<path
									d="M6 1L11 10H1L6 1Z"
									stroke="currentColor"
									strokeWidth="1.2"
									strokeLinecap="round"
									strokeLinejoin="round"
								/>
								<path
									d="M6 5V7"
									stroke="currentColor"
									strokeWidth="1.2"
									strokeLinecap="round"
								/>
								<circle
									cx="6"
									cy="9"
									r="0.5"
									fill="currentColor"
								/>
							</svg>
							{ formError }
						</div>
					) }

					<div className="flex gap-2 ml-auto">
						<Button
							className="font-semibold text-sm px-3 py-2 cursor-pointer border border-border-subtle text-text-primary hover:text-text-primary bg-transparent hover:bg-field-secondary-background rounded-md"
							variant="secondary"
							onClick={ () => {
								setErrors( {} );
								setActiveTab( FormTabs[ 1 ].slug );
							} }
						>
							{ __( 'Back', 'power-coupons' ) }
						</Button>
						{ SPEND_X_TYPES.includes( formData.key ) ? (
							<Button
								className="font-semibold text-sm px-3 py-2 w-fit ml-auto cursor-pointer no-underline text-white hover:text-white bg-wpcolor hover:bg-wphovercolor rounded-md box-content outline-0 hover:outline-0 focus:ring-0 focus-visible:ring-1 ring-0 border-none"
								variant="primary"
								onClick={ () => {
									const tab3Errors = validateTab3( formData );
									if (
										Object.keys( tab3Errors ).length > 0
									) {
										setErrors( tab3Errors );
										return;
									}
									setErrors( {} );
									setActiveTab( FormTabs[ 3 ].slug );
								} }
							>
								{ __( 'Save & Continue', 'power-coupons' ) }
							</Button>
						) : (
							<Button
								className="font-semibold text-sm px-3 py-2 w-fit ml-auto cursor-pointer no-underline text-white hover:text-white bg-wpcolor hover:bg-wphovercolor rounded-md box-content outline-0 hover:outline-0 focus:ring-0 focus-visible:ring-1 ring-0 border-none"
								variant="primary"
								onClick={ () => {
									const tab3Errors = validateTab3( formData );
									if (
										Object.keys( tab3Errors ).length > 0
									) {
										setErrors( tab3Errors );
										return;
									}
									setErrors( {} );
									saveOffer();
								} }
								disabled={ isLoading }
							>
								{ /* eslint-disable no-nested-ternary */ }
								{ isLoading
									? formData.id
										? __( 'Updating…', 'power-coupons' )
										: __( 'Creating…', 'power-coupons' )
									: formData.id
									? __( 'Update Offer', 'power-coupons' )
									: __( 'Create Offer', 'power-coupons' ) }
								{ /* eslint-enable no-nested-ternary */ }
							</Button>
						) }
					</div>
				</>
			);
		},
	},
	{
		slug: 'progress-bar',
		title: __( 'Progress Bar', 'power-coupons' ),
		content: ( {
			formData,
			setFormData,
			setActiveTab,
			saveOffer,
			isLoading,
			formError,
		} ) => {
			const isSpendType = SPEND_X_TYPES.includes( formData.key );

			return (
				<>
					<div>
						<strong>
							{ __( 'Cart Progress Bar', 'power-coupons' ) }
						</strong>
						<p>
							{ __(
								'Configure how this offer appears in the cart progress bar. Only applies to spend-based offers.',
								'power-coupons'
							) }
						</p>
					</div>

					{ ! isSpendType && (
						<p className="text-sm text-text-tertiary bg-gray-50 border border-border-subtle rounded-md px-4 py-3">
							{ __(
								'Progress bar is only available for spend-based offers (Spend $X types). Quantity-based offers do not have a spend threshold.',
								'power-coupons'
							) }
						</p>
					) }

					{ isSpendType && (
						<div className="flex flex-col gap-4">
							<div className="flex items-center gap-3">
								<Switch
									size="sm"
									className="[&>input]:!border-none"
									defaultValue={
										formData.progress_bar_enabled || false
									}
									onChange={ ( checked ) =>
										setFormData(
											'progress_bar_enabled',
											checked
										)
									}
								/>
								<span className="text-sm font-medium">
									{ __(
										'Enable Progress Bar',
										'power-coupons'
									) }
								</span>
							</div>

							<div>
								{ /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
								<label className="block text-sm font-medium mb-1">
									{ __( 'Icon', 'power-coupons' ) }
								</label>
								<select
									value={
										formData.progress_bar_icon || 'gift'
									}
									onChange={ ( e ) =>
										setFormData(
											'progress_bar_icon',
											e.target.value
										)
									}
									className="block w-full border border-border-subtle rounded-md text-sm py-2 px-3 focus:outline-none focus:ring-2 focus:ring-orange-500"
								>
									<option value="tag">
										{ __( 'Tag', 'power-coupons' ) }
									</option>
									<option value="truck">
										{ __( 'Truck', 'power-coupons' ) }
									</option>
									<option value="gift">
										{ __( 'Gift', 'power-coupons' ) }
									</option>
									<option value="percent">
										{ __( 'Percent', 'power-coupons' ) }
									</option>
								</select>
							</div>

							<Input
								label={ __( 'Priority', 'power-coupons' ) }
								type="number"
								min="1"
								step="1"
								size="md"
								defaultValue={
									formData.progress_bar_priority || '1'
								}
								onChange={ ( value ) =>
									setFormData(
										'progress_bar_priority',
										value
									)
								}
							/>

							<div>
								<label
									htmlFor="bogo-progress-bar-message"
									className="block text-sm font-medium mb-1"
								>
									{ __(
										'Progress Message',
										'power-coupons'
									) }
								</label>
								<textarea
									id="bogo-progress-bar-message"
									className="w-full p-2 border border-border-subtle rounded text-sm"
									rows="2"
									defaultValue={
										formData.progress_bar_message ||
										__(
											'Spend {remaining} more to unlock {coupon_name}!',
											'power-coupons'
										)
									}
									onChange={ ( e ) =>
										setFormData(
											'progress_bar_message',
											e.target.value
										)
									}
								/>
								<p className="mt-1 text-xs text-text-tertiary">
									{ __(
										'Placeholders: {remaining}, {threshold}, {coupon_name}',
										'power-coupons'
									) }
								</p>
							</div>

							<div>
								<label
									htmlFor="bogo-progress-bar-success-msg"
									className="block text-sm font-medium mb-1"
								>
									{ __( 'Success Message', 'power-coupons' ) }
								</label>
								<textarea
									id="bogo-progress-bar-success-msg"
									className="w-full p-2 border border-border-subtle rounded text-sm"
									rows="2"
									defaultValue={
										formData.progress_bar_success_msg ||
										__(
											'You unlocked {coupon_name}!',
											'power-coupons'
										)
									}
									onChange={ ( e ) =>
										setFormData(
											'progress_bar_success_msg',
											e.target.value
										)
									}
								/>
							</div>
						</div>
					) }

					{ /* Server-level error banner */ }
					{ formError && (
						<div className="bg-red-50 border border-red-200 rounded-md px-4 py-3 text-red-700 text-sm flex items-start gap-2">
							<svg
								className="shrink-0 mt-0.5"
								width="14"
								height="14"
								viewBox="0 0 12 12"
								fill="none"
								xmlns="http://www.w3.org/2000/svg"
								aria-hidden="true"
							>
								<path
									d="M6 1L11 10H1L6 1Z"
									stroke="currentColor"
									strokeWidth="1.2"
									strokeLinecap="round"
									strokeLinejoin="round"
								/>
								<path
									d="M6 5V7"
									stroke="currentColor"
									strokeWidth="1.2"
									strokeLinecap="round"
								/>
								<circle
									cx="6"
									cy="9"
									r="0.5"
									fill="currentColor"
								/>
							</svg>
							{ formError }
						</div>
					) }

					<div className="flex gap-2 ml-auto">
						<Button
							className="font-semibold text-sm px-3 py-2 cursor-pointer border border-border-subtle text-text-primary hover:text-text-primary bg-transparent hover:bg-field-secondary-background rounded-md"
							variant="secondary"
							onClick={ () => setActiveTab( FormTabs[ 2 ].slug ) }
						>
							{ __( 'Back', 'power-coupons' ) }
						</Button>
						<Button
							className="font-semibold text-sm px-3 py-2 w-fit ml-auto cursor-pointer no-underline text-white hover:text-white bg-wpcolor hover:bg-wphovercolor rounded-md box-content outline-0 hover:outline-0 focus:ring-0 focus-visible:ring-1 ring-0 border-none"
							variant="primary"
							onClick={ saveOffer }
							disabled={ isLoading }
						>
							{ /* eslint-disable no-nested-ternary */ }
							{ isLoading
								? formData.id
									? __( 'Updating…', 'power-coupons' )
									: __( 'Creating…', 'power-coupons' )
								: formData.id
								? __( 'Update Offer', 'power-coupons' )
								: __( 'Create Offer', 'power-coupons' ) }
							{ /* eslint-enable no-nested-ternary */ }
						</Button>
					</div>
				</>
			);
		},
	},
];

// ─── Preset grid screen ───────────────────────────────────────────────────────

const _ModalContentOffersPresetGrid = ( { setCurrentScreen, setFormData } ) => {
	const handleClick = ( preset ) => {
		setCurrentScreen( 'form' );
		setFormData( 'key', preset.key );
		setFormData( 'name', preset.title );
	};

	return (
		<div className="flex flex-col gap-6 sm:gap-8 px-3">
			<h3 className="text-[#111827] text-xl sm:text-[24px] font-[600] m-0">
				{ __( 'Select the offer you want to create', 'power-coupons' ) }
			</h3>
			<div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-[repeat(3,292px)] gap-4">
				{ BOGOPresets.map( ( preset ) => (
					<button
						key={ preset.key }
						type="button"
						onClick={ () => handleClick( preset ) }
						className="cursor-pointer w-full h-auto min-h-[120px] lg:h-[152px] border-solid border border-[#E5E7EB] hover:outline hover:outline-solid hover:outline-[#ED4D22] bg-white rounded-lg p-3 shadow-[0px_1px_2px_0px_#0000000D]"
					>
						<h4 className="m-0 font-[600] text-[18px] leading-7 text-[#111827]">
							{ preset.title }
						</h4>
						<p className="m-0 mt-1 text-[#374151] text-[14px] leading-6">
							{ preset.description }
						</p>
					</button>
				) ) }
			</div>
			{ /* We will provide a better way to create from scratch soon */ }
			{ /* <button
				type="button"
				onClick={ () => handleClick( { key: 'scratch' } ) }
				className="cursor-pointer border-none flex items-center bg-transparent m-[0_auto] text-[#9CA3AF]"
			>
				{ __( 'Or Create from Scratch', 'power-coupons' ) }
				{ RenderIcon( 'arrowRight' ) }
			</button> */ }
		</div>
	);
};

// ─── Form screen ──────────────────────────────────────────────────────────────

const _ModalContentForm = ( {
	setCurrentScreen,
	formData,
	setFormData,
	toggleModalOpen,
} ) => {
	const [ activeTab, setActiveTabInternal ] = useState( FormTabs[ 0 ].slug );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ errors, setErrors ] = useState( {} );
	const [ formError, setFormError ] = useState( '' );

	const isSpendType = SPEND_X_TYPES.includes( formData.key );
	const visibleTabs = isSpendType
		? FormTabs
		: FormTabs.filter( ( tab ) => tab.slug !== 'progress-bar' );
	const tabIndex = visibleTabs.findIndex( ( t ) => t.slug === activeTab );
	const presetData = getBOGOPresetData( formData.key );

	/**
	 * Navigate to a tab — always clears all errors first so stale messages
	 * from a previous tab don't bleed through.
	 * @param {string} slug
	 */
	const setActiveTab = ( slug ) => {
		setErrors( {} );
		setFormError( '' );
		setActiveTabInternal( slug );
	};

	/**
	 * Remove a single field's error — called from each field's onChange handler
	 * so the error disappears the moment the admin starts correcting the value.
	 * @param {string} key
	 */
	const clearError = ( key ) => {
		setErrors( ( prev ) => {
			if ( ! prev[ key ] ) {
				return prev;
			}
			const next = { ...prev };
			delete next[ key ];
			return next;
		} );
	};

	/**
	 * Final submit — called by the last tab's "Create / Update Offer" button.
	 * POSTs to the server with all form data including progress bar fields.
	 * Server validation errors are mapped back to the relevant tab.
	 */
	const saveOffer = async () => {
		setIsLoading( true );
		setFormError( '' );
		setErrors( {} );

		try {
			const payload = new FormData();
			payload.append( 'action', 'power_coupons_save_bogo_offer' );
			payload.append(
				'_wpnonce',
				window.powerCouponsSettings?.update_nonce || ''
			);

			if ( formData.id ) {
				payload.append( 'offer_id', formData.id );
			}

			payload.append( 'name', formData.name || presetData?.title || '' );
			payload.append(
				'description',
				formData.description || presetData?.description || ''
			);
			payload.append( 'offer_type', formData.key || 'buy-x-get-x-free' );
			payload.append(
				'activation_type',
				formData.activation_type || 'automatically'
			);
			payload.append( 'buy_quantity', formData.buy_quantity ?? 1 );
			payload.append( 'get_quantity', formData.get_quantity ?? 1 );
			payload.append( 'spend_amount', formData.spend_amount ?? 50 );
			payload.append( 'discount_type', formData.discount_type || 'free' );
			payload.append(
				'discount_percent',
				formData.discount_percent ?? 10
			);
			payload.append( 'discount_amount', formData.discount_amount ?? 5 );
			payload.append( 'free_shipping', formData.free_shipping || false );
			payload.append(
				'trigger_type',
				formData.trigger_type || 'any_product'
			);

			// Schedule fields.
			payload.append( 'start_date', formData.start_date || '' );
			payload.append( 'end_date', formData.end_date || '' );

			( formData.buy_product_ids || [] ).forEach( ( id, i ) =>
				payload.append( `buy_product_ids[${ i }]`, id )
			);
			( formData.get_product_ids || [] ).forEach( ( id, i ) =>
				payload.append( `get_product_ids[${ i }]`, id )
			);

			// Progress bar fields.
			payload.append(
				'progress_bar_enabled',
				formData.progress_bar_enabled || false
			);
			payload.append(
				'progress_bar_icon',
				formData.progress_bar_icon || 'gift'
			);
			payload.append(
				'progress_bar_priority',
				formData.progress_bar_priority ?? 1
			);
			payload.append(
				'progress_bar_message',
				formData.progress_bar_message || ''
			);
			payload.append(
				'progress_bar_success_msg',
				formData.progress_bar_success_msg || ''
			);

			const response = await fetch( ajaxurl, {
				method: 'POST',
				body: payload,
			} );
			const result = await response.json();

			if ( result.success ) {
				setFormData( {} );
				toggleModalOpen( true );
			} else if (
				result.data?.type === 'validation' &&
				result.data?.errors
			) {
				// Structured server-side validation errors.
				const serverErrors = result.data.errors;
				setErrors( serverErrors );

				// Navigate back to the tab that owns the first error.
				if ( serverErrors.name ) {
					setActiveTabInternal( FormTabs[ 0 ].slug );
				} else if (
					serverErrors.buy_quantity ||
					serverErrors.get_quantity ||
					serverErrors.spend_amount ||
					serverErrors.discount_percent ||
					serverErrors.discount_amount
				) {
					setActiveTabInternal( FormTabs[ 1 ].slug );
				}
				// Product / Tab 3 errors stay on the current tab.
			} else {
				const msg =
					typeof result.data === 'string'
						? result.data
						: __(
								'Failed to save offer. Please try again.',
								'power-coupons'
						  );
				setFormError( msg );
			}
		} catch ( error ) {
			console.error( 'Error saving offer:', error );
			setFormError(
				__(
					'An error occurred while saving the offer. Please try again.',
					'power-coupons'
				)
			);
		} finally {
			setIsLoading( false );
		}
	};

	return (
		<div className="bg-white w-full max-w-[760px] rounded-md px-4 sm:px-6 py-6 sm:py-8 shadow-[0px_1px_2px_-1px_#0000001A,0px_1px_3px_0px_#0000001A]">
			{ /* Form Header */ }
			<div className="flex items-center gap-2">
				{ ! formData.id && (
					<button
						type="button"
						className="m-0 p-0 bg-transparent border-none cursor-pointer leading-[0]"
						onClick={ () => setCurrentScreen( 'offers' ) }
					>
						{ RenderIcon( 'chevronLeft' ) }
					</button>
				) }
				<h3 className="m-0 text-2xl text-[#111827]">
					{ formData.id
						? __( 'Edit Offer', 'power-coupons' ) +
						  ': ' +
						  ( formData.name || presetData?.title )
						: formData?.name ||
						  presetData?.title ||
						  __( 'Create a New Offer', 'power-coupons' ) }
				</h3>
			</div>

			{ /* Form body */ }
			<div className="mt-6">
				<Tabs activeItem={ activeTab }>
					<Tabs.Group
						iconPosition="left"
						orientation="horizontal"
						size="md"
						variant="underline"
						width="auto"
					>
						{ visibleTabs.map( ( tab, index ) => (
							<Tabs.Tab
								key={ tab.slug }
								type="button"
								icon={
									tabIndex > index ? (
										<CheckCircleIcon color="#16A34A" />
									) : (
										<ClockIcon color="#737373" />
									)
								}
								className="power-coupons-bogo-form-tab px-2.5 py-4 !cursor-default"
								slug={ tab.slug }
								text={ tab.title }
							/>
						) ) }
					</Tabs.Group>

					<div className="py-5 flex flex-col text-left gap-6">
						{ visibleTabs.map( ( tab ) => (
							<Tabs.Panel key={ tab.slug } slug={ tab.slug }>
								{
									<tab.content
										formData={ formData }
										setFormData={ setFormData }
										presetData={ presetData }
										setActiveTab={ setActiveTab }
										saveOffer={ saveOffer }
										isLoading={ isLoading }
										errors={ errors }
										setErrors={ setErrors }
										clearError={ clearError }
										formError={ formError }
									/>
								}
							</Tabs.Panel>
						) ) }
					</div>
				</Tabs>
			</div>
		</div>
	);
};

// ─── Modal root ───────────────────────────────────────────────────────────────

const ModalContent = ( { toggleModalOpen, editingOffer } ) => {
	const [ currentScreen, setCurrentScreen ] = useState(
		editingOffer ? 'form' : 'offers'
	);
	const [ formData, setFormData ] = useState(
		editingOffer
			? {
					id: editingOffer.id,
					key: editingOffer.offer_type,
					name: editingOffer.name,
					description: editingOffer.description,
					activation_type:
						editingOffer.activation_type || 'automatically',
					buy_quantity: editingOffer.buy_quantity || 1,
					get_quantity: editingOffer.get_quantity || 1,
					spend_amount: editingOffer.spend_amount || 50,
					discount_type: editingOffer.discount_type || 'free',
					discount_percent: editingOffer.discount_percent || 10,
					discount_amount: editingOffer.discount_amount || 0,
					free_shipping: editingOffer.free_shipping || false,
					buy_product_ids: editingOffer.buy_product_ids || [],
					get_product_ids: editingOffer.get_product_ids || [],
					trigger_type: editingOffer.trigger_type || 'any_product',
					start_date: editingOffer.start_date || '',
					end_date: editingOffer.end_date || '',
					progress_bar_enabled:
						editingOffer.progress_bar_enabled || false,
					progress_bar_icon: editingOffer.progress_bar_icon || 'gift',
					progress_bar_priority:
						editingOffer.progress_bar_priority || 1,
					progress_bar_message:
						editingOffer.progress_bar_message || '',
					progress_bar_success_msg:
						editingOffer.progress_bar_success_msg || '',
			  }
			: {}
	);

	const handleFormData = ( key, value ) => {
		setFormData( ( prev ) => ( { ...prev, [ key ]: value } ) );
	};

	return (
		<div className="flex items-start sm:items-center justify-center text-center px-4 sm:px-6 lg:px-0 pt-14 pb-8 min-h-full box-border">
			{ 'offers' === currentScreen ? (
				<_ModalContentOffersPresetGrid
					setCurrentScreen={ setCurrentScreen }
					formData={ formData }
					setFormData={ handleFormData }
				/>
			) : (
				<_ModalContentForm
					toggleModalOpen={ toggleModalOpen }
					setCurrentScreen={ setCurrentScreen }
					formData={ formData }
					setFormData={ handleFormData }
				/>
			) }
		</div>
	);
};

export default ( { toggleModalOpen, editingOffer } ) => {
	return (
		<div
			id="power-coupons-bogo-modal"
			className="bg-background-secondary absolute top-0 left-0 w-full h-[100vh] z-[999] bg-[#F9FAFB] overflow-y-auto"
		>
			<Topbar
				className="power_coupons-header--content !absolute h-14 min-h-[unset] p-0 border-0 border-b border-solid border-border-subtle bg-white !fixed items-center z-10 shadow-[0px_1px_2px_0px_#0000000D]"
				gap={ 0 }
				role="navigation"
				aria-label={ __( 'Main Navigation', 'power-coupons' ) }
			>
				<Topbar.Left className="power_coupons-header--content-left lg:px-5 px-3">
					<Topbar.Item>
						<img
							className="lg:block h-8 w-8"
							src={ Logo }
							alt={ __( 'Power Coupons', 'power-coupons' ) }
						/>
					</Topbar.Item>
				</Topbar.Left>
				<Topbar.Right
					className="power_coupons-header--content-right p-2 md:p-4 gap-2 md:gap-4"
					gap="md"
				>
					<Topbar.Item>
						<button
							type="button"
							onClick={ () => toggleModalOpen() }
							className="bg-transparent border-none cursor-pointer"
						>
							{ RenderIcon( 'close' ) }
						</button>
					</Topbar.Item>
				</Topbar.Right>
			</Topbar>

			<ModalContent
				toggleModalOpen={ toggleModalOpen }
				editingOffer={ editingOffer }
			/>
		</div>
	);
};
