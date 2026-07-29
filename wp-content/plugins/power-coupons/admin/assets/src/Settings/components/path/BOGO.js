import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useRef } from '@wordpress/element';
import bogoEnvelop from '../../../../images/bogo-envelop.svg';
import { createExcerpt, getBOGOPresetData, RenderIcon } from '../common/Utils';
import ModalCreateOffers from '../bogo/ModalCreateOffers';
import { Button, Container, Switch, Table, Tooltip } from '@bsf/force-ui';
import {
	TrashIcon,
	PencilIcon,
	XMarkIcon,
	EyeIcon,
	DocumentDuplicateIcon,
} from '@heroicons/react/24/outline';
import ConfirmationModal from '../common/ConfirmationModal';
import ModalPreviewOffer from '../bogo/ModalPreviewOffer';

const features = [
	__(
		'Choose whether the reward is free, percentage-discounted, or fixed-price discounted.',
		'power-coupons'
	),
	__(
		'Offer the same product or different products as the free or discounted reward.',
		'power-coupons'
	),
	__(
		'Fully compatible with WooCommerce coupons, cart, and checkout experience.',
		'power-coupons'
	),
];

function BOGO( { toast } ) {
	const [ openModal, setOpenModal ] = useState( false );
	const [ editingOffer, setEditingOffer ] = useState( null );
	const [ offers, setOffers ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ searchQuery, setSearchQuery ] = useState( '' );
	const [ searching, setSearching ] = useState( false );

	const [ previewOffer, setPreviewOffer ] = useState( null );

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

	const toggleModalOpen = ( displayToast, offerId = null ) => {
		document
			.querySelector( 'html' )
			.classList.toggle( 'power-coupon-modal-open' );

		// If offerId is provided, load the offer data for editing
		if ( offerId ) {
			const offerToEdit = offers.find(
				( offer ) => offer.id === offerId
			);
			setEditingOffer( offerToEdit );
		} else {
			setEditingOffer( null );
		}

		setOpenModal( ! openModal );

		if ( true === displayToast ) {
			toast.success(
				editingOffer
					? __( 'BOGO offer updated successfully!', 'power-coupons' )
					: __( 'BOGO offer created successfully!', 'power-coupons' ),
				{
					description: '',
				}
			);
		}
		loadOffers( true, searchQuery );
	};

	// Initial load on mount.
	useEffect( () => {
		loadOffers();
	}, [] );

	// Debounced server search whenever searchQuery changes.
	useEffect( () => {
		// Skip the very first render — initial load is handled by the effect above.
		if ( isFirstRender.current ) {
			isFirstRender.current = false;
			return;
		}

		// Signal that a search is in-progress immediately so the spinner shows.
		setSearching( true );
		setSelected( [] );

		// Clear any pending debounce timer.
		if ( debounceRef.current ) {
			clearTimeout( debounceRef.current );
		}

		debounceRef.current = setTimeout( () => {
			loadOffers( true, searchQuery );
		}, 400 );

		return () => {
			if ( debounceRef.current ) {
				clearTimeout( debounceRef.current );
			}
		};
	}, [ searchQuery ] ); // eslint-disable-line react-hooks/exhaustive-deps

	/**
	 * Load BOGO offers from the server.
	 *
	 * @param {boolean} silent When true, skip the full-table loading spinner.
	 * @param {string}  search Keyword to filter by (sent to the server).
	 */
	const loadOffers = async ( silent = false, search = '' ) => {
		if ( ! silent ) {
			setLoading( true );
		}

		try {
			const body = {
				action: 'power_coupons_get_bogo_offers',
				_wpnonce: window.powerCouponsSettings?.update_nonce || '',
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
				setOffers( result.data || [] );
			}
		} catch ( error ) {
			console.error( 'Error loading offers:', error );
		}

		setLoading( false );
		setSearching( false );
	};

	const toggleOfferStatus = async ( offerId, newStatus ) => {
		try {
			const response = await fetch( ajaxurl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams( {
					action: 'power_coupons_toggle_bogo_status',
					_wpnonce: window.powerCouponsSettings?.update_nonce || '',
					offer_id: offerId,
					status: newStatus,
				} ),
			} );
			const result = await response.json();
			if ( result.success ) {
				loadOffers( true, searchQuery ); // Reload with current search.
				toast.success(
					newStatus === 'active'
						? __(
								'BOGO offer enabled successfully!',
								'power-coupons'
						  )
						: __(
								'BOGO offer disabled successfully!',
								'power-coupons'
						  ),
					{ description: '' }
				);
			}
		} catch ( error ) {
			console.error( 'Error toggling status:', error );
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
					action: 'power_coupons_delete_bogo_offer',
					_wpnonce: window.powerCouponsSettings?.update_nonce || '',
					offer_id: deleteModal.id,
				} ),
			} );
			const result = await response.json();
			if ( result.success ) {
				loadOffers( true, searchQuery );
				toast.success(
					__( 'BOGO offer deleted successfully!', 'power-coupons' ),
					{ description: '' }
				);
			}
		} catch ( error ) {
			console.error( 'Error deleting offer:', error );
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
					action: 'power_coupons_bulk_delete_bogo_offers',
					_wpnonce: window.powerCouponsSettings?.update_nonce || '',
					offer_ids: JSON.stringify( selected ),
				} ),
			} );
			const result = await response.json();
			if ( result.success ) {
				setSelected( [] );
				loadOffers( true, searchQuery );
				toast.success(
					__( 'BOGO offers deleted successfully!', 'power-coupons' ),
					{ description: '' }
				);
			}
		} catch ( error ) {
			console.error( 'Error bulk deleting offers:', error );
		} finally {
			setIsDeleting( false );
			setDeleteModal( { isOpen: false, type: null, id: null } );
		}
	};

	const [ cloningId, setCloningId ] = useState( null );

	const handleCloneOffer = async ( offerId ) => {
		setCloningId( offerId );
		try {
			const response = await fetch( ajaxurl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams( {
					action: 'power_coupons_clone_bogo_offer',
					_wpnonce: window.powerCouponsSettings?.update_nonce || '',
					offer_id: offerId,
				} ),
			} );
			const result = await response.json();
			if ( result.success ) {
				loadOffers( true, searchQuery );
				toast.success(
					__( 'BOGO offer cloned successfully!', 'power-coupons' ),
					{ description: '' }
				);
			} else {
				toast.error(
					__(
						'Failed to clone offer. Please try again.',
						'power-coupons'
					),
					{ description: '' }
				);
			}
		} catch ( error ) {
			console.error( 'Error cloning offer:', error );
			toast.error(
				__(
					'Failed to clone offer. Please try again.',
					'power-coupons'
				),
				{ description: '' }
			);
		} finally {
			setCloningId( null );
		}
	};

	const [ selected, setSelected ] = useState( [] );

	const handleCheckboxChange = ( checked, value ) => {
		if ( checked ) {
			setSelected( [ ...selected, value.id ] );
		} else {
			setSelected( selected.filter( ( item ) => item !== value.id ) );
		}
	};

	const toggleSelectAll = ( checked ) => {
		if ( checked ) {
			setSelected( offers.map( ( item ) => item.id ) );
		} else {
			setSelected( [] );
		}
	};

	// Show empty state only when there truly are no offers (no active search).
	if (
		! loading &&
		! searching &&
		offers.length === 0 &&
		! searchQuery.trim()
	) {
		return (
			<>
				{ openModal && (
					<ModalCreateOffers
						toggleModalOpen={ toggleModalOpen }
						editingOffer={ editingOffer }
					/>
				) }

				<div className="h-auto px-6 bg-background-primary rounded-[6px] shadow-sm flex justify-between gap-[121px]">
					{ /* Section left */ }
					<div className="py-[32px]">
						<h2 className="m-0 font-semibold text-xl">
							{ __(
								"Let's Setup Your First BOGO Offer",
								'power-coupons'
							) }
						</h2>

						<p>
							{ __(
								'Boost your sales and increase average order value by creating powerful Buy One Get One offers in your store. Reward customers automatically and drive conversions with flexible, fully customizable BOGO promotions.',
								'power-coupons'
							) }
						</p>

						<ul className="divide-y divide-gray-200 list-none pl-0 space-y-2">
							{ features.map( ( feature, index ) => (
								<li
									key={ feature + index }
									className="flex items-center space-x-2 text-field-label text-sm font-normal"
								>
									{ RenderIcon( 'check' ) }
									<span className="text-[#111827]">
										{ feature }{ ' ' }
									</span>
								</li>
							) ) }
						</ul>

						<button
							type="button"
							onClick={ toggleModalOpen }
							className="flex items-center gap-[8px] p-[10px] mt-[24px] cursor-pointer no-underline text-white hover:text-white bg-wpcolor hover:bg-wphovercolor rounded-md box-content outline-0 hover:outline-0 focus:ring-0 focus-visible:ring-1 ring-0 border-none"
						>
							{ RenderIcon( 'plus' ) }
							<span>
								{ __( 'Create New Offer', 'power-coupons' ) }
							</span>
						</button>
					</div>

					{ /* Section Right. */ }
					<img src={ bogoEnvelop } alt="" />
				</div>
			</>
		);
	}

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

	// Handle delete trigger.
	const handleDeleteTrigger = () => {
		if ( ! selected.length ) {
			return;
		}
		setDeleteModal( { isOpen: true, type: 'bulk', id: null } );
	};

	// Show offers table
	return (
		<>
			{ openModal && (
				<ModalCreateOffers
					toggleModalOpen={ toggleModalOpen }
					editingOffer={ editingOffer }
				/>
			) }

			<div className="bg-background-primary rounded-xl border border-border-subtle p-4 flex flex-col gap-4">
				{ /* Header */ }
				<div className="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3">
					<div className="flex items-center gap-4">
						<h2 className="m-0 text-xl font-semibold text-text-primary">
							{ __( 'BOGO Offers', 'power-coupons' ) }
						</h2>

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
							{ /* Search icon or in-progress spinner */ }
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
								className="block w-full sm:w-64 pl-10 pr-3 py-2 border border-border-subtle rounded-md text-sm placeholder-text-tertiary focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500"
								placeholder={ __(
									'Search offers…',
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
							onClick={ toggleModalOpen }
							className="flex items-center gap-2 px-4 py-2 text-white bg-orange-500 hover:bg-orange-600 rounded-md border-none cursor-pointer whitespace-nowrap"
						>
							{ RenderIcon( 'plus' ) }
							<span>
								{ __( 'Create New Offer', 'power-coupons' ) }
							</span>
						</button>
					</div>
				</div>

				{ /* Table */ }
				{ loading ? (
					<div className="p-6 text-center">
						<div className="inline-block animate-spin rounded-full h-6 w-6 border-b-2 border-orange-500"></div>
						<p className="mt-2 text-text-tertiary">
							{ __( 'Loading offers…', 'power-coupons' ) }
						</p>
					</div>
				) : (
					<div>
						<Table
							checkboxSelection={ offers.length > 0 }
							className="whitespace-nowrap sm:whitespace-normal"
						>
							<Table.Head
								selected={ selected.length > 0 }
								onChangeSelection={ toggleSelectAll }
								indeterminate={
									selected.length > 0 &&
									selected.length < offers.length
								}
							>
								<Table.HeadCell>
									{ __( 'Offer Name', 'power-coupons' ) }
								</Table.HeadCell>
								<Table.HeadCell>
									{ __( 'Description', 'power-coupons' ) }
								</Table.HeadCell>
								<Table.HeadCell>
									{ __( 'Offer Type', 'power-coupons' ) }
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
								{ offers.length === 0 ? (
									<Table.Row>
										<Table.Cell
											colSpan={ 5 }
											className="w-full text-center text-text-tertiary py-8"
										>
											{ __(
												'No offers found matching your search.',
												'power-coupons'
											) }
										</Table.Cell>
									</Table.Row>
								) : (
									offers.map( ( offer ) => (
										<Table.Row
											key={ offer.id }
											value={ offer }
											selected={ selected.includes(
												offer.id
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
															offer.id
														)
													}
												>
													{ createExcerpt(
														offer.name
													) }
												</button>
											</Table.Cell>
											<Table.Cell className="text-text-secondary text-sm font-normal">
												{ createExcerpt(
													offer.description
												) }
											</Table.Cell>
											<Table.Cell className="text-text-secondary text-sm font-normal">
												{ getBOGOPresetData(
													offer.offer_type
												)?.title ||
													__(
														'Custom',
														'power-coupons'
													) }
											</Table.Cell>
											<Table.Cell>
												<Switch
													aria-label="Switch Element"
													className="[&>input]:!border-none"
													defaultValue={
														offer.status ===
														'active'
													}
													onChange={ ( checked ) =>
														toggleOfferStatus(
															offer.id,
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
														content="Preview"
														arrow
														placement="top"
														tooltipPortalRoot={
															portalRootRef.current
														}
													>
														<Button
															onClick={ () =>
																setPreviewOffer(
																	offer
																)
															}
															variant="ghost"
															icon={ <EyeIcon /> }
															size="xs"
															className="text-icon-secondary hover:text-icon-primary"
															aria-label="Preview"
														/>
													</Tooltip>
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
																	offer.id
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
														content="Clone"
														arrow
														placement="top"
														tooltipPortalRoot={
															portalRootRef.current
														}
													>
														<Button
															onClick={ () =>
																handleCloneOffer(
																	offer.id
																)
															}
															variant="ghost"
															icon={
																<DocumentDuplicateIcon />
															}
															size="xs"
															className="text-icon-secondary hover:text-icon-primary"
															aria-label="Clone"
															disabled={
																cloningId ===
																offer.id
															}
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
																		id: offer.id,
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

			{ previewOffer && (
				<ModalPreviewOffer
					offer={ previewOffer }
					onClose={ () => setPreviewOffer( null ) }
				/>
			) }

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
					/* eslint-disable no-mixed-spaces-and-tabs, indent, @wordpress/i18n-no-variables, @wordpress/i18n-no-collapsible-whitespace, @wordpress/i18n-translator-comments */
					deleteModal.type === 'bulk'
						? __( 'Delete Selected Offers', 'power-coupons' )
						: __( 'Delete Offer', 'power-coupons' )
				}
				message={
					deleteModal.type === 'bulk'
						? sprintf(
								/* translators: %s: number of selected offers */
								__(
									'Are you sure you want to delete %s selected offer(s)? This action cannot be undone.',
									'power-coupons'
								),
								selected.length
						  )
						: __(
								'Are you sure you want to delete this offer? This action cannot be undone.',
								'power-coupons'
						  )
				}
				isLoading={ isDeleting }
			/>
		</>
	);
}

export default BOGO;
