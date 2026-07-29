import {
	Topbar,
	Tabs,
	Input,
	DatePicker,
	Switch,
	RadioButton,
} from '@bsf/force-ui';
import { __ } from '@wordpress/i18n';
import { useState, useEffect, useRef } from '@wordpress/element';
import Logo from '../../../../images/logo.svg';
import { RenderIcon } from '../common/Utils';
import { CheckCircleIcon } from '@heroicons/react/24/solid';
import { CalendarIcon, ClockIcon } from '@heroicons/react/24/outline';
import { format } from 'date-fns';

// ─── Date helpers ─────────────────────────────────────────────────────────────

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

// ─── DateField component ──────────────────────────────────────────────────────

const DateField = ( { id, label, value, placeholder, onChange, helper } ) => {
	const [ open, setOpen ] = useState( false );
	const [ dropUp, setDropUp ] = useState( false );
	const ref = useRef( null );

	useEffect( () => {
		if ( ! open ) {
			return;
		}
		const handler = ( e ) => {
			if ( ref.current && ! ref.current.contains( e.target ) ) {
				setOpen( false );
			}
		};
		document.addEventListener( 'mousedown', handler );
		return () => document.removeEventListener( 'mousedown', handler );
	}, [ open ] );

	const handleToggle = () => {
		if ( ! open && ref.current ) {
			const rect = ref.current.getBoundingClientRect();
			// Single-month DatePicker is ~350px tall; flip up if not enough room below.
			setDropUp( window.innerHeight - rect.bottom < 370 );
		}
		setOpen( ( v ) => ! v );
	};

	const selected = parseDate( value );

	return (
		<div className="flex flex-col gap-1.5" ref={ ref }>
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
			<HelperText>{ helper }</HelperText>
		</div>
	);
};

// ─── Select options ──────────────────────────────────────────────────────────

const ACTION_TYPE_OPTIONS = [
	{
		value: 'order_earn',
		label: __( 'Order Earning', 'power-coupons' ),
		description: __(
			'Award credits when a customer completes an order.',
			'power-coupons'
		),
	},
	{
		value: 'signup',
		label: __( 'Signup Bonus', 'power-coupons' ),
		description: __(
			'Award credits once when a new user registers an account.',
			'power-coupons'
		),
	},
	{
		value: 'review',
		label: __( 'Product Review', 'power-coupons' ),
		description: __(
			'Award credits when a customer leaves an approved product review.',
			'power-coupons'
		),
	},
];

const EARN_TYPE_OPTIONS = [
	{
		value: 'fixed',
		label: __( 'Fixed', 'power-coupons' ),
		description: __(
			'A fixed number of credits per order, regardless of total.',
			'power-coupons'
		),
	},
	{
		value: 'per_currency',
		label: __( 'Per Currency Unit', 'power-coupons' ),
		description: __(
			'Credits based on amount spent. E.g. 2 = 2 credits per $1.',
			'power-coupons'
		),
	},
	{
		value: 'percentage',
		label: __( 'Percentage', 'power-coupons' ),
		description: __(
			'Credits as a % of order total. E.g. 10 = 10% as credits.',
			'power-coupons'
		),
	},
];

// ─── Helper text & error display ─────────────────────────────────────────────

const FieldLabel = ( { htmlFor, text } ) => (
	<label
		htmlFor={ htmlFor }
		className="text-sm font-medium text-text-primary"
	>
		{ text }
	</label>
);

const HelperText = ( { children } ) => {
	if ( ! children ) {
		return null;
	}
	return (
		<p className="m-0 text-xs text-text-tertiary leading-snug">
			{ children }
		</p>
	);
};

const FieldError = ( { message } ) => {
	if ( ! message ) {
		return null;
	}
	return (
		<span className="text-red-500 text-xs flex items-center gap-1 mt-0.5">
			{ message }
		</span>
	);
};

// ─── Validation ──────────────────────────────────────────────────────────────

const validateStep1 = ( formData ) => {
	const errors = {};
	if ( ! ( formData.title || '' ).trim() ) {
		errors.title = __( 'Campaign name is required.', 'power-coupons' );
	}
	return errors;
};

const validateStep2 = ( formData ) => {
	const errors = {};
	if ( ! formData.earn_value || parseFloat( formData.earn_value ) <= 0 ) {
		errors.earn_value = __(
			'Earn value must be greater than 0.',
			'power-coupons'
		);
	}
	return errors;
};

// ─── Tab definitions ─────────────────────────────────────────────────────────

const FormTabs = [
	{
		slug: 'basic-settings',
		title: __( 'Basic Settings', 'power-coupons' ),
		content: ( {
			formData,
			setFormData,
			setActiveTab,
			errors,
			setErrors,
			clearError,
		} ) => {
			const handleSaveAndContinue = () => {
				const tabErrors = validateStep1( formData );
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
							{ __( 'Campaign Basics', 'power-coupons' ) }
						</strong>
						<p>
							{ __(
								'Define the name, type, and earning rules for this campaign.',
								'power-coupons'
							) }
						</p>
					</div>

					<div className="flex flex-col gap-5">
						{ /* Campaign Name */ }
						<div className="flex flex-col gap-1">
							<Input
								value={ formData.title ?? '' }
								id="campaign-input-title"
								label={ __( 'Campaign Name', 'power-coupons' ) }
								size="md"
								type="text"
								placeholder={ __(
									'E.g. Order Credits — 1 per $1',
									'power-coupons'
								) }
								onChange={ ( value ) => {
									setFormData( 'title', value );
									clearError( 'title' );
								} }
							/>
							<FieldError message={ errors.title } />
						</div>

						{ /* Description */ }
						<div className="flex flex-col items-start gap-1.5">
							<label
								htmlFor="campaign-textarea-description"
								className="text-sm font-medium"
							>
								{ __( 'Description', 'power-coupons' ) }
							</label>
							<textarea
								id="campaign-textarea-description"
								className="!font-normal !text-sm h-20 bg-field-secondary-background font-normal placeholder-text-tertiary text-text-primary w-full outline outline-1 outline-border-subtle border-none transition-[color,box-shadow,outline] duration-200 p-3 py-2 rounded text-xs focus:outline-focus-border focus:ring-2 focus:ring-toggle-on focus:ring-offset-2 hover:outline-border-strong"
								placeholder={ __(
									'Optional description for this campaign',
									'power-coupons'
								) }
								defaultValue={ formData.description || '' }
								onChange={ ( e ) =>
									setFormData( 'description', e.target.value )
								}
							/>
						</div>

						{ /* Action Type */ }
						<div className="flex flex-col gap-1.5">
							<span className="text-sm font-medium">
								{ __( 'Action Type', 'power-coupons' ) }
							</span>
							<RadioButton.Group
								columns={ 3 }
								onChange={ ( value ) =>
									setFormData( 'action_type', value )
								}
								size="md"
								defaultValue={
									formData.action_type || 'order_earn'
								}
								style="simple"
							>
								{ ACTION_TYPE_OPTIONS.map( ( opt ) => (
									<RadioButton.Button
										key={ opt.value }
										label={ {
											heading: opt.label,
											description: opt.description,
										} }
										value={ opt.value }
										borderOn
									/>
								) ) }
							</RadioButton.Group>
						</div>
					</div>

					{ /* Save & Continue button */ }
					<div className="flex justify-end mt-4">
						<button
							type="button"
							onClick={ handleSaveAndContinue }
							className="flex items-center gap-2 px-5 py-2.5 text-white bg-wpcolor hover:bg-wphovercolor rounded-md border-none cursor-pointer text-sm font-medium"
						>
							{ __( 'Save & Continue', 'power-coupons' ) }
						</button>
					</div>
				</>
			);
		},
	},
	{
		slug: 'earning-rules',
		title: __( 'Earning Rules', 'power-coupons' ),
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
			const isOrderEarn = formData.action_type === 'order_earn';

			const getEarnValueLabel = () => {
				if ( ! isOrderEarn ) {
					return __( 'Credits to Award', 'power-coupons' );
				}
				return __( 'Earn Value', 'power-coupons' );
			};

			const getEarnValueHint = () => {
				if ( ! isOrderEarn ) {
					return __(
						'Fixed number of credits awarded each time this action occurs.',
						'power-coupons'
					);
				}
				switch ( formData.earn_type ) {
					case 'per_currency':
						return __(
							'E.g. "2" means 2 credits for every $1 spent.',
							'power-coupons'
						);
					case 'percentage':
						return __(
							'E.g. "10" means 10% of the order total as credits.',
							'power-coupons'
						);
					default:
						return __(
							'A fixed number of credits awarded per order, regardless of total.',
							'power-coupons'
						);
				}
			};

			const handleCreate = () => {
				const tabErrors = validateStep2( formData );
				if ( Object.keys( tabErrors ).length > 0 ) {
					setErrors( tabErrors );
					return;
				}
				setErrors( {} );
				saveOffer();
			};

			return (
				<>
					<div>
						<strong>
							{ __( 'Earning Configuration', 'power-coupons' ) }
						</strong>
						<p>
							{ __(
								'Set the credits value, limits, and schedule for this campaign.',
								'power-coupons'
							) }
						</p>
					</div>

					<div className="flex flex-col gap-5">
						{ /* Row 1: Earn Type radio buttons (order_earn only) */ }
						{ isOrderEarn && (
							<div className="flex flex-col gap-1.5">
								<span className="text-sm font-medium">
									{ __( 'Earn Type', 'power-coupons' ) }
								</span>
								<RadioButton.Group
									columns={ 3 }
									onChange={ ( value ) =>
										setFormData( 'earn_type', value )
									}
									size="md"
									defaultValue={
										formData.earn_type || 'fixed'
									}
									style="simple"
								>
									{ EARN_TYPE_OPTIONS.map( ( opt ) => (
										<RadioButton.Button
											key={ opt.value }
											label={ {
												heading: opt.label,
												description: opt.description,
											} }
											value={ opt.value }
											borderOn
										/>
									) ) }
								</RadioButton.Group>
							</div>
						) }

						{ /* Verified Purchase Only — for review campaigns */ }
						{ 'review' === formData.action_type && (
							<div className="flex flex-col gap-1">
								<div className="flex items-center justify-between gap-3">
									<span className="text-sm font-medium text-text-primary">
										{ __(
											'Verified Purchases Only',
											'power-coupons'
										) }
									</span>
									<Switch
										size="sm"
										value={
											!! formData.verified_purchase_only
										}
										onChange={ () =>
											setFormData(
												'verified_purchase_only',
												! formData.verified_purchase_only
											)
										}
										className="[&>input]:!border-none"
									/>
								</div>
								<HelperText>
									{ __(
										'Only award credits to customers who have actually purchased the reviewed product.',
										'power-coupons'
									) }
								</HelperText>
							</div>
						) }

						{ /* Row 2: Number fields */ }
						<div
							className={ `grid gap-4 ${
								isOrderEarn ? 'grid-cols-4' : 'grid-cols-2'
							}` }
						>
							<div className="flex flex-col gap-1">
								<FieldLabel
									htmlFor="campaign-input-earn-value"
									text={ getEarnValueLabel() }
								/>
								<Input
									value={ formData.earn_value ?? '' }
									id="campaign-input-earn-value"
									size="md"
									type="number"
									placeholder="0"
									onChange={ ( value ) => {
										setFormData( 'earn_value', value );
										clearError( 'earn_value' );
									} }
								/>
								<HelperText>{ getEarnValueHint() }</HelperText>
								<FieldError message={ errors.earn_value } />
							</div>
							{ isOrderEarn && (
								<>
									<div className="flex flex-col gap-1">
										<FieldLabel
											htmlFor="campaign-input-min-order"
											text={ __(
												'Min Order Total',
												'power-coupons'
											) }
										/>
										<Input
											value={
												formData.min_order_total ?? ''
											}
											id="campaign-input-min-order"
											size="md"
											type="number"
											placeholder={ __(
												'No minimum',
												'power-coupons'
											) }
											onChange={ ( value ) =>
												setFormData(
													'min_order_total',
													value
												)
											}
										/>
										<HelperText>
											{ __(
												'Leave blank for no minimum order requirement.',
												'power-coupons'
											) }
										</HelperText>
									</div>
									<div className="flex flex-col gap-1">
										<FieldLabel
											htmlFor="campaign-input-max-points"
											text={ __(
												'Max Credits Cap',
												'power-coupons'
											) }
										/>
										<Input
											value={
												formData.max_points_cap ?? ''
											}
											id="campaign-input-max-points"
											size="md"
											type="number"
											placeholder={ __(
												'No cap',
												'power-coupons'
											) }
											onChange={ ( value ) =>
												setFormData(
													'max_points_cap',
													value
												)
											}
										/>
										<HelperText>
											{ __(
												'Leave blank for no cap on credits per order.',
												'power-coupons'
											) }
										</HelperText>
									</div>
								</>
							) }
							<div className="flex flex-col gap-1">
								<FieldLabel
									htmlFor="campaign-input-priority"
									text={ __( 'Priority', 'power-coupons' ) }
								/>
								<Input
									value={ formData.priority ?? '10' }
									id="campaign-input-priority"
									size="md"
									type="number"
									placeholder="10"
									onChange={ ( value ) =>
										setFormData( 'priority', value )
									}
								/>
								<HelperText>
									{ __(
										'Lower number = higher priority. The highest priority campaign wins when multiple match.',
										'power-coupons'
									) }
								</HelperText>
							</div>
						</div>

						{ /* Row 3: Date fields */ }
						<div className="grid grid-cols-2 gap-4">
							<DateField
								id="campaign-input-start-date"
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
								id="campaign-input-end-date"
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
					</div>

					{ formError && (
						<p className="text-red-500 text-sm mt-2">
							{ formError }
						</p>
					) }

					{ /* Action buttons */ }
					<div className="flex justify-between mt-4">
						<button
							type="button"
							onClick={ () => setActiveTab( FormTabs[ 0 ].slug ) }
							className="flex items-center gap-2 px-5 py-2.5 bg-transparent border border-solid border-border-subtle hover:bg-gray-50 rounded-md cursor-pointer text-sm font-medium text-text-primary"
						>
							{ RenderIcon( 'chevronLeft' ) }
							{ __( 'Back', 'power-coupons' ) }
						</button>
						<button
							type="button"
							onClick={ handleCreate }
							disabled={ isLoading }
							className="flex items-center gap-2 px-5 py-2.5 text-white bg-wpcolor hover:bg-wphovercolor rounded-md border-none cursor-pointer text-sm font-medium disabled:opacity-50"
						>
							{ /* eslint-disable-next-line no-nested-ternary */ }
							{ isLoading
								? __( 'Saving…', 'power-coupons' )
								: formData.id
								? __( 'Update Campaign', 'power-coupons' )
								: __( 'Create Campaign', 'power-coupons' ) }
						</button>
					</div>
				</>
			);
		},
	},
];

// ─── Form card ───────────────────────────────────────────────────────────────

const _ModalContentForm = ( { toggleModalOpen, formData, setFormData } ) => {
	const [ activeTab, setActiveTabInternal ] = useState( FormTabs[ 0 ].slug );
	const [ errors, setErrors ] = useState( {} );
	const [ formError, setFormError ] = useState( '' );
	const [ isLoading, setIsLoading ] = useState( false );

	const tabIndex = FormTabs.findIndex( ( t ) => t.slug === activeTab );

	const setActiveTab = ( slug ) => {
		setErrors( {} );
		setFormError( '' );
		setActiveTabInternal( slug );
	};

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

	const getNonce = () =>
		window.powerCouponsSettings?.points_nonces?.campaigns || '';

	const saveOffer = async () => {
		setIsLoading( true );
		setFormError( '' );
		setErrors( {} );

		try {
			const actionType = formData.action_type || 'order_earn';
			const body = {
				action: 'power_coupons_save_points_campaign',
				_wpnonce: getNonce(),
				title: formData.title || '',
				description: formData.description || '',
				action_type: actionType,
				earn_type:
					'order_earn' === actionType
						? formData.earn_type || 'fixed'
						: 'fixed',
				earn_value: formData.earn_value || '0',
				min_order_total: formData.min_order_total || '0',
				max_points: formData.max_points_cap || '0',
				priority: formData.priority || '10',
				start_date: formData.start_date || '',
				end_date: formData.end_date || '',
				status: formData.status || 'active',
				verified_purchase_only:
					'review' === actionType && formData.verified_purchase_only
						? 'true'
						: 'false',
			};

			if ( formData.id ) {
				body.id = formData.id;
			}

			const response = await fetch( ajaxurl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams( body ),
			} );
			const result = await response.json();

			if ( result.success ) {
				toggleModalOpen( true );
			} else {
				setFormError(
					typeof result.data === 'string'
						? result.data
						: __(
								'Failed to save campaign. Please try again.',
								'power-coupons'
						  )
				);
			}
		} catch ( error ) {
			console.error( 'Error saving campaign:', error );
			setFormError(
				__( 'An error occurred. Please try again.', 'power-coupons' )
			);
		} finally {
			setIsLoading( false );
		}
	};

	return (
		<div className="bg-white w-full max-w-[760px] rounded-md px-4 sm:px-6 py-6 sm:py-8 shadow-[0px_1px_2px_-1px_#0000001A,0px_1px_3px_0px_#0000001A]">
			{ /* Form Header */ }
			<div className="flex items-center gap-2">
				<h3 className="m-0 text-2xl text-[#111827]">
					{ formData.id
						? __( 'Edit Campaign', 'power-coupons' ) +
						  ': ' +
						  ( formData.title || '' )
						: __( 'Create a New Campaign', 'power-coupons' ) }
				</h3>
			</div>

			{ /* Form body with tabs */ }
			<div className="mt-6">
				<Tabs activeItem={ activeTab }>
					<Tabs.Group
						iconPosition="left"
						orientation="horizontal"
						size="md"
						variant="underline"
						width="auto"
					>
						{ FormTabs.map( ( tab, index ) => (
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
						{ FormTabs.map( ( tab ) => (
							<Tabs.Panel key={ tab.slug } slug={ tab.slug }>
								<tab.content
									formData={ formData }
									setFormData={ setFormData }
									setActiveTab={ setActiveTab }
									saveOffer={ saveOffer }
									isLoading={ isLoading }
									errors={ errors }
									setErrors={ setErrors }
									clearError={ clearError }
									formError={ formError }
								/>
							</Tabs.Panel>
						) ) }
					</div>
				</Tabs>
			</div>
		</div>
	);
};

// ─── Modal root (full-screen overlay — same as BOGO) ─────────────────────────

export default ( { toggleModalOpen, editingCampaign } ) => {
	const [ formData, setFormDataState ] = useState(
		editingCampaign
			? ( () => {
					const conds = editingCampaign.conditions
						? JSON.parse( editingCampaign.conditions )
						: {};
					return {
						id: editingCampaign.id,
						title: editingCampaign.title || '',
						description: editingCampaign.description || '',
						action_type:
							editingCampaign.action_type || 'order_earn',
						earn_type: editingCampaign.earn_type || 'fixed',
						earn_value: editingCampaign.earn_value || '',
						min_order_total: editingCampaign.min_order_total || '',
						max_points_cap: editingCampaign.max_points || '',
						priority: editingCampaign.priority || '10',
						start_date: editingCampaign.start_date || '',
						end_date: editingCampaign.end_date || '',
						status: editingCampaign.status || 'active',
						verified_purchase_only: !! conds.verified_purchase_only,
					};
			  } )()
			: {
					title: '',
					description: '',
					action_type: 'order_earn',
					earn_type: 'fixed',
					earn_value: '',
					min_order_total: '',
					max_points_cap: '',
					priority: '10',
					start_date: '',
					end_date: '',
					status: 'active',
					verified_purchase_only: false,
			  }
	);

	const handleFormData = ( key, value ) => {
		setFormDataState( ( prev ) => ( { ...prev, [ key ]: value } ) );
	};

	return (
		<div
			id="power-coupons-campaign-modal"
			className="bg-background-secondary absolute top-0 left-0 w-full h-[100vh] z-[999] bg-[#F9FAFB] overflow-y-auto"
		>
			<Topbar
				className="power_coupons-header--content !absolute h-14 min-h-[unset] p-0 border-0 border-b border-solid border-border-subtle bg-white !fixed items-center z-10 shadow-[0px_1px_2px_0px_#0000000D]"
				gap={ 0 }
				role="navigation"
				aria-label={ __( 'Campaign Navigation', 'power-coupons' ) }
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

			<div className="flex items-start sm:items-center justify-center text-center px-4 sm:px-6 lg:px-0 pt-14 pb-8 min-h-full box-border">
				<_ModalContentForm
					toggleModalOpen={ toggleModalOpen }
					formData={ formData }
					setFormData={ handleFormData }
				/>
			</div>
		</div>
	);
};
