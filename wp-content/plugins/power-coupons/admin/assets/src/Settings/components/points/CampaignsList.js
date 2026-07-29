import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useRef } from '@wordpress/element';
import { Button, Container, Switch, Table, Tooltip } from '@bsf/force-ui';
import {
	TrashIcon,
	PencilIcon,
	XMarkIcon,
	ShoppingBagIcon,
	UserPlusIcon,
	ChatBubbleBottomCenterTextIcon,
	InformationCircleIcon,
} from '@heroicons/react/24/outline';
import { RenderIcon } from '../common/Utils';
import ConfirmationModal from '../common/ConfirmationModal';
import LoyaltyStatusPill from './LoyaltyStatusPill';
import ModalCreateCampaign from './ModalCreateCampaign';

const ACTION_TYPE_LABELS = {
	order_earn: __( 'Order Earning', 'power-coupons' ),
	signup: __( 'Signup Bonus', 'power-coupons' ),
	review: __( 'Product Review', 'power-coupons' ),
};

const PROGRAM_TYPE_CARDS = [
	{
		key: 'order_earn',
		Icon: ShoppingBagIcon,
		label: __( 'Order Earning', 'power-coupons' ),
		description: __(
			'Award credits when a customer completes an order.',
			'power-coupons'
		),
		example: __( 'e.g. 2 credits per $1 spent.', 'power-coupons' ),
	},
	{
		key: 'signup',
		Icon: UserPlusIcon,
		label: __( 'Signup Bonus', 'power-coupons' ),
		description: __(
			'Award credits once when a new user registers.',
			'power-coupons'
		),
		example: __( 'e.g. 100 credits on signup.', 'power-coupons' ),
	},
	{
		key: 'review',
		Icon: ChatBubbleBottomCenterTextIcon,
		label: __( 'Product Review', 'power-coupons' ),
		description: __(
			'Award credits when a customer leaves an approved product review.',
			'power-coupons'
		),
		example: __( 'e.g. 50 credits per review.', 'power-coupons' ),
	},
];

const EARN_TYPE_LABELS = {
	fixed: __( 'Fixed', 'power-coupons' ),
	per_currency: __( 'Per Currency Unit', 'power-coupons' ),
	percentage: __( 'Percentage', 'power-coupons' ),
};

const EARN_TYPE_DESCRIPTIONS = {
	fixed: __(
		'A fixed number of credits awarded per order, regardless of cart total.',
		'power-coupons'
	),
	per_currency: __(
		'Credits based on amount spent. E.g., "100" means 100 credits earned per $1 spent.',
		'power-coupons'
	),
	percentage: __(
		'Credits as a percentage of the order total. E.g., "10" means 10 credits per $10 spent.',
		'power-coupons'
	),
};

function CampaignsList( { toast, tabSelector } ) {
	const [ openModal, setOpenModal ] = useState( false );
	const [ editingCampaign, setEditingCampaign ] = useState( null );
	const [ campaigns, setCampaigns ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ searchQuery, setSearchQuery ] = useState( '' );
	const [ searching, setSearching ] = useState( false );
	const [ selected, setSelected ] = useState( [] );

	const [ isDeleting, setIsDeleting ] = useState( false );
	const [ deleteModal, setDeleteModal ] = useState( {
		isOpen: false,
		type: null,
		id: null,
	} );

	const debounceRef = useRef( null );
	const isFirstRender = useRef( true );
	const portalRootRef = useRef(
		document.getElementById( 'power-coupons-settings' )
	);

	const getNonce = () =>
		window.powerCouponsSettings?.points_nonces?.campaigns || '';

	const toggleModalOpen = ( displayToast = false, campaignId = null ) => {
		document
			.querySelector( 'html' )
			.classList.toggle( 'power-coupon-modal-open' );

		// Compute toast message before updating state to avoid stale reference.
		const isEditing = !! editingCampaign;

		if ( campaignId ) {
			const campaignToEdit = campaigns.find(
				( c ) => c.id === campaignId
			);
			setEditingCampaign( campaignToEdit );
		} else {
			setEditingCampaign( null );
		}

		setOpenModal( ( prev ) => ! prev );

		if ( true === displayToast ) {
			const toastMessage = isEditing
				? __( 'Campaign updated successfully!', 'power-coupons' )
				: __( 'Campaign created successfully!', 'power-coupons' );
			toast.success( toastMessage, { description: '' } );
		}
		loadCampaigns( true, searchQuery );
	};

	// Initial load on mount.
	useEffect( () => {
		loadCampaigns();
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Debounced server search whenever searchQuery changes.
	useEffect( () => {
		if ( isFirstRender.current ) {
			isFirstRender.current = false;
			return;
		}

		setSearching( true );
		setSelected( [] );

		if ( debounceRef.current ) {
			clearTimeout( debounceRef.current );
		}

		debounceRef.current = setTimeout( () => {
			loadCampaigns( true, searchQuery );
		}, 400 );

		return () => {
			if ( debounceRef.current ) {
				clearTimeout( debounceRef.current );
			}
		};
	}, [ searchQuery ] ); // eslint-disable-line react-hooks/exhaustive-deps

	/**
	 * Load campaigns from the server.
	 *
	 * @param {boolean} silent When true, skip the full-table loading spinner.
	 * @param {string}  search Keyword to filter by (sent to the server).
	 */
	const loadCampaigns = async ( silent = false, search = '' ) => {
		if ( ! silent ) {
			setLoading( true );
		}

		try {
			const body = {
				action: 'power_coupons_get_points_campaigns',
				_wpnonce: getNonce(),
			};

			if ( search.trim() ) {
				body.search = search.trim();
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
				setCampaigns( result.data?.campaigns || [] );
			}
		} catch ( error ) {
			console.error( 'Error loading campaigns:', error );
		}

		setLoading( false );
		setSearching( false );
	};

	const toggleCampaignStatus = async ( campaignId, newStatus ) => {
		try {
			const response = await fetch( ajaxurl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams( {
					action: 'power_coupons_toggle_points_campaign_status',
					_wpnonce: getNonce(),
					campaign_id: campaignId,
					status: newStatus,
				} ),
			} );
			const result = await response.json();
			if ( result.success ) {
				loadCampaigns( true, searchQuery );
				toast.success(
					newStatus === 'active'
						? __(
								'Campaign enabled successfully!',
								'power-coupons'
						  )
						: __(
								'Campaign disabled successfully!',
								'power-coupons'
						  ),
					{ description: '' }
				);
			}
		} catch ( error ) {
			console.error( 'Error toggling campaign status:', error );
		}
	};

	const handleSingleDelete = async () => {
		setIsDeleting( true );
		try {
			const response = await fetch( ajaxurl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams( {
					action: 'power_coupons_delete_points_campaign',
					_wpnonce: getNonce(),
					campaign_id: deleteModal.id,
				} ),
			} );
			const result = await response.json();
			if ( result.success ) {
				loadCampaigns( true, searchQuery );
				toast.success(
					__( 'Campaign deleted successfully!', 'power-coupons' ),
					{ description: '' }
				);
			}
		} catch ( error ) {
			console.error( 'Error deleting campaign:', error );
		} finally {
			setIsDeleting( false );
			setDeleteModal( { isOpen: false, type: null, id: null } );
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
					action: 'power_coupons_bulk_delete_points_campaigns',
					_wpnonce: getNonce(),
					campaign_ids: JSON.stringify( selected ),
				} ),
			} );
			const result = await response.json();
			if ( result.success ) {
				setSelected( [] );
				loadCampaigns( true, searchQuery );
				toast.success(
					__( 'Campaigns deleted successfully!', 'power-coupons' ),
					{ description: '' }
				);
			}
		} catch ( error ) {
			console.error( 'Error bulk deleting campaigns:', error );
		} finally {
			setIsDeleting( false );
			setDeleteModal( { isOpen: false, type: null, id: null } );
		}
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
			setSelected( campaigns.map( ( item ) => item.id ) );
		} else {
			setSelected( [] );
		}
	};

	const handleConfirmDelete = () => {
		if ( deleteModal.type === 'bulk' ) {
			handleBulkDelete();
		} else {
			handleSingleDelete();
		}
	};

	const handleCancelSelect = () => {
		setSelected( [] );
	};

	const handleDeleteTrigger = () => {
		if ( ! selected.length ) {
			return;
		}
		setDeleteModal( { isOpen: true, type: 'bulk', id: null } );
	};

	const renderEarnType = ( campaign ) => {
		if ( campaign.action_type !== 'order_earn' ) {
			return __( '-', 'power-coupons' );
		}

		const label =
			EARN_TYPE_LABELS[ campaign.earn_type ] ||
			campaign.earn_type ||
			__( '-', 'power-coupons' );

		if ( EARN_TYPE_DESCRIPTIONS[ campaign.earn_type ] ) {
			return (
				<Tooltip
					content={ EARN_TYPE_DESCRIPTIONS[ campaign.earn_type ] }
					placement="top"
					tooltipPortalRoot={ portalRootRef.current }
				>
					<span className="cursor-help">{ label }</span>
				</Tooltip>
			);
		}

		return label;
	};

	// Show empty state only when there truly are no campaigns (no active search).
	if (
		! loading &&
		! searching &&
		campaigns.length === 0 &&
		! searchQuery.trim()
	) {
		return (
			<>
				{ openModal && (
					<ModalCreateCampaign
						toggleModalOpen={ toggleModalOpen }
						editingCampaign={ editingCampaign }
					/>
				) }

				<div className="bg-background-primary rounded-xl border border-border-subtle p-8 flex flex-col items-center text-center gap-5">
					<div className="self-start">{ tabSelector }</div>
					<h2 className="m-0 font-semibold text-xl">
						{ __(
							'Create Your First Credits Campaign',
							'power-coupons'
						) }
					</h2>
					<p className="m-0 text-text-secondary text-sm max-w-xl">
						{ __(
							'Reward customers with credits they can redeem for discounts. Choose from the program types below — and run as many as you like at once.',
							'power-coupons'
						) }
					</p>
					<div
						className="grid w-full max-w-3xl grid-cols-1 sm:grid-cols-3 gap-3 mt-1"
						aria-label={ __(
							'Available reward program types',
							'power-coupons'
						) }
					>
						{ PROGRAM_TYPE_CARDS.map( ( card ) => (
							<div
								key={ card.key }
								className="flex flex-col items-start gap-2 p-4 rounded-lg border border-solid border-border-subtle bg-background-secondary text-left"
							>
								<div className="inline-flex items-center justify-center h-9 w-9 rounded-md bg-orange-50 text-orange-600">
									<card.Icon className="h-5 w-5" />
								</div>
								<strong className="text-sm font-semibold text-text-primary">
									{ card.label }
								</strong>
								<p className="m-0 text-xs text-text-secondary leading-snug">
									{ card.description }
								</p>
								<p className="m-0 text-xs text-text-tertiary leading-snug">
									{ card.example }
								</p>
							</div>
						) ) }
					</div>
					<button
						type="button"
						onClick={ () => toggleModalOpen() }
						className="flex items-center gap-2 px-4 py-2 mt-2 text-white bg-orange-500 hover:bg-orange-600 rounded-md border-none cursor-pointer whitespace-nowrap"
					>
						{ RenderIcon( 'plus' ) }
						<span>
							{ __( 'Create New Campaign', 'power-coupons' ) }
						</span>
					</button>
				</div>
			</>
		);
	}

	return (
		<>
			{ openModal && (
				<ModalCreateCampaign
					toggleModalOpen={ toggleModalOpen }
					editingCampaign={ editingCampaign }
				/>
			) }

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
									onClick={ handleCancelSelect }
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
									onClick={ handleDeleteTrigger }
								>
									{ __( 'Delete', 'power-coupons' ) }
								</Button>
							</div>
						) }
					</div>
					<div className="flex items-center gap-3 sm:gap-4 w-full sm:w-auto">
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
								className="block w-full sm:w-64 pl-10 pr-3 py-2 border-none outline outline-1 outline-border-subtle rounded-md text-sm placeholder-text-tertiary focus:outline-none focus:ring-2 focus:ring-orange-500"
								placeholder={ __(
									'Search campaigns…',
									'power-coupons'
								) }
								value={ searchQuery }
								onChange={ ( e ) =>
									setSearchQuery( e.target.value )
								}
							/>
						</div>
						<button
							type="button"
							onClick={ () => toggleModalOpen() }
							className="flex items-center gap-2 px-4 py-2 text-sm text-white bg-orange-500 hover:bg-orange-600 rounded-md border-none cursor-pointer whitespace-nowrap"
						>
							{ RenderIcon( 'plus' ) }
							<span>
								{ __( 'Create New Campaign', 'power-coupons' ) }
							</span>
						</button>
					</div>
				</div>

				{ campaigns.length > 0 && (
					<div className="flex items-start gap-2 px-3 py-2 rounded-md bg-blue-50 text-blue-900 text-xs leading-snug">
						<InformationCircleIcon
							aria-hidden="true"
							className="h-4 w-4 mt-0.5 text-blue-600 flex-shrink-0"
						/>
						<p className="m-0">
							{ __(
								'You can run multiple campaigns simultaneously — Priority decides which one wins when more than one matches.',
								'power-coupons'
							) }
						</p>
					</div>
				) }

				{ /* Table */ }
				{ loading ? (
					<div className="p-6 text-center">
						<div className="inline-block animate-spin rounded-full h-6 w-6 border-b-2 border-orange-500"></div>
						<p className="mt-2 text-text-tertiary">
							{ __( 'Loading campaigns…', 'power-coupons' ) }
						</p>
					</div>
				) : (
					<div>
						<Table
							checkboxSelection={ campaigns.length > 0 }
							className="whitespace-nowrap sm:whitespace-normal"
						>
							<Table.Head
								selected={ selected.length > 0 }
								onChangeSelection={ toggleSelectAll }
								indeterminate={
									selected.length > 0 &&
									selected.length < campaigns.length
								}
							>
								<Table.HeadCell>
									{ __( 'Title', 'power-coupons' ) }
								</Table.HeadCell>
								<Table.HeadCell>
									{ __( 'Action Type', 'power-coupons' ) }
								</Table.HeadCell>
								<Table.HeadCell>
									{ __( 'Earn Type', 'power-coupons' ) }
								</Table.HeadCell>
								<Table.HeadCell>
									{ __( 'Earn Value', 'power-coupons' ) }
								</Table.HeadCell>
								<Table.HeadCell>
									{ __( 'Priority', 'power-coupons' ) }
								</Table.HeadCell>
								<Table.HeadCell>
									{ __( 'Status', 'power-coupons' ) }
								</Table.HeadCell>
								<Table.HeadCell>
									<Container
										align="center"
										className="gap-2"
										justify="end"
									>
										{ __( 'Actions', 'power-coupons' ) }
									</Container>
								</Table.HeadCell>
							</Table.Head>
							<Table.Body>
								{ campaigns.length === 0 ? (
									<Table.Row>
										<Table.Cell
											colSpan={ 7 }
											className="w-full text-center text-text-tertiary py-8"
										>
											{ __(
												'No campaigns found matching your search.',
												'power-coupons'
											) }
										</Table.Cell>
									</Table.Row>
								) : (
									campaigns.map( ( campaign ) => (
										<Table.Row
											key={ campaign.id }
											value={ campaign }
											selected={ selected.includes(
												campaign.id
											) }
											onChangeSelection={
												handleCheckboxChange
											}
										>
											<Table.Cell className="text-text-secondary text-sm font-normal">
												<button
													type="button"
													className="bg-transparent border-none p-0 m-0 cursor-pointer text-text-secondary hover:text-orange-600 hover:underline text-sm font-normal text-left"
													onClick={ () =>
														toggleModalOpen(
															false,
															campaign.id
														)
													}
												>
													{ campaign.title }
												</button>
											</Table.Cell>
											<Table.Cell className="text-text-secondary text-sm font-normal">
												{ ACTION_TYPE_LABELS[
													campaign.action_type
												] || campaign.action_type }
											</Table.Cell>
											<Table.Cell className="text-text-secondary text-sm font-normal">
												{ renderEarnType( campaign ) }
											</Table.Cell>
											<Table.Cell className="text-text-secondary text-sm font-normal">
												{ campaign.earn_value }
											</Table.Cell>
											<Table.Cell className="text-text-secondary text-sm font-normal">
												{ campaign.priority }
											</Table.Cell>
											<Table.Cell>
												<Switch
													aria-label="Switch Element"
													className="[&>input]:!border-none"
													defaultValue={
														campaign.status ===
														'active'
													}
													onChange={ ( checked ) =>
														toggleCampaignStatus(
															campaign.id,
															checked
																? 'active'
																: 'inactive'
														)
													}
													size="sm"
												/>
											</Table.Cell>
											<Table.Cell>
												<Container
													align="center"
													className="gap-2"
													justify="end"
												>
													<Tooltip
														content="Edit"
														arrow
														placement="top"
														tooltipPortalRoot={
															portalRootRef.current
														}
													>
														<Button
															onClick={ () =>
																toggleModalOpen(
																	false,
																	campaign.id
																)
															}
															variant="ghost"
															icon={
																<PencilIcon />
															}
															size="xs"
															className="text-icon-secondary hover:text-icon-primary"
															aria-label="Edit"
														/>
													</Tooltip>
													<Tooltip
														content="Delete"
														arrow
														placement="top"
														tooltipPortalRoot={
															portalRootRef.current
														}
													>
														<Button
															onClick={ () =>
																setDeleteModal(
																	{
																		isOpen: true,
																		type: 'single',
																		id: campaign.id,
																	}
																)
															}
															variant="ghost"
															icon={
																<TrashIcon />
															}
															size="xs"
															className="text-icon-secondary hover:text-icon-primary"
															aria-label="Delete"
														/>
													</Tooltip>
												</Container>
											</Table.Cell>
										</Table.Row>
									) )
								) }
							</Table.Body>
						</Table>
					</div>
				) }
			</div>

			<ConfirmationModal
				isOpen={ deleteModal.isOpen }
				onClose={ () =>
					setDeleteModal( {
						isOpen: false,
						type: null,
						id: null,
					} )
				}
				onConfirm={ handleConfirmDelete }
				title={
					deleteModal.type === 'bulk'
						? __( 'Delete Selected Campaigns', 'power-coupons' )
						: __( 'Delete Campaign', 'power-coupons' )
				}
				message={
					deleteModal.type === 'bulk'
						? sprintf(
								/* translators: %s: number of selected campaigns */
								__(
									'Are you sure you want to delete %s selected campaign(s)? This action cannot be undone.',
									'power-coupons'
								),
								selected.length
						  )
						: __(
								'Are you sure you want to delete this campaign? This action cannot be undone.',
								'power-coupons'
						  )
				}
				isLoading={ isDeleting }
			/>
		</>
	);
}

export default CampaignsList;
