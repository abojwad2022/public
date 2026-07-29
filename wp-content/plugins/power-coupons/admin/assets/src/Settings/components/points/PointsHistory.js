import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useRef } from '@wordpress/element';
import {
	Badge,
	Button,
	DatePicker,
	Input,
	Label,
	SearchBox,
	Table,
	TextArea,
	Tooltip,
} from '@bsf/force-ui';
import {
	AdjustmentsHorizontalIcon,
	CalendarIcon,
	ChevronLeftIcon,
	ChevronRightIcon,
	TrashIcon,
	XMarkIcon,
} from '@heroicons/react/24/outline';
import { format } from 'date-fns';
import { RenderIcon } from '../common/Utils';
import ConfirmationModal from '../common/ConfirmationModal';
import LoyaltyStatusPill from './LoyaltyStatusPill';

const ACTION_LABELS = {
	order_earn: __( 'Order Earning', 'power-coupons' ),
	order_complete: __( 'Order Earning', 'power-coupons' ),
	order_pending: __( 'Order Earning (Pending)', 'power-coupons' ),
	redeem: __( 'Redemption', 'power-coupons' ),
	admin_adjust: __( 'Admin Adjustment', 'power-coupons' ),
	signup: __( 'Signup Bonus', 'power-coupons' ),
	review: __( 'Product Review', 'power-coupons' ),
	expiry: __( 'Expired', 'power-coupons' ),
	cancel_reversal: __( 'Cancelled/Reversed', 'power-coupons' ),
	order_refund: __( 'Refund', 'power-coupons' ),
};

const ACTION_FILTER_OPTIONS = [
	{ value: '', label: __( 'All Actions', 'power-coupons' ) },
	{ value: 'order_earn', label: __( 'Order Earning', 'power-coupons' ) },
	{
		value: 'order_pending',
		label: __( 'Order Earning (Pending)', 'power-coupons' ),
	},
	{ value: 'redeem', label: __( 'Redemption', 'power-coupons' ) },
	{
		value: 'admin_adjust',
		label: __( 'Admin Adjustment', 'power-coupons' ),
	},
	{ value: 'signup', label: __( 'Signup Bonus', 'power-coupons' ) },
	{ value: 'review', label: __( 'Product Review', 'power-coupons' ) },
	{ value: 'expiry', label: __( 'Expired', 'power-coupons' ) },
	{
		value: 'cancel_reversal',
		label: __( 'Cancelled/Reversed', 'power-coupons' ),
	},
	{ value: 'order_refund', label: __( 'Refund', 'power-coupons' ) },
];

const decodeEntities = ( text ) => {
	if ( ! text || typeof text !== 'string' ) {
		return text;
	}
	const el = document.createElement( 'textarea' );
	el.innerHTML = text;
	return el.value;
};

const PER_PAGE = 20;

function PointsHistory( { toast, tabSelector } ) {
	const [ entries, setEntries ] = useState( [] );
	const [ totalEntries, setTotalEntries ] = useState( 0 );
	const [ loading, setLoading ] = useState( true );
	const [ page, setPage ] = useState( 1 );

	// Filters.
	const [ searchQuery, setSearchQuery ] = useState( '' );
	const [ actionFilter, setActionFilter ] = useState( '' );
	const [ dateFrom, setDateFrom ] = useState( '' );
	const [ dateTo, setDateTo ] = useState( '' );
	const [ datePickerOpen, setDatePickerOpen ] = useState( false );
	const [ datePickerDropUp, setDatePickerDropUp ] = useState( false );
	const datePickerRef = useRef( null );

	const [ searching, setSearching ] = useState( false );

	// Manual adjust dialog.
	const [ adjustOpen, setAdjustOpen ] = useState( false );
	const [ adjustData, setAdjustData ] = useState( {
		user_id: '',
		points: '',
		note: '',
	} );
	const [ adjustSaving, setAdjustSaving ] = useState( false );
	const [ adjustErrors, setAdjustErrors ] = useState( {} );

	// User search for manual adjust.
	const [ userSearchTerm, setUserSearchTerm ] = useState( '' );
	const [ userSearchResults, setUserSearchResults ] = useState( [] );
	const [ userSearchLoading, setUserSearchLoading ] = useState( false );
	const [ selectedUser, setSelectedUser ] = useState( null );
	const [ userSearchOpen, setUserSearchOpen ] = useState( false );
	const userSearchDebounceRef = useRef( null );

	// Bulk delete.
	const [ selected, setSelected ] = useState( [] );
	const [ isDeleting, setIsDeleting ] = useState( false );
	const [ deleteModal, setDeleteModal ] = useState( false );

	const debounceRef = useRef( null );
	const isFirstRender = useRef( true );

	// Close date picker on outside click.
	useEffect( () => {
		if ( ! datePickerOpen ) {
			return;
		}
		const handleOutside = ( e ) => {
			if (
				datePickerRef.current &&
				! datePickerRef.current.contains( e.target )
			) {
				setDatePickerOpen( false );
			}
		};
		document.addEventListener( 'mousedown', handleOutside );
		return () => document.removeEventListener( 'mousedown', handleOutside );
	}, [ datePickerOpen ] );

	const getNonce = () =>
		window.powerCouponsSettings?.points_nonces?.history || '';

	// Initial load on mount.
	useEffect( () => {
		loadHistory();
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Reload when page changes (but not on first render which is handled above).
	useEffect( () => {
		if ( isFirstRender.current ) {
			return;
		}
		loadHistory( true );
	}, [ page ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Reload when action filter or date range changes.
	useEffect( () => {
		if ( isFirstRender.current ) {
			return;
		}
		setPage( 1 );
		loadHistory( true );
	}, [ actionFilter, dateFrom, dateTo ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Debounced search.
	useEffect( () => {
		if ( isFirstRender.current ) {
			isFirstRender.current = false;
			return;
		}

		setSearching( true );

		if ( debounceRef.current ) {
			clearTimeout( debounceRef.current );
		}

		debounceRef.current = setTimeout( () => {
			setPage( 1 );
			loadHistory( true );
		}, 400 );

		return () => {
			if ( debounceRef.current ) {
				clearTimeout( debounceRef.current );
			}
		};
	}, [ searchQuery ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Debounced user search for manual adjust modal.
	useEffect( () => {
		if ( userSearchDebounceRef.current ) {
			clearTimeout( userSearchDebounceRef.current );
		}

		if ( userSearchTerm.length >= 2 ) {
			setUserSearchLoading( true );
			userSearchDebounceRef.current = setTimeout( () => {
				searchUsers( userSearchTerm );
				setUserSearchOpen( true );
			}, 400 );
		} else {
			setUserSearchResults( [] );
			setUserSearchOpen( false );
		}

		return () => {
			if ( userSearchDebounceRef.current ) {
				clearTimeout( userSearchDebounceRef.current );
			}
		};
	}, [ userSearchTerm ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const searchUsers = async ( term ) => {
		setUserSearchLoading( true );
		try {
			const formData = new FormData();
			formData.append( 'action', 'power_coupons_search_users' );
			formData.append( '_wpnonce', getNonce() );
			formData.append( 'term', term );

			const response = await fetch( ajaxurl, {
				method: 'POST',
				body: formData,
			} );
			const result = await response.json();

			if ( result.success ) {
				setUserSearchResults( result.data );
			} else {
				setUserSearchResults( [] );
			}
		} catch ( error ) {
			console.error( 'Error searching users:', error );
			setUserSearchResults( [] );
		}
		setUserSearchLoading( false );
	};

	const loadHistory = async ( silent = false ) => {
		if ( ! silent ) {
			setLoading( true );
		}

		try {
			const body = {
				action: 'power_coupons_get_points_history',
				_wpnonce: getNonce(),
				page,
				per_page: PER_PAGE,
			};

			if ( searchQuery.trim() ) {
				body.search = searchQuery.trim();
			}
			if ( actionFilter ) {
				body.action_filter = actionFilter;
			}
			if ( dateFrom ) {
				body.date_from = dateFrom;
			}
			if ( dateTo ) {
				body.date_to = dateTo;
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
				setEntries( result.data?.entries || [] );
				setTotalEntries( result.data?.total || 0 );
			}
		} catch ( error ) {
			console.error( 'Error loading points history:', error );
		}

		setLoading( false );
		setSearching( false );
	};

	const totalPages = Math.ceil( totalEntries / PER_PAGE );

	const handleAdjustOpen = () => {
		setAdjustData( { user_id: '', points: '', note: '' } );
		setAdjustErrors( {} );
		setSelectedUser( null );
		setUserSearchTerm( '' );
		setUserSearchResults( [] );
		setUserSearchOpen( false );
		setAdjustOpen( true );
	};

	const handleAdjustSave = async () => {
		const newErrors = {};
		if ( ! selectedUser ) {
			newErrors.user_id = __( 'Please select a user.', 'power-coupons' );
		}
		if ( ! adjustData.points || parseInt( adjustData.points ) === 0 ) {
			newErrors.points = __(
				'Credits must be a non-zero value.',
				'power-coupons'
			);
		}
		setAdjustErrors( newErrors );
		if ( Object.keys( newErrors ).length > 0 ) {
			return;
		}

		setAdjustSaving( true );

		try {
			const response = await fetch( ajaxurl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams( {
					action: 'power_coupons_admin_adjust_points',
					_wpnonce: getNonce(),
					user_id: selectedUser.id,
					points: adjustData.points,
					note: adjustData.note,
				} ),
			} );
			const result = await response.json();
			if ( result.success ) {
				setAdjustOpen( false );
				toast.success(
					__( 'Credits adjusted successfully!', 'power-coupons' ),
					{ description: '' }
				);
				loadHistory( true );
			} else {
				toast.error(
					result.data?.message ||
						__( 'Failed to adjust credits.', 'power-coupons' ),
					{ description: '' }
				);
			}
		} catch ( error ) {
			console.error( 'Error adjusting points:', error );
		} finally {
			setAdjustSaving( false );
		}
	};

	/**
	 * Format points with color: green for positive, red for negative.
	 *
	 * @param {number|string} points The points value.
	 * @return {JSX.Element} Colored points display.
	 */
	const formatPoints = ( points ) => {
		const num = parseInt( points );
		if ( num > 0 ) {
			return <span className="text-green-600 font-medium">+{ num }</span>;
		} else if ( num < 0 ) {
			return <span className="text-red-600 font-medium">{ num }</span>;
		}
		return <span className="text-text-secondary">{ num }</span>;
	};

	const handleCheckboxChange = ( checked, value ) => {
		if ( checked ) {
			setSelected( [ ...selected, value.id ] );
		} else {
			setSelected( selected.filter( ( item ) => item !== value.id ) );
		}
	};

	const toggleSelectAll = ( checked ) => {
		if ( checked ) {
			setSelected( entries.map( ( item ) => item.id ) );
		} else {
			setSelected( [] );
		}
	};

	const handleBulkDelete = async () => {
		setIsDeleting( true );
		try {
			const response = await fetch( ajaxurl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams( {
					action: 'power_coupons_bulk_delete_points_history',
					_wpnonce: getNonce(),
					entry_ids: JSON.stringify( selected ),
				} ),
			} );
			const result = await response.json();
			if ( result.success ) {
				setSelected( [] );
				loadHistory( true );
				toast.success(
					result.data?.message ||
						__( 'Entries deleted successfully!', 'power-coupons' ),
					{ description: '' }
				);
			}
		} catch ( error ) {
			console.error( 'Error bulk deleting entries:', error );
		} finally {
			setIsDeleting( false );
			setDeleteModal( false );
		}
	};

	const toYMD = ( date ) => format( date, 'yyyy-MM-dd' );
	const toDisplay = ( date ) => format( date, 'MMM d, yyyy' );

	const getDateRangeLabel = () => {
		if ( ! dateFrom && ! dateTo ) {
			return __( 'All Dates', 'power-coupons' );
		}
		const fromLabel = dateFrom
			? format( new Date( dateFrom + 'T00:00:00' ), 'MMM d' )
			: '';
		const toLabel = dateTo
			? toDisplay( new Date( dateTo + 'T00:00:00' ) )
			: '';
		if ( fromLabel && toLabel ) {
			return `${ fromLabel } – ${ toLabel }`;
		}
		return fromLabel || toLabel;
	};

	return (
		<>
			<div className="bg-background-primary rounded-xl border border-border-subtle p-4 flex flex-col gap-4">
				<div className="flex items-center gap-2 flex-wrap">
					<h2 className="m-0 text-xl font-semibold text-text-primary">
						{ __( 'Loyalty Rewards', 'power-coupons' ) }
					</h2>
					<LoyaltyStatusPill />
				</div>
				{ /* Header */ }
				<div className="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3">
					<div className="flex items-center gap-4">
						{ tabSelector }

						{ ! loading && selected.length > 0 && (
							<div className="flex gap-4 items-center border-0 border-l border-solid border-gray-200">
								<Button
									variant="ghost"
									icon={
										<XMarkIcon className="h-6 w-6 text-gray-500" />
									}
									size="xs"
									className="text-icon-secondary hover:text-icon-primary"
									onClick={ () => setSelected( [] ) }
								/>
								<span className="text-sm font-normal text-gray-500">
									{ selected.length }{ ' ' }
									{ __( 'Selected', 'power-coupons' ) }
								</span>
								<Button
									className="py-2 px-4 bg-red-50 text-red-600 outline-red-600 hover:bg-red-50 hover:outline-red-600"
									size="sm"
									tag="button"
									type="button"
									variant="outline"
									icon={ <TrashIcon className="h-4 w-4" /> }
									iconPosition="left"
									onClick={ () => setDeleteModal( true ) }
								>
									{ __( 'Delete', 'power-coupons' ) }
								</Button>
							</div>
						) }
					</div>
					<div className="flex items-center gap-3 flex-wrap">
						{ /* Search */ }
						<div className="relative flex-1 sm:flex-none">
							<div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
								{ searching ? (
									<svg
										className="h-4 w-4 animate-spin"
										viewBox="0 0 100 100"
									>
										<circle
											fill="none"
											strokeWidth="10"
											className="stroke-current opacity-40"
											cx="50"
											cy="50"
											r="40"
										></circle>
										<circle
											fill="none"
											strokeWidth="10"
											className="stroke-current"
											strokeDasharray="250"
											strokeDashoffset="210"
											cx="50"
											cy="50"
											r="40"
										></circle>
									</svg>
								) : (
									RenderIcon( 'search' )
								) }
							</div>
							<input
								type="text"
								className="block w-full sm:w-56 pl-10 pr-3 py-2 border-none outline outline-1 outline-border-subtle rounded-md text-sm placeholder-text-tertiary focus:outline-none focus:ring-2 focus:ring-orange-500"
								placeholder={ __(
									'Search user…',
									'power-coupons'
								) }
								value={ searchQuery }
								onChange={ ( e ) =>
									setSearchQuery( e.target.value )
								}
							/>
						</div>

						{ /* Action Filter */ }
						<div className="w-full sm:w-48">
							<select
								className="bg-field-secondary-background text-text-primary !w-full !max-w-none outline outline-1 outline-border-subtle border-none transition-[color,box-shadow,outline] duration-200 px-3 py-2 rounded text-sm focus:outline-focus-border focus:ring-2 focus:ring-toggle-on focus:ring-offset-2 hover:outline-border-strong"
								value={ actionFilter }
								onChange={ ( e ) =>
									setActionFilter( e.target.value )
								}
							>
								{ ACTION_FILTER_OPTIONS.map( ( opt ) => (
									<option
										key={ opt.value }
										value={ opt.value }
									>
										{ opt.label }
									</option>
								) ) }
							</select>
						</div>

						{ /* Date Range */ }
						<div className="relative" ref={ datePickerRef }>
							<button
								type="button"
								onClick={ () => {
									if (
										! datePickerOpen &&
										datePickerRef.current
									) {
										const rect =
											datePickerRef.current.getBoundingClientRect();
										// Presets + dual-calendar is ~430px tall; flip up if not enough room.
										setDatePickerDropUp(
											window.innerHeight - rect.bottom <
												450
										);
									}
									setDatePickerOpen( ( v ) => ! v );
								} }
								className="flex items-center gap-2 bg-field-secondary-background text-text-primary outline outline-1 outline-border-subtle border-none transition-[color,box-shadow,outline] duration-200 px-3 py-2 rounded text-sm cursor-pointer hover:outline-border-strong focus:outline-focus-border focus:ring-2 focus:ring-toggle-on focus:ring-offset-2"
							>
								<CalendarIcon className="w-4 h-4 text-text-tertiary flex-shrink-0" />
								<span
									className={
										dateFrom || dateTo
											? 'text-text-primary'
											: 'text-text-tertiary'
									}
								>
									{ getDateRangeLabel() }
								</span>
							</button>
							{ ( dateFrom || dateTo ) && (
								<button
									type="button"
									onClick={ () => {
										setDateFrom( '' );
										setDateTo( '' );
									} }
									className="absolute right-2 top-1/2 -translate-y-1/2 bg-transparent border-none cursor-pointer p-0.5 text-text-tertiary hover:text-text-primary"
									aria-label={ __(
										'Clear dates',
										'power-coupons'
									) }
								>
									<XMarkIcon className="w-3.5 h-3.5" />
								</button>
							) }
							{ datePickerOpen && (
								<div
									className={ `pc-date-picker-popup absolute z-50 ${
										datePickerDropUp
											? 'bottom-full mb-1'
											: 'top-full mt-1'
									} right-0 shadow-lg rounded-md` }
								>
									<DatePicker
										selectionType="range"
										variant="presets"
										selected={
											dateFrom && dateTo
												? {
														from: new Date(
															dateFrom +
																'T00:00:00'
														),
														to: new Date(
															dateTo + 'T00:00:00'
														),
												  }
												: {
														from: undefined,
														to: undefined,
												  }
										}
										onApply={ ( range ) => {
											if ( range?.from ) {
												setDateFrom(
													toYMD( range.from )
												);
											}
											if ( range?.to ) {
												setDateTo( toYMD( range.to ) );
											}
											setDatePickerOpen( false );
										} }
										onCancel={ () => {
											setDateFrom( '' );
											setDateTo( '' );
											setDatePickerOpen( false );
										} }
									/>
								</div>
							) }
						</div>
						<Tooltip
							content={ __(
								'Manually add or deduct credits for a specific user',
								'power-coupons'
							) }
							placement="left"
							tooltipPortalRoot={ document.getElementById(
								'power-coupons-settings'
							) }
						>
							<button
								type="button"
								onClick={ handleAdjustOpen }
								className="flex items-center gap-2 px-4 py-2 text-sm text-white bg-orange-500 hover:bg-orange-600 rounded-md border-none cursor-pointer whitespace-nowrap"
							>
								<AdjustmentsHorizontalIcon className="h-4 w-4" />
								<span>
									{ __( 'Manual Adjust', 'power-coupons' ) }
								</span>
							</button>
						</Tooltip>
					</div>
				</div>

				{ /* Table */ }
				{ loading ? (
					<div className="p-6 text-center">
						<div className="inline-block animate-spin rounded-full h-6 w-6 border-b-2 border-orange-500"></div>
						<p className="mt-2 text-text-tertiary">
							{ __( 'Loading history…', 'power-coupons' ) }
						</p>
					</div>
				) : (
					<div>
						<Table
							checkboxSelection={ entries.length > 0 }
							className="whitespace-nowrap sm:whitespace-normal"
						>
							<Table.Head
								selected={ selected.length > 0 }
								onChangeSelection={ toggleSelectAll }
								indeterminate={
									selected.length > 0 &&
									selected.length < entries.length
								}
							>
								<Table.HeadCell>
									{ __( 'Date', 'power-coupons' ) }
								</Table.HeadCell>
								<Table.HeadCell>
									{ __( 'User', 'power-coupons' ) }
								</Table.HeadCell>
								<Table.HeadCell>
									{ __( 'Action', 'power-coupons' ) }
								</Table.HeadCell>
								<Table.HeadCell>
									{ __( 'Credits', 'power-coupons' ) }
								</Table.HeadCell>
								<Table.HeadCell>
									{ __( 'Balance After', 'power-coupons' ) }
								</Table.HeadCell>
								<Table.HeadCell>
									{ __( 'Note', 'power-coupons' ) }
								</Table.HeadCell>
							</Table.Head>
							<Table.Body>
								{ entries.length === 0 ? (
									<Table.Row>
										<Table.Cell
											colSpan={ 6 }
											className="w-full text-center text-text-tertiary py-8"
										>
											{ __(
												'No history entries found.',
												'power-coupons'
											) }
										</Table.Cell>
									</Table.Row>
								) : (
									entries.map( ( entry ) => (
										<Table.Row
											key={ entry.id }
											value={ entry }
											selected={ selected.includes(
												entry.id
											) }
											onChangeSelection={
												handleCheckboxChange
											}
										>
											<Table.Cell className="text-text-secondary text-sm font-normal">
												{ entry.date }
											</Table.Cell>
											<Table.Cell className="text-text-secondary text-sm font-normal">
												<div className="flex flex-col">
													<span>
														{ entry.user_name }
													</span>
													{ entry.user_email && (
														<span className="text-xs text-text-tertiary">
															{ entry.user_email }
														</span>
													) }
												</div>
											</Table.Cell>
											<Table.Cell className="text-text-secondary text-sm font-normal">
												{ ACTION_LABELS[
													entry.action
												] || entry.action }
											</Table.Cell>
											<Table.Cell className="text-sm">
												{ formatPoints( entry.points ) }
											</Table.Cell>
											<Table.Cell className="text-text-secondary text-sm font-normal">
												{ entry.balance_after }
											</Table.Cell>
											<Table.Cell className="text-text-secondary text-sm font-normal max-w-[200px]">
												<div className="flex flex-col gap-0.5">
													{ entry.note && (
														<span className="truncate">
															{ decodeEntities(
																entry.note
															) }
														</span>
													) }
													{ entry.reference && (
														<a
															href={
																entry.reference
																	.url
															}
															target="_blank"
															rel="noopener noreferrer"
															className="text-text-secondary hover:text-orange-600 hover:underline truncate"
														>
															{
																entry.reference
																	.label
															}
														</a>
													) }
													{ ! entry.note &&
														! entry.reference &&
														__(
															'-',
															'power-coupons'
														) }
												</div>
											</Table.Cell>
										</Table.Row>
									) )
								) }
							</Table.Body>
						</Table>

						{ /* Pagination */ }
						{ totalPages > 1 && (
							<div className="flex items-center justify-between mt-4 px-2">
								<span className="text-sm text-text-tertiary">
									{ sprintf(
										/* translators: 1: current range start, 2: current range end, 3: total entries */
										__(
											'Showing %1$s-%2$s of %3$s',
											'power-coupons'
										),
										( page - 1 ) * PER_PAGE + 1,
										Math.min(
											page * PER_PAGE,
											totalEntries
										),
										totalEntries
									) }
								</span>
								<div className="flex items-center gap-2">
									<Button
										variant="outline"
										size="xs"
										onClick={ () => setPage( page - 1 ) }
										disabled={ page <= 1 }
										icon={
											<ChevronLeftIcon className="h-4 w-4" />
										}
										aria-label={ __(
											'Previous page',
											'power-coupons'
										) }
									/>
									<span className="text-sm text-text-secondary px-2">
										{ sprintf(
											/* translators: 1: current page, 2: total pages */
											__(
												'%1$s / %2$s',
												'power-coupons'
											),
											page,
											totalPages
										) }
									</span>
									<Button
										variant="outline"
										size="xs"
										onClick={ () => setPage( page + 1 ) }
										disabled={ page >= totalPages }
										icon={
											<ChevronRightIcon className="h-4 w-4" />
										}
										aria-label={ __(
											'Next page',
											'power-coupons'
										) }
									/>
								</div>
							</div>
						) }
					</div>
				) }
			</div>

			{ /* Manual Adjust Dialog */ }
			{ adjustOpen && (
				<div className="fixed inset-0 z-[9999] flex items-center justify-center">
					<div
						className="absolute inset-0 bg-black/50"
						onClick={ () => setAdjustOpen( false ) }
						role="presentation"
					/>
					<div className="relative bg-white rounded-lg shadow-xl w-full max-w-md mx-4 z-10">
						{ /* Header */ }
						<div className="flex items-center justify-between p-5 border-0 border-b border-solid border-border-subtle">
							<h3 className="m-0 text-lg font-semibold text-text-primary">
								{ __(
									'Manual Credits Adjustment',
									'power-coupons'
								) }
							</h3>
							<button
								type="button"
								onClick={ () => setAdjustOpen( false ) }
								className="bg-transparent border-none cursor-pointer p-1 text-text-tertiary hover:text-text-primary"
							>
								{ RenderIcon( 'close' ) }
							</button>
						</div>

						{ /* Body */ }
						<div
							id="power-coupons-points-adjust-modal"
							className="p-5 flex flex-col gap-4"
						>
							<div className="flex flex-col gap-1.5">
								<Label required>
									{ __( 'User', 'power-coupons' ) }
								</Label>
								{ selectedUser ? (
									<Badge
										closable
										onClose={ () => {
											setSelectedUser( null );
											setAdjustData( ( prev ) => ( {
												...prev,
												user_id: '',
											} ) );
											setUserSearchTerm( '' );
											setUserSearchResults( [] );
										} }
										label={ `${ selectedUser.name } (${ selectedUser.email })` }
										size="md"
										type="pill"
										variant="neutral"
									/>
								) : (
									<SearchBox
										variant="secondary"
										closeAfterSelect={ false }
										loading={ userSearchLoading }
										setOpen={ setUserSearchOpen }
										open={ userSearchOpen }
										size="md"
									>
										<SearchBox.Input
											className="!w-full !box-border [&_span]:hidden"
											placeholder={ __(
												'Search by name or email…',
												'power-coupons'
											) }
											value={ userSearchTerm }
											onChange={ setUserSearchTerm }
										/>
										<SearchBox.Portal id="power-coupons-points-adjust-modal">
											<SearchBox.Content>
												<SearchBox.List>
													{ userSearchResults.length >
													0 ? (
														userSearchResults.map(
															( user ) => (
																<SearchBox.Item
																	className="cursor-pointer"
																	key={
																		user.id
																	}
																	onClick={ () => {
																		setSelectedUser(
																			user
																		);
																		setAdjustData(
																			(
																				prev
																			) => ( {
																				...prev,
																				user_id:
																					user.id,
																			} )
																		);
																		setUserSearchTerm(
																			''
																		);
																		setUserSearchOpen(
																			false
																		);
																		setUserSearchResults(
																			[]
																		);
																	} }
																>
																	<div className="flex flex-col">
																		<span className="font-medium">
																			{
																				user.name
																			}
																		</span>
																		<span className="text-xs text-text-tertiary">
																			{
																				user.email
																			}
																		</span>
																	</div>
																</SearchBox.Item>
															)
														)
													) : (
														<SearchBox.Empty>
															{ /* eslint-disable no-nested-ternary */ }
															{ userSearchTerm.length <
															2
																? __(
																		'Type at least 2 characters to search',
																		'power-coupons'
																  )
																: userSearchLoading
																? __(
																		'Searching…',
																		'power-coupons'
																  )
																: __(
																		'No users found',
																		'power-coupons'
																  ) }
															{ /* eslint-enable no-nested-ternary */ }
														</SearchBox.Empty>
													) }
												</SearchBox.List>
											</SearchBox.Content>
										</SearchBox.Portal>
									</SearchBox>
								) }
								{ adjustErrors.user_id && (
									<span className="text-red-500 text-xs">
										{ adjustErrors.user_id }
									</span>
								) }
							</div>
							<div className="flex flex-col gap-1.5">
								<Label required>
									{ __( 'Credits', 'power-coupons' ) }
								</Label>
								<Input
									type="number"
									size="md"
									value={ adjustData.points }
									onChange={ ( value ) =>
										setAdjustData( ( prev ) => ( {
											...prev,
											points: value,
										} ) )
									}
									placeholder={ __(
										'e.g. 50 or -25',
										'power-coupons'
									) }
									step="1"
									error={ !! adjustErrors.points }
								/>
								{ adjustErrors.points && (
									<span className="text-red-500 text-xs">
										{ adjustErrors.points }
									</span>
								) }
								<span className="text-xs text-text-tertiary">
									{ __(
										'Use positive values to add credits, negative to deduct.',
										'power-coupons'
									) }
								</span>
							</div>
							<div className="flex flex-col gap-1.5">
								<Label>{ __( 'Note', 'power-coupons' ) }</Label>
								<TextArea
									size="md"
									value={ adjustData.note }
									onChange={ ( value ) =>
										setAdjustData( ( prev ) => ( {
											...prev,
											note: value,
										} ) )
									}
									placeholder={ __(
										'Optional note for this adjustment',
										'power-coupons'
									) }
									rows={ 2 }
								/>
							</div>
						</div>

						{ /* Footer */ }
						<div className="flex gap-3 justify-end p-5 border-0 border-t border-solid border-border-subtle">
							<button
								type="button"
								onClick={ () => setAdjustOpen( false ) }
								disabled={ adjustSaving }
								className="px-4 py-2 bg-transparent border border-solid border-border-subtle hover:bg-gray-50 rounded-md cursor-pointer text-sm font-medium text-text-primary disabled:opacity-50"
							>
								{ __( 'Cancel', 'power-coupons' ) }
							</button>
							<button
								type="button"
								onClick={ handleAdjustSave }
								disabled={ adjustSaving }
								className="px-4 py-2 text-white bg-orange-500 hover:bg-orange-600 rounded-md border-none cursor-pointer text-sm font-medium disabled:opacity-50"
							>
								{ adjustSaving
									? __( 'Saving…', 'power-coupons' )
									: __( 'Adjust Credits', 'power-coupons' ) }
							</button>
						</div>
					</div>
				</div>
			) }

			<ConfirmationModal
				isOpen={ deleteModal }
				onClose={ () => setDeleteModal( false ) }
				onConfirm={ handleBulkDelete }
				title={ __( 'Delete Selected Entries', 'power-coupons' ) }
				message={ sprintf(
					/* translators: %s: number of selected entries */
					__(
						'Are you sure you want to delete %s selected history entry(s)? This action cannot be undone.',
						'power-coupons'
					),
					selected.length
				) }
				isLoading={ isDeleting }
			/>
		</>
	);
}

export default PointsHistory;
